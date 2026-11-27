<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Cache;
use app\Core\Crypto;
use app\Core\Database;
use app\Core\Mailer;
use app\Core\RateLimiter;
use app\Core\Session;

/**
 * 会员自助中心（v2.3.0）
 *
 * 会话隔离：会员态存 $_SESSION['member_user']（与后台 admin_user 完全独立），
 * 登录绑定 UA 指纹（member_ua）。手机号是唯一身份键：
 *  - 我的预约 = bookings.phone 匹配
 *  - 我的测评 = assessment_results.member_id 匹配（登录后提交自动关联）
 * 隐私：手机号展示一律掩码；登录 IP 只存哈希；提供本人数据导出与删除申请。
 */
class MemberService
{
    /** 登录方式 A：手机号 + 预约查询码（零外部依赖；预约记录即可建号） */
    public static function loginByCode(string $phone, string $code): array
    {
        $phone = trim($phone);
        $code = strtoupper(trim($code));
        if (!self::validPhone($phone) || $code === '') return [false, '请输入正确的手机号与预约查询码'];
        if (!RateLimiter::hit('member_code_login_phone', $phone, 10, 1800)
            || !RateLimiter::hit('member_login', $phone . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 8, 3600)) {
            return [false, '尝试过于频繁，请 30 分钟后再试'];
        }
        $b = Database::fetch('SELECT id FROM bookings WHERE code=? AND phone=?', [$code, $phone]);
        if (!$b) return [false, '未找到对应预约记录，请核对查询码与手机号'];
        return self::establish($phone);
    }

    /** 登录方式 B：手机号 + 短信验证码（依赖短信通知插件的阿里云配置） */
    public static function loginBySms(string $phone, string $code): array
    {
        $phone = trim($phone);
        $code = trim($code);
        if (!self::validPhone($phone) || !preg_match('/^\d{6}$/', $code)) return [false, '请输入正确的手机号与 6 位验证码'];
        if (!RateLimiter::hit('member_sms_verify', $phone, 10, 1800)
            || !RateLimiter::hit('member_login', $phone . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 8, 3600)) {
            return [false, '尝试过于频繁，请稍后再试'];
        }
        $key = 'mcode:' . hash('sha256', $phone);
        if (!Cache::consumeIf($key, static function ($expect) use ($code): bool {
            return is_string($expect) && hash_equals($expect, $code);
        })) return [false, '验证码不正确或已过期'];
        return self::establish($phone);
    }

    /** 发送登录验证码（短信通知插件提供通道；60s 冷却 + 限流） */
    public static function sendCode(string $phone): array
    {
        $phone = trim($phone);
        if (!self::validPhone($phone)) return [false, '手机号格式不正确'];
        if (!self::smsReady()) return [false, '短信验证码通道未配置完整，请联系机构'];
        if (!RateLimiter::hit('member_code', $phone, 1, 60)) return [false, '发送过于频繁，请 60 秒后再试'];
        if (!RateLimiter::hit('member_code_ip', ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 3600)) {
            return [false, '本机发送次数已达上限，请稍后再试'];
        }
        if (!class_exists(\plugins\smsnotify\Plugin::class)) return [false, '短信通道未安装'];
        $code = (string)random_int(100000, 999999);
        [$ok, $msg] = \plugins\smsnotify\Plugin::sendCode($phone, $code);
        if (!$ok) return [false, $msg];
        Cache::set('mcode:' . hash('sha256', $phone), $code, 300); // 5 分钟有效
        return [true, '验证码已发送，5 分钟内有效'];
    }

    /** 短信通道是否就绪（登录页决定展示哪种方式） */
    public static function smsReady(): bool
    {
        return (bool)setting('sms.code_enabled', '0')
            && trim((string)setting('sms.code_template_code', '')) !== ''
            && trim((string)setting('sms.access_key_id', '')) !== ''
            && trim((string)Crypto::decrypt((string)setting('sms.access_secret', ''))) !== ''
            && trim((string)setting('sms.sign_name', '')) !== '';
    }

