<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\Session;
use app\Services\MemberService;
use app\Services\StatsService;

/**
 * 会员自助中心（前台，独立会话域 member_user，与后台 admin 隔离）
 * /member/login  登录（预约码 / 短信验证码）
 * /member        中心首页
 * /member/bookings        我的预约
 * /member/assessments     我的测评
 * /member/privacy         隐私中心（导出/注销）
 */
class Member
{
    public function login(Request $req, array $p): void
    {
        StatsService::track();
        if (MemberService::current()) { \app\Core\ResponseRedirect('/member'); }
        if (Session::get('admin_user')) { \app\Core\ResponseRedirect('/admin'); }
        $error = '';
        if ($req->isPost()) {
            Csrf::guard();
            $account = trim((string)$req->post('account', ''));
            $password = (string)$req->post('password', '');
            if ($account === '' || $password === '') {
                $error = '请输入账号和密码';
            } else {
                // ① 会员（手机号 + 密码）
                [$ok, $msg] = MemberService::loginByPassword($account, $password);
                if ($ok) { \app\Core\ResponseRedirect('/member'); }
                // ② 咨询师（后台账号名 + 密码，绑定咨询师档案 → 直进工作台）
                [$cslOk, $cslMsg] = $this->loginCounselor($account, $password, $req);
                if ($cslOk) { \app\Core\ResponseRedirect('/admin/counselor-home'); }
                $error = $cslMsg !== '' ? $cslMsg : $msg;
            }
        }
        $this->view('member/login', ['title' => '会员中心 · 登录', 'error' => $error]);
    }

    /**
     * 咨询师前台登录（统一入口分流）：
     * 只放行「绑定咨询师档案的账号」（counselors.user_id），普通管理员仍走后台 /admin 登录。
     * 命中即建立 admin 会话 + 设置后台闸口 cookie，跳咨询师工作台。
     * @return [ok, msg]（msg 为空 = 非咨询师账号，静默，由上层用会员侧错误提示）
     */
    private function loginCounselor(string $account, string $password, Request $req): array
    {
        $u = Database::fetch('SELECT * FROM users WHERE username=? AND status=1', [$account]);
        if (!$u) return [false, ''];
        if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) {
            return [false, '账号已临时锁定，请 15 分钟后再试'];
        }
        if (!password_verify($password, (string)$u['password_hash'])) {
            // 后台账号密码错：走后台登录失败计数逻辑（复用限流语义）
            $failed = (int)$u['failed_count'] + 1;
            $upd = ['failed_count' => $failed];
            if ($failed >= 5) { $upd['locked_until'] = date('Y-m-d H:i:s', time() + 900); }
            Database::update('users', $upd, 'id=?', [$u['id']]);
            return [false, '账号或密码不正确'];
        }
        if (!empty($u['totp_secret'])) {
            return [false, '该账号已开启两步验证，请从后台登录入口登录'];
        }
        // 仅放行绑定咨询师档案的账号（普通管理员不得经前台登录）
        $csl = Database::fetch('SELECT id FROM counselors WHERE user_id=? AND status=1', [(int)$u['id']]);
        if (!$csl) {
            return [false, '该账号为后台管理账号，请从后台登录入口登录'];
        }
        $ip = $req->ip();
        Database::update('users', [
            'failed_count' => 0, 'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip,
        ], 'id=?', [$u['id']]);
        Session::set('admin_user', [
            'id' => $u['id'], 'username' => $u['username'],
            'display_name' => $u['display_name'] ?: $u['username'], 'role_id' => $u['role_id'],
        ]);
        Session::set('admin_last_active', time());
        Session::set('admin_ua', hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
        // 后台闸口 cookie（与后台入口同款），否则跳 /admin/counselor-home 会被闸口拦成 404
        $gate = trim((string)setting('security.admin_gate', ''));
        if ($gate !== '') {
            setcookie('psy_admin_gate', hash('sha256', $gate), [
                'expires' => time() + 30 * 86400, 'path' => '/', 'samesite' => 'Lax',
                'httponly' => true, 'secure' => $req->isSecure(),
            ]);
        }
        session_regenerate_id(true);
        return [true, ''];
    }

    /** 注册（v2.9.0）：手机号+密码，零外部依赖 */
    public function register(Request $req, array $p): void
    {
        StatsService::track();
        if (MemberService::current()) { \app\Core\ResponseRedirect('/member'); }
        $error = '';
        if ($req->isPost()) {
            Csrf::guard();
            if (!RateLimiter::hit('member_reg_page', $_SERVER['REMOTE_ADDR'] ?? '', 10, 3600)) {
                $error = '请求过于频繁';
            } else {
                [$ok, $msg] = MemberService::register(
                    (string)$req->post('phone', ''), (string)$req->post('password', ''),
                    (string)$req->post('name', ''), (string)$req->post('email', '')
                );
                if ($ok) { \app\Core\ResponseRedirect('/member'); }
                $error = $msg;
            }
        }
        $this->view('member/register', ['title' => '注册会员', 'error' => $error]);
    }

    /** 会员设置（改密码 / 邮箱 / 通知偏好） */
    public function settings(Request $req, array $p): void
    {
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        if ($req->isPost()) {
            Csrf::guard();
            $act = (string)$req->post('act', '');
            if ($act === 'password') {
                [$ok, $msg] = MemberService::changePassword($m, (string)$req->post('old_password', ''), (string)$req->post('new_password', ''));
            } elseif ($act === 'email') {
                [$ok, $msg] = MemberService::updateEmail($m, (string)$req->post('email', ''));
            } elseif ($act === 'prefs') {
                [$ok, $msg] = MemberService::saveNotifyPrefs((int)$m['id'], !empty($req->post('pref_mail')), !empty($req->post('pref_wechat')));
            } else {
                [$ok, $msg] = [false, '未知操作'];
            }
            \app\Core\Session::flash($ok ? 'success' : 'error', $msg);
            \app\Core\ResponseRedirect('/member/settings');
        }
        $full = MemberService::currentFull();
        $this->view('member/settings', [
            'title' => '会员设置', 'm' => $full,
            'smsOn' => MemberService::smsReady() && class_exists(\plugins\smsnotify\Plugin::class),
            'wxOn' => (bool)setting('wxlogin.enabled', '0') || \app\Core\PluginManager::isEnabled('wxhub'),
        ]);
    }