    /** 建立会员会话（存在即登录；新手机号自动注册）。v2.7.0 起公开：微信登录插件复用同一会话路径 */
    public static function establish(string $phone): array
    {
        $m = Database::fetch('SELECT * FROM members WHERE phone=?', [$phone]);
        $now = date('Y-m-d H:i:s');
        $ipHash = md5(($_SERVER['REMOTE_ADDR'] ?? '') . (CONFIG['stats_salt'] ?? 'psyisle-salt'));
        $created = false;
        if ($m) {
            if (($m['status'] ?? '') !== 'active') {
                return [false, '该账号当前不可登录'];
            }
            Database::run('UPDATE members SET last_login_at=?, last_login_ip_hash=? WHERE id=?', [$now, $ipHash, (int)$m['id']]);
        } else {
            Database::insert('members', [
                'phone' => $phone, 'status' => 'active',
                'created_at' => $now, 'last_login_at' => $now, 'last_login_ip_hash' => $ipHash,
            ]);
            $m = Database::fetch('SELECT * FROM members WHERE phone=?', [$phone]);
            $created = true;
        }
        if (!self::bindMemberSession($m)) return [false, '会话创建失败，请重试'];
        if ($created) {
            NotifyService::push('member', '新会员注册', '手机号 ' . self::maskPhone($phone) . ' 已加入会员中心');
        }
        return [true, '登录成功'];
    }

    /**
     * 绑定会员会话（v2.21.0 安全加固）
     * 先轮换 session id 再写会话，防会话固定（攻击者预置 sessid 后诱导登录）。
     * 预约码 / 短信 / 密码 / 微信四条登录入口统一走这里。
     */
    private static function bindMemberSession(array $m): bool
    {
        Session::start();
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) return false;
        Session::set('member_user', [
            'id' => (int)$m['id'],
            'phone' => (string)$m['phone'],
            'name' => $m['name'] ?? null,
        ]);
        Session::set('member_ua', hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
        return true;
    }

    /**
     * v2.7.0：以既有会员行直接建立会话（微信登录插件等外部通道复用）
     * 会员行必须已存在且 active；统一更新登录时间/IP 指纹与 UA 绑定，与手机号登录完全同路径。
     */
    public static function establishByMember(array $m): array
    {
        if ((int)$m['id'] <= 0) return [false, '会员不存在'];
        if (($m['status'] ?? '') !== 'active') return [false, '会员状态不可登录'];
        $now = date('Y-m-d H:i:s');
        $ipHash = md5(($_SERVER['REMOTE_ADDR'] ?? '') . (CONFIG['stats_salt'] ?? 'psyisle-salt'));
        Database::run('UPDATE members SET last_login_at=?, last_login_ip_hash=? WHERE id=?', [$now, $ipHash, (int)$m['id']]);
        if (!self::bindMemberSession($m)) return [false, '会话创建失败，请重试'];
        return [true, '登录成功'];
    }