    /** 发送手机验证码（POST，限流在服务层）—— 仅用于微信绑定/密码找回，不再用于登录 */
    public function sendCode(Request $req, array $p): void
    {
        Csrf::guard();
        header('Content-Type: application/json; charset=utf-8');
        if (!RateLimiter::hit('member_code_page', $_SERVER['REMOTE_ADDR'] ?? '', 20, 3600)) {
            json_out(['ok' => false, 'error' => '请求过于频繁']);
        }
        [$ok, $msg] = MemberService::sendCode((string)$req->post('phone', ''));
        json_out(['ok' => $ok, 'error' => $ok ? '' : $msg, 'message' => $ok ? $msg : '']);
    }

    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $bookings = MemberService::myBookings((int)$m['id'], $m['phone']);
        $upcoming = array_values(array_filter($bookings, fn ($b) => in_array($b['status'], ['pending', 'confirmed', 'rescheduled'], true)));
        $assessCount = (int)\app\Core\Database::value('SELECT COUNT(*) FROM assessment_results WHERE member_id=?', [(int)$m['id']]);
        // v2.8.1：插件卡片支持 sort 字段（缺省 50），中心页按序渲染；插件可注入待办与徽标
        $cards = \app\Core\Hook::fire('member.cards', ['member' => $m, 'cards' => []])['cards'] ?? [];
        usort($cards, fn ($a, $b) => ((int)($a['sort'] ?? 50)) <=> ((int)($b['sort'] ?? 50)));
        $todo = array_values(array_filter(
            \app\Core\Hook::fire('member.todo', ['member' => $m, 'items' => []])['items'] ?? [],
            fn ($t) => !empty($t['label']) && !empty($t['url'])
        ));
        $badges = \app\Core\Hook::fire('member.badges', ['member' => $m, 'badges' => []])['badges'] ?? [];
        $this->view('member/center', [
            'title' => '会员中心', 'm' => $m,
            'upcoming' => array_slice($upcoming, 0, 3),
            'upcomingCount' => count($upcoming),
            'bookingTotal' => count($bookings), 'assessCount' => $assessCount,
            'extraCards' => $cards, 'todo' => $todo, 'badges' => $badges,
        ]);
    }

    public function bookings(Request $req, array $p): void
    {
        StatsService::track();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $list = MemberService::myBookings((int)$m['id'], $m['phone']);
        // v2.13.0：插件注入行级动作（rtc-pro：时间窗内「进入咨询室」）
        $list = \app\Core\Hook::fire('member.booking.rows', ['member' => $m, 'rows' => $list])['rows'] ?? $list;
        $statusMap = ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'];
        $this->view('member/bookings', ['title' => '我的预约', 'm' => $m, 'list' => $list, 'statusMap' => $statusMap]);
    }

    public function cancelBooking(Request $req, array $p): void
    {
        Csrf::guard();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        [$ok, $msg] = MemberService::cancelBooking((int)$m['id'], (int)$p['id']);
        \app\Core\Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/member/bookings');
    }

    public function rescheduleBooking(Request $req, array $p): void
    {
        Csrf::guard();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        [$ok, $msg] = MemberService::requestReschedule(
            (int)$m['id'], (int)$p['id'],
            (string)$req->post('new_date', ''), (string)$req->post('new_slot', '')
        );
        \app\Core\Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/member/bookings');
    }

    public function assessments(Request $req, array $p): void
    {
        StatsService::track();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $rows = \app\Core\Database::fetchAll(
            'SELECT a.slug, a.name AS assessment_name, r.score, r.level, r.created_at, r.report_token
             FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id
             WHERE r.member_id=? ORDER BY r.created_at DESC LIMIT 100', [(int)$m['id']]);
        $grouped = [];
        foreach ($rows as $r) { $grouped[$r['assessment_name']][] = $r; }
        $this->view('member/assessments', ['title' => '我的测评', 'm' => $m, 'grouped' => $grouped, 'total' => count($rows)]);
    }

    public function privacy(Request $req, array $p): void
    {
        StatsService::track();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $this->view('member/privacy', ['title' => '隐私中心', 'm' => $m]);
    }

    /** 本人数据导出（JSON 下载） */
    public function export(Request $req, array $p): void
    {
        Csrf::guard();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $data = MemberService::exportData($m);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-data-' . date('Ymd-His') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 忘记密码（提交邮箱 → 发送重置链接） */
    public function forgotPassword(Request $req, array $p): void
    {
        StatsService::track();
        $msg = ''; $ok = false;
        if ($req->isPost()) {
            \app\Core\Csrf::guard();
            [$ok, $msg] = MemberService::requestPasswordReset((string)$req->post('email', ''));
            if ($ok) {
                \app\Core\Session::flash('success', $msg);
                \app\Core\ResponseRedirect('/member/login');
            }
        }
        $this->view('member/forgot-password', ['title' => '忘记密码', 'error' => $ok ? '' : $msg]);
    }

    /** 重置密码（从邮件链接进入） */
    /** v2.14.0：短信找回（发送验证码） */
    public function forgotPasswordSms(Request $req, array $p): void
    {
        Csrf::guard();
        header('Content-Type: application/json; charset=utf-8');
        [$ok, $msg] = \app\Services\MemberService::sendPasswordResetSms((string)$req->post('phone', ''));
        json_out(['ok' => $ok, 'message' => $msg]);
    }

    /** v2.14.0：短信重置密码（校验验证码+改密） */
    public function resetPasswordSms(Request $req, array $p): void
    {
        Csrf::guard();
        [$ok, $msg] = \app\Services\MemberService::resetPasswordBySms(
            (string)$req->post('phone', ''), (string)$req->post('sms_code', ''), (string)$req->post('new_password', ''));
        \app\Core\Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect($ok ? '/member/login' : '/member/forgot-password');
    }

    public function resetPassword(Request $req, array $p): void
    {
        StatsService::track();
        $token = (string)$req->get('token', '') ?: (string)$req->post('token', '');
        $error = '';
        if ($req->isPost()) {
            \app\Core\Csrf::guard();
            [$ok, $msg] = MemberService::resetPassword((string)$req->post('token', ''), (string)$req->post('new_password', ''));
            if ($ok) {
                \app\Core\Session::flash('success', $msg);
                \app\Core\ResponseRedirect('/member/login');
            }
            $error = $msg;
        }
        $this->view('member/reset-password', ['title' => '重置密码', 'error' => $error, 'token' => $token]);
    }

    public function requestDelete(Request $req, array $p): void
    {
        Csrf::guard();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        [$ok, $msg] = MemberService::requestDelete($m);
        \app\Core\Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect($ok ? '/' : '/member/privacy');
    }

    /** 兑换码（咨询套餐/转介绍邀请码；逻辑由 packages-pro 插件接管） */
    public function redeem(Request $req, array $p): void
    {
        Csrf::guard();
        $m = \app\Services\MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $ctx = \app\Core\Hook::fire('member.redeem', ['member' => $m, 'code' => (string)$req->post('code', ''), 'ok' => false, 'msg' => '暂无兑换服务']);
        \app\Core\Session::flash($ctx['ok'] ? 'success' : 'error', (string)$ctx['msg']);
        \app\Core\ResponseRedirect('/member');
    }

    public function logout(Request $req, array $p): void
    {
        MemberService::logout();
        \app\Core\ResponseRedirect('/');
    }

    // ==================== 站内消息（v2.14 遗留方法补齐：路由已挂但方法缺失致 404） ====================

    /** 站内消息列表 */
    public function notifications(Request $req, array $p): void
    {
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $rows = \app\Core\Database::fetchAll(
            'SELECT * FROM member_notifications WHERE member_id=? ORDER BY id DESC LIMIT 50', [(int)$m['id']]);
        $unread = (int)\app\Core\Database::value(
            'SELECT COUNT(*) FROM member_notifications WHERE member_id=? AND is_read=0', [(int)$m['id']]);
        $this->view('member/notifications', ['title' => '站内消息', 'rows' => $rows, 'unread' => $unread]);
    }

    /** 标记已读（同步写通知查阅回执——短信兜底取消信号） */
    public function markRead(Request $req, array $p): void
    {
        Csrf::guard();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $id = (int)$p['id'];
        \app\Core\Database::run('UPDATE member_notifications SET is_read=1 WHERE id=? AND member_id=?', [$id, (int)$m['id']]);
        \app\Services\MemberNotifyService::markEscalationReadByInapp((int)$m['id'], $id);
        \app\Core\Session::flash('success', '已标记为已读');
        \app\Core\ResponseRedirect('/member/notifications');
    }

    /** 未读数 JSON（前端徽标轮询） */
    public function unreadJson(Request $req, array $p): void
    {
        $m = MemberService::current();
        if (!$m) { json_out(['ok' => false, 'unread' => 0]); }
        $unread = (int)\app\Core\Database::value(
            'SELECT COUNT(*) FROM member_notifications WHERE member_id=? AND is_read=0', [(int)$m['id']]);
        json_out(['ok' => true, 'unread' => $unread]);
    }

    private function view(string $tpl, array $data = []): void
    {
        // 会员页自带 flash 渲染（前台布局无全局 flash）
        $data['flashes'] = \app\Core\Session::takeFlashes();
        // v2.7.0：会员页全部 noindex（个人数据页不应进索引），并把标题传入 meta
        $data['noindex'] = true;
        $data['meta'] = ['title' => (string)($data['title'] ?? ''), 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => ['type' => 'website']];
        \app\Core\View::render($tpl, $data);
    }
}