    /** 登录方式 C：手机号 + 密码（v2.9.0，零外部依赖的注册会员体系） */
    public static function loginByPassword(string $phone, string $password): array
    {
        $phone = trim($phone);
        if (!self::validPhone($phone) || $password === '') return [false, '请输入手机号与密码'];
        if (!RateLimiter::hit('member_pwd_login_phone', $phone, 10, 1800)
            || !RateLimiter::hit('member_pwd_login', $phone . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 8, 3600)) {
            return [false, '尝试过于频繁，请 30 分钟后再试'];
        }
        $m = Database::fetch('SELECT * FROM members WHERE phone=?', [$phone]);
        if (!$m || empty($m['password_hash'])) {
            return [false, '该手机号未设置密码，可先用预约查询码登录，或在设置中开启密码'];
        }
        if (($m['status'] ?? '') !== 'active') return [false, '该账号当前不可登录'];
        if (!password_verify($password, (string)$m['password_hash'])) {
            return [false, '手机号或密码不正确'];
        }
        // 密码重Hash（算法升级时自动迁移）
        if (password_needs_rehash((string)$m['password_hash'], PASSWORD_BCRYPT)) {
            Database::run('UPDATE members SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_BCRYPT), (int)$m['id']]);
        }
        $ipHash = md5(($_SERVER['REMOTE_ADDR'] ?? '') . (CONFIG['stats_salt'] ?? 'psyisle-salt'));
        Database::run('UPDATE members SET last_login_at=?, last_login_ip_hash=? WHERE id=?', [date('Y-m-d H:i:s'), $ipHash, (int)$m['id']]);
        if (!self::bindMemberSession($m)) return [false, '会话创建失败，请重试'];
        return [true, '登录成功'];
    }

    /**
     * 注册（v2.9.0）：手机号 + 密码，不依赖微信/短信插件。
     * 已有同号会员必须先通过预约查询码或短信验证码证明手机号归属，再在登录态设置密码。
     */
    public static function register(string $phone, string $password, string $name, string $email): array
    {
        $phone = trim($phone);
        $name = trim($name);
        $email = trim($email);
        if (!self::validPhone($phone)) return [false, '手机号格式不正确'];
        $pwdErr = \app\Core\PasswordPolicy::check($password);
        if ($pwdErr !== null) return [false, $pwdErr];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, '邮箱格式不正确'];
        if (mb_strlen($name) > 40) return [false, '称呼过长'];
        if (!RateLimiter::hit('member_register', $phone . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600)) {
            return [false, '注册尝试过于频繁，请 1 小时后再试'];
        }
        $m = Database::fetch('SELECT * FROM members WHERE phone=?', [$phone]);
        if ($m) {
            if (($m['status'] ?? '') !== 'active') return [false, '该账号当前不可登录'];
            return [false, '该手机号已有会员记录，请先用预约查询码或短信验证码登录，再设置密码'];
        }
        $ipHash = md5(($_SERVER['REMOTE_ADDR'] ?? '') . (CONFIG['stats_salt'] ?? 'psyisle-salt'));
        Database::insert('members', [
            'phone' => $phone,
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip_hash' => $ipHash,
        ]);
        if (!self::bindMemberSession([
            'id' => (int)Database::value('SELECT id FROM members WHERE phone=?', [$phone]),
            'phone' => $phone,
            'name' => $name !== '' ? $name : null,
        ])) return [false, '会话创建失败，请重试'];
        NotifyService::push('member', '新会员注册', '手机号 ' . self::maskPhone($phone) . ' 完成注册（手机号+密码）');
        return [true, '注册成功，欢迎加入会员中心'];
    }

    /** 修改密码（登录态；$old 为空且未设过密码时可直接设置） */
    public static function changePassword(array $m, string $old, string $new): array
    {
        $pwdErr = \app\Core\PasswordPolicy::check($new);
        if ($pwdErr !== null) return [false, $pwdErr];
        if (!RateLimiter::hit('member_pwd_change', (int)$m['id'] . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 600)) {
            return [false, '尝试过于频繁，请 10 分钟后再试'];
        }
        $row = Database::fetch('SELECT password_hash FROM members WHERE id=?', [(int)$m['id']]);
        if ($row && !empty($row['password_hash'])) {
            if ($old === '' || !password_verify($old, (string)$row['password_hash'])) return [false, '当前密码不正确'];
        }
        $stmt = Database::run('UPDATE members SET password_hash=? WHERE id=? AND status="active"', [password_hash($new, PASSWORD_BCRYPT), (int)$m['id']]);
        if ($stmt->rowCount() !== 1) return [false, '账号状态已变化，请重新登录后再试'];
        return [true, '密码已更新'];
    }

    /** 更新邮箱（通知主通道） */
    public static function updateEmail(array $m, string $email): array
    {
        $email = trim($email);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, '邮箱格式不正确'];
        Database::run('UPDATE members SET email=NULLIF(?,"") WHERE id=?', [$email, (int)$m['id']]);
        return [true, $email !== '' ? '邮箱已更新，通知将发送至该邮箱' : '邮箱已清除'];
    }

    /** v2.20.0：更新会员资料（姓名/邮箱，小程序与 WEB 共用；手机号为登录标识不开放修改） */
    public static function updateProfile(array $m, string $name, string $email): array
    {
        $name = trim($name);
        $email = trim($email);
        if (mb_strlen($name) > 20) return [false, '姓名过长（最多 20 字）'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, '邮箱格式不正确'];
        Database::run('UPDATE members SET name=NULLIF(?,""), email=NULLIF(?,"") WHERE id=?', [$name, $email, (int)$m['id']]);
        return [true, '资料已更新'];
    }

    /** 读取通知偏好（缺省：邮件开、微信开；未配置邮箱时邮件实际不发但偏好记录保留） */
    public static function notifyPrefs(int $memberId): array
    {
        $raw = (string)Database::value('SELECT notify_prefs FROM members WHERE id=?', [$memberId]);
        $p = json_decode($raw, true);
        $prefs = is_array($p) ? $p : [];
        return [
            'mail' => (int)($prefs['mail'] ?? 1) === 1,
            'wechat' => (int)($prefs['wechat'] ?? 1) === 1,
            'sms' => (int)($prefs['sms'] ?? 1) === 1,
        ];
    }

    /** 保存通知偏好（短信通道启用后允许关闭邮件/微信；未启用短信时邮件保持开启） */
    public static function saveNotifyPrefs(int $memberId, bool $mail, bool $wechat): array
    {
        // enabled() 是实例方法（NotifyChannel 接口），静态调用在 HTTP 下会因 error handler 抛 ErrorException
        $smsOn = class_exists(\plugins\smsnotify\Plugin::class) && (new \plugins\smsnotify\Plugin())->enabled();
        if (!$smsOn && !$mail) return [false, '短信通知未启用，邮件通知不可关闭（保证至少一条通知通道）'];
        $prefs = json_encode(['mail' => $mail ? 1 : 0, 'wechat' => $wechat ? 1 : 0], JSON_UNESCAPED_UNICODE);
        Database::run('UPDATE members SET notify_prefs=? WHERE id=?', [$prefs, $memberId]);
        return [true, '通知偏好已保存'];
    }

    /** 当前登录会员完整行（含 email/password 状态；中心设置页用） */
    public static function currentFull(): ?array
    {
        $cur = self::current();
        if (!$cur) return null;
        $row = Database::fetch('SELECT * FROM members WHERE id=?', [(int)$cur['id']]);
        if ($row) { $row['notify_prefs'] = self::notifyPrefs((int)$row['id']); }
        return $row;
    }

    /** 当前登录会员（含 UA 校验；失败返回 null） */
    public static function current(): ?array
    {
        $u = Session::get('member_user');
        if (!$u) return null;
        $ua = Session::get('member_ua');
        if (!is_string($ua) || $ua === '') {
            Session::forget('member_user');
            Session::forget('member_ua');
            return null;
        }
        $cur = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!hash_equals($ua, $cur)) {
            Session::forget('member_user');
            return null;
        }
        $m = Database::fetch('SELECT * FROM members WHERE id=? AND status="active"', [(int)$u['id']]);
        if (!$m) { Session::forget('member_user'); return null; }
        return ['id' => (int)$m['id'], 'phone' => (string)$m['phone'], 'name' => $m['name'] ?: self::maskPhone((string)$m['phone'])];
    }

    public static function logout(): void
    {
        Session::forget('member_user');
        Session::forget('member_ua');
    }

    /** 前台守卫：未登录跳登录页 */
    public static function requireLogin(): void
    {
        if (!self::current()) {
            \app\Core\ResponseRedirect('/member/login');
        }
    }

    /** 我的预约（本人手机号匹配） */
    public static function myBookings(int $memberId, string $phone): array
    {
        return Database::fetchAll(
            'SELECT id, code, name, status, method, prefer_date, prefer_slot, issue, admin_note, created_at, amount_cents, pay_status
             FROM bookings WHERE phone=? ORDER BY created_at DESC LIMIT 50', [$phone]);
    }

    public static function bookingStartTs(array $booking): ?int
    {
        $date = trim((string)($booking['prefer_date'] ?? ''));
        if ($date === '') return null;
        $start = substr((string)($booking['prefer_slot'] ?? ''), 0, 5);
        $ts = strtotime($start !== '' ? $date . ' ' . $start : $date);
        return $ts === false ? null : $ts;
    }

    public static function canChangeBooking(array $booking): bool
    {
        if (!in_array($booking['status'] ?? '', ['pending', 'confirmed', 'rescheduled'], true)) return false;
        $start = self::bookingStartTs($booking);
        return $start === null || $start - time() >= 86400;
    }

    public static function bookingNextStep(array $booking): string
    {
        $status = (string)($booking['status'] ?? '');
        if ($status === 'cancelled') return '本次预约已取消，不占用咨询师时段；如需咨询请重新预约。';
        if ($status === 'completed') return '咨询已完成；如有后续安排，请查看机构消息或联系咨询师。';
        if ($status === 'rescheduled') return '机构已调整时间，请以当前显示的日期和时段为准。';
        if ((string)($booking['pay_status'] ?? '') === 'unpaid' && (int)($booking['amount_cents'] ?? 0) > 0) {
            return '请先完成在线支付，支付成功后预约自动生效。';
        }
        if ($status === 'pending') return '预约已收到，机构确认后会通过站内消息、微信或短信通知你。';
        if ($status === 'confirmed') return '预约已生效；请提前准备，并按预约时间进入咨询室或前往咨询地点。';
        return '如需了解进度，请联系机构。';
    }

    /** 取消预约：仅待确认/已确认/已改期，且距咨询开始至少 24 小时 */
    public static function cancelBooking(int $memberId, int $bookingId): array
    {
        return self::cancelForMember(self::current() ?: [], $bookingId);
    }

    public static function cancelForMember(array $m, int $bookingId): array
    {
        if (!$m) return [false, '请先登录'];
        $b = Database::fetch('SELECT * FROM bookings WHERE id=? AND phone=?', [$bookingId, $m['phone']]);
        if (!$b) return [false, '预约不存在或无权操作'];
        if (!in_array($b['status'], ['pending', 'confirmed', 'rescheduled'], true)) return [false, '当前状态不可取消'];
        [$ok, $message, $updated, $changed] = BookingLifecycle::transition(
            $bookingId, 'cancelled', (int)$m['id'], (string)($m['name'] ?: '会员'), '会员自助取消', true
        );
        if (!$ok) return [$ok, $message];
        if (!$changed) return [true, '预约已取消'];
        $b = $updated;
        NotifyService::push('booking', '会员取消预约', "查询码 {$b['code']}（" . self::maskPhone($m['phone']) . "）已由会员在自助中心取消", ['type' => 'booking', 'id' => $bookingId]);
        // v2.17.0：自助取消 → 会员+咨询师双侧三通道
        \app\Services\MemberNotifyService::push((int)$m['id'], 'booking_cancel', [
            'title' => '预约已取消：' . (string)$b['code'],
            'body' => (string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '') . ' · ' . (string)($b['method'] ?? ''),
            'lines' => ['查询码' => (string)$b['code'], '时间' => trim((string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '')) ?: '待确认', '状态' => '已取消'],
            'inapp_url' => '/member/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$b['code'],
            'booking' => ['id' => (int)$b['id'], 'code' => (string)$b['code'], 'phone' => (string)$m['phone']],
        ]);
        if ((int)($b['counselor_id'] ?? 0) > 0) {
            \app\Services\CounselorNotifyService::push((int)$b['counselor_id'], 'booking_cancel', [
                'title' => '会员取消预约：' . (string)$b['code'],
                'body' => (string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '') . ' · 会员 ' . (string)$m['name'],
                'url' => '/admin/counselor-home/bookings', 'booking_id' => (int)$b['id'],
                'booking' => ['id' => (int)$b['id'], 'code' => (string)$b['code'], 'phone' => (string)$m['phone']],
            ]);
        }
        return [true, '预约已取消'];
    }

    private static function rescheduleStartTs(string $date, string $slot): ?int
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;
        if (preg_match('/\d{1,2}:\d{2}/', $slot)) {
            if (!preg_match('/(?:^|[^\d])([01]\d|2[0-3]):([0-5]\d)/', $slot, $m)) return null;
            $parsed->setTime((int)$m[1], (int)$m[2]);
        }
        return $parsed->getTimestamp();
    }

    /** 改期申请：记录到机构备注 + 通知（管理员改后回电确认） */
    /** v2.20.2：改会员行注入参数（WEB 会话与小程序 BFF 共用）；兼容旧签名（传 int 时按 WEB 会话取） */
    public static function requestReschedule($m, int $bookingId, string $newDate, string $newSlot): array
    {
        if (is_int($m)) $m = self::current() ?? [];
        if (!$m) return [false, '请先登录'];
        $b = Database::fetch('SELECT * FROM bookings WHERE id=? AND phone=?', [$bookingId, $m['phone']]);
        if (!$b) return [false, '预约不存在或无权操作'];
        if (!in_array($b['status'], ['pending', 'confirmed', 'rescheduled'], true)) return [false, '当前状态不可申请改期'];
        $newSlot = str_take(trim($newSlot), 50, '');
        $targetStart = self::rescheduleStartTs($newDate, $newSlot);
        if ($targetStart === null) return [false, '期望日期格式不正确'];
        if ($targetStart - time() < 86400) return [false, '改期时间至少在 24 小时后'];
        $note = '【会员改期申请 ' . date('m-d H:i') . '】希望改到 ' . $newDate . ($newSlot !== '' ? ' ' . $newSlot : '') . '；原时间 ' . (string)$b['prefer_date'] . ' ' . (string)$b['prefer_slot'];
        $hasNoteCol = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'admin_note'") ? true : false;
        if ($hasNoteCol) {
            Database::run('UPDATE bookings SET admin_note=CONCAT(COALESCE(admin_note,""),?) WHERE id=?', ["\n" . $note, $bookingId]);
        }
        NotifyService::push('booking', '会员申请改期', "查询码 {$b['code']}（" . self::maskPhone($m['phone']) . "）申请：{$note}", ['type' => 'booking', 'id' => $bookingId]);
        // v2.17.0：改期申请 → 会员回执 + 咨询师知会（状态未变，机构确认后再推 booking_reschedule）
        \app\Services\MemberNotifyService::push((int)$m['id'], 'booking_reschedule', [
            'title' => '改期申请已提交：' . (string)$b['code'],
            'body' => '希望改到 ' . $newDate . ($newSlot !== '' ? ' ' . $newSlot : ''),
            'lines' => ['查询码' => (string)$b['code'], '原时间' => trim((string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '')) ?: '待确认', '期望时间' => $newDate . ($newSlot !== '' ? ' ' . $newSlot : '')],
            'inapp_url' => '/member/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$b['code'],
            'note' => '机构确认后将通过微信/短信通知您，最终以确认信息为准。',
            'booking' => ['id' => (int)$b['id'], 'code' => (string)$b['code'], 'phone' => (string)$m['phone']],
        ]);
        if ((int)($b['counselor_id'] ?? 0) > 0) {
            \app\Services\CounselorNotifyService::push((int)$b['counselor_id'], 'booking_reschedule', [
                'title' => '会员申请改期：' . (string)$b['code'],
                'body' => '原 ' . (string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '') . ' → 期望 ' . $newDate . ($newSlot !== '' ? ' ' . $newSlot : ''),
                'url' => '/admin/counselor-home/bookings', 'booking_id' => (int)$b['id'],
                'booking' => ['id' => (int)$b['id'], 'code' => (string)$b['code'], 'phone' => (string)$m['phone']],
            ]);
        }
        return [true, '改期申请已提交，机构确认后将回电通知您'];
    }

    /** 本人数据导出（JSON 下载） */
    public static function exportData(array $m): array
    {
        $bookings = self::myBookings((int)$m['id'], $m['phone']);
        foreach ($bookings as &$b) { unset($b['admin_note']); } // 导出不含机构内部备注
        unset($b);
        $joined = Database::value('SELECT created_at FROM members WHERE id=?', [(int)$m['id']]);
        $assess = Database::fetchAll(
            'SELECT a.slug, a.name AS assessment_name, r.score, r.level, r.created_at
             FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id
             WHERE r.member_id=? ORDER BY r.created_at DESC LIMIT 200', [(int)$m['id']]);
        $feedbacks = Database::fetchAll(
            'SELECT booking_id, rating, comment, wants_followup, bridge_status, submitted_at, created_at, updated_at
             FROM booking_feedbacks WHERE member_id=? ORDER BY id DESC', [(int)$m['id']]
        );
        return [
            'exported_at' => date('c'),
            'member' => ['phone' => self::maskPhone($m['phone']), 'name' => $m['name'], 'joined_at' => $joined],
            'bookings' => $bookings,
            'post_session_feedbacks' => $feedbacks,
            'assessments' => $assess,
        ];
    }

    /** 注销申请：通知管理员，状态标记待处理 */
    public static function requestDelete(array $m): array
    {
        $exists = Database::value('SELECT status FROM members WHERE id=?', [(int)$m['id']]);
        if ($exists === 'delete_requested') return [false, '注销申请已提交，请等待机构处理'];
        Database::run("UPDATE members SET status='delete_requested' WHERE id=?", [(int)$m['id']]);
        self::logout();
        NotifyService::push('member', '会员注销申请', '手机号 ' . self::maskPhone((string)$m['phone']) . ' 申请删除个人数据。请在「会员管理」中确认：删除其预约关联与测评记录后移除会员。', [], true);
        return [true, '注销申请已提交。机构将在核实后删除您的预约与测评记录（法律要求的留存认知记录除外）'];
    }

    /** ===== v2.11.0：密码找回（邮件 token） ===== */

    /** v2.14.0：短信验证码重置密码第一步——发送验证码到已绑定会员手机号 */
    public static function sendPasswordResetSms(string $phone): array
    {
        $phone = trim($phone);
        if (!self::validPhone($phone)) return [false, '手机号格式不正确'];
        if (!self::smsReady()) return [false, '短信验证码通道未配置完整，请联系机构'];
        if (!RateLimiter::hit('pwd_sms', $phone, 3, 3600)) return [false, '请求过于频繁，请 1 小时后再试'];
        if (!RateLimiter::hit('pwd_sms_cooldown', $phone, 1, 60)) return [false, '发送过于频繁，请 60 秒后再试'];
        if (!RateLimiter::hit('pwd_sms_ip', $_SERVER['REMOTE_ADDR'] ?? '', 10, 3600)) return [false, '本机发送次数已达上限，请稍后再试'];
        $m = Database::fetch('SELECT id, name FROM members WHERE phone=? AND status="active"', [$phone]);
        if (!$m) return [false, '该手机号未注册会员'];
        if (!class_exists(\plugins\smsnotify\Plugin::class)) return [false, '短信通道未安装'];
        $code = (string)random_int(100000, 999999);
        [$ok, $msg] = \plugins\smsnotify\Plugin::sendCode($phone, $code);
        if (!$ok) return [false, $msg];
        Cache::set('pwd_sms:' . hash('sha256', $phone), json_encode(['code' => $code, 'id' => (int)$m['id']], JSON_UNESCAPED_UNICODE), 600);
        return [true, '验证码已发送（10 分钟内有效）'];
    }

    /** v2.14.0：短信重置第二步——校验验证码并重置密码 */
    public static function resetPasswordBySms(string $phone, string $code, string $newPassword): array
    {
        $phone = trim($phone);
        $code = trim($code);
        if (!self::validPhone($phone) || !preg_match('/^\d{6}$/', $code)) return [false, '参数不正确'];
        $pwdErr = \app\Core\PasswordPolicy::check($newPassword);
        if ($pwdErr !== null) return [false, $pwdErr];
        if (!RateLimiter::hit('pwd_sms_verify_phone', $phone, 10, 1800)
            || !RateLimiter::hit('pwd_sms_verify', $phone . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 1800)) {
            return [false, '验证码尝试过于频繁，请 30 分钟后再试'];
        }
        $key = 'pwd_sms:' . hash('sha256', $phone);
        $row = null;
        if (!Cache::consumeIf($key, static function ($raw) use ($code, &$row): bool {
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) return false;
            $row = $data;
            return isset($data['code'], $data['id'])
                && is_string($data['code']) && hash_equals($data['code'], $code);
        })) return [false, '验证码不正确或已过期'];
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = Database::run('UPDATE members SET password_hash=? WHERE id=? AND phone=? AND status="active"',
            [$hash, (int)$row['id'], $phone]);
        if ($stmt->rowCount() !== 1) return [false, '账号状态已变化，请重新获取验证码'];
        return [true, '密码已重置，请用新密码登录'];
    }

    /** v2.14.0：微信通道找回（已绑定微信的会员在微信内走 wxhub 授权后直接改密，无需验证码） */
    public static function requestPasswordReset(string $email): array
    {
        $email = trim(strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, '请输入正确的邮箱地址'];
        if (!RateLimiter::hit('pwd_reset', $email, 3, 3600)) return [false, '请求过于频繁，请 1 小时后再试'];
        $m = Database::fetch('SELECT id, phone, name, email FROM members WHERE email=? AND status="active"', [$email]);
        if (!$m) return [true, '如果该邮箱已注册，重置链接将发送到您的邮箱（如未收到请检查垃圾箱）'];
        $token = bin2hex(random_bytes(16));
        $key = 'pwd_reset:' . $token;
        Cache::set($key, json_encode(['id' => (int)$m['id'], 'email' => (string)$m['email']], JSON_UNESCAPED_UNICODE), 1800);
        $site = (string)setting('site.name', '心理咨询中心');
        $link = rtrim(base_url(), '/') . '/member/reset-password?token=' . $token;
        $html = '<div style="max-width:560px;margin:0 auto;font-family:PingFang SC,Microsoft YaHei,sans-serif;color:#173010">'
            . '<div style="border-bottom:2px solid #0d9488;padding:12px 0;margin-bottom:14px"><b>' . e($site) . '</b></div>'
            . '<h2 style="font-size:16px">密码重置</h2>'
            . '<p>请点击以下链接重置密码（30 分钟内有效）：</p>'
            . '<p><a href="' . e($link) . '" style="background:#0d9488;color:#fff;padding:9px 20px;border-radius:8px;text-decoration:none">重置密码</a></p>'
            . '<p style="font-size:12px;color:#94a3b8">如果按钮无法点击，请复制链接：<br>' . e($link) . '</p>'
            . '<p style="font-size:12px;color:#94a3b8">如果您没有请求重置密码，请忽略本邮件。</p></div>';
        $r = Mailer::send($email, '【' . $site . '】密码重置', $html);
        if ($r['ok'] ?? false) return [true, '重置链接已发送到您的邮箱（30 分钟内有效）'];
        Cache::forget($key);
        return [false, '邮件发送失败，请稍后再试或联系机构'];
    }

    public static function resetPassword(string $token, string $newPassword): array
    {
        $pwdErr = \app\Core\PasswordPolicy::check($newPassword);
        if ($pwdErr !== null) return [false, $pwdErr];
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return [false, '重置链接无效或已过期，请重新获取'];
        $data = null;
        if (!Cache::consumeIf('pwd_reset:' . $token, static function ($raw) use (&$data): bool {
            $data = json_decode((string)$raw, true);
            return is_array($data) && !empty($data['id']) && !empty($data['email']);
        })) return [false, '重置链接无效或已过期，请重新获取'];
        $stmt = Database::run('UPDATE members SET password_hash=? WHERE id=? AND email=? AND status="active"',
            [password_hash($newPassword, PASSWORD_BCRYPT), (int)$data['id'], (string)$data['email']]);
        if ($stmt->rowCount() !== 1) return [false, '账号信息已变化，请重新获取重置链接'];
        return [true, '密码已重置，请使用新密码登录'];
    }

    public static function maskPhone(string $phone): string
    {
        return strlen($phone) >= 11 ? substr($phone, 0, 3) . '****' . substr($phone, -4) : $phone;
    }

    private static function validPhone(string $phone): bool
    {
        return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
    }
}
