<?php
/** 微信小程序插件（v1.0.0）：一键生成专业级小程序包 + BFF 登录桥/预约/JSAPI 支付 */
declare(strict_types=1);

namespace plugins\miniapppro;

use app\Core\Cache;
use app\Core\Crypto;
use app\Core\Database;
use app\Core\Logger;
use app\Core\RateLimiter;

final class Plugin
{
    public static function boot(array $meta): void
    {
        // v1.1.3：counselor_wx 结构保证（咨询师微信绑定表，服务号/小程序双 openid 列）
        self::ensureCounselorWxSchema();
        // BFF 走 frontend_route（front_api 分发）；向导在 admin 页
    }

    /** BFF 入口：/plugin/miniapp-pro/api/{action}（MpApi 需手动加载——核心插件自动加载器按类名推目录，带连字符目录对不上） */
    public static function front_api($req, array $segs = []): void
    {
        require_once __DIR__ . '/MpApi.php';
        MpApi::dispatch($req, $segs);
    }

    // ==================== 后台接入向导 ====================

    public static function adminIndex($req): void
    {
        $enabled = self::enabled();
        $mode = self::loginMode();
        $hubOk = trim((string)setting('wxhub.base_url', '')) !== '' && trim((string)setting('wxhub.site_key', '')) !== '';
        $host = preg_replace('/^https?:\/\//', '', rtrim(base_url(), '/'));
        $selfcheck = \app\Core\Cache::get('miniapp_selfcheck');
        \app\Core\PluginManager::view('miniapp-pro', 'admin', [
            'enabled' => $enabled, 'mode' => $mode, 'hubOk' => $hubOk,
            'appid' => setting('miniapp.appid', ''), 'payAppid' => setting('miniapp.pay_appid', ''),
            'secretSet' => trim(Crypto::decrypt((string)setting('miniapp.secret', ''))) !== '',
            'smsCode' => (bool)setting('sms.code_enabled', '0'),
            'host' => $host, 'selfcheck' => $selfcheck,
            'downloadUrl' => url('/admin/plugin/miniapp-pro/download'),
        ]);
    }

    public static function saveSettings($req): void
    {
        \app\Core\Csrf::guard();
        $post = $req->posts();
        \app\Core\Model::saveSetting('miniapp.enabled', !empty($post['enabled']) ? '1' : '0', 'plugin:miniapp-pro');
        \app\Core\Model::saveSetting('miniapp.login_mode', ($post['login_mode'] ?? '') === 'direct' ? 'direct' : 'hub', 'plugin:miniapp-pro');
        \app\Core\Model::saveSetting('miniapp.appid', trim((string)($post['appid'] ?? '')), 'plugin:miniapp-pro');
        \app\Core\Model::saveSetting('miniapp.pay_appid', trim((string)($post['pay_appid'] ?? '')), 'plugin:miniapp-pro');
        $secret = trim((string)($post['secret'] ?? ''));
        if ($secret !== '' && !\app\Core\Crypto::isMask($secret)) {
            \app\Core\Model::saveSetting('miniapp.secret', \app\Core\Crypto::encrypt($secret), 'plugin:miniapp-pro');
        }
        \app\Core\Cache::forget('settings_all');
        Logger::info('miniapp-pro', 'settings saved', ['mode' => setting('miniapp.login_mode'), 'enabled' => setting('miniapp.enabled')]);
        \app\Core\Session::flash('success', '小程序设置已保存');
        \app\Core\ResponseRedirect('/admin/plugin/miniapp-pro');
    }

    /** 自检：登录桥连通（hub）或 AppID/Secret 形态（direct）+ 前置条件清单 */
    public static function selfcheck($req): void
    {
        \app\Core\Csrf::guard();
        $out = [];
        $out[] = self::loginMode() === 'hub'
            ? (self::hubBase() !== '' && trim((string)setting('wxhub.site_key', '')) !== '' ? '✓ 中台登录桥配置存在（wxhub.base_url/site_key）' : '✗ 中台未配置（wxhub.base_url/site_key）——请先配置「微信中台」插件')
            : (trim((string)setting('miniapp.appid', '')) !== '' ? '✓ 小程序 AppID 已填' : '✗ 小程序 AppID 未填');
        $out[] = \app\Core\PluginManager::isEnabled('wechat-pay') ? '✓ 微信支付插件已启用（JSAPI 支付可用）' : '⚠ 微信支付插件未启用——小程序内无法在线支付';
        $out[] = (bool)setting('sms.code_enabled', '0') ? '✓ 短信验证码已启用（手机号绑定可用）' : '⚠ 短信验证码未启用——小程序绑定手机号不可用';
        $out[] = (bool)setting('sms.enabled', '0') ? '✓ 短信通道已启用' : '⚠ 短信通道未启用';
        if (self::loginMode() === 'hub') {
            $probe = 'probe' . bin2hex(random_bytes(4));
            [$ok, $err] = self::hubMpLogin($probe);
            $out[] = $ok ? '✓ 中台登录桥连通异常应答校验失败（不应发生）' : '✓ 登录桥连通正常（中台正确拒绝无效 code：' . str_take($err, 60, '') . '）';
        }
        \app\Core\Cache::set('miniapp_selfcheck', ['at' => date('m-d H:i'), 'lines' => $out], 3600);
        \app\Core\Session::flash('success', '自检完成');
        \app\Core\ResponseRedirect('/admin/plugin/miniapp-pro');
    }

    /** 下载小程序源码包（zip） */
    public static function download($req): void
    {
        $zip = self::buildZip();
        if ($zip === '' || !is_file($zip)) {
            \app\Core\Session::flash('error', '生成小程序包失败（服务器缺 ZipArchive 扩展）');
            \app\Core\ResponseRedirect('/admin/plugin/miniapp-pro');
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="miniapp-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($zip));
        readfile($zip);
        @unlink($zip);
        exit;
    }

    public static function install(): void
    {
        self::ensureMpOpenidColumn();
    }

    public static function uninstall(): void
    {
        // mp_openid 列保留（会员身份数据不随插件卸载删除）
    }

    // ==================== 配置与开关 ====================

    /** BFF 总开关（默认关——向导里开启） */
    public static function enabled(): bool
    {
        return (bool)setting('miniapp.enabled', '0');
    }

    /** 登录模式：hub=中台登录桥（推荐，无需自配 AppID/Secret）/ direct=自有小程序直连 */
    public static function loginMode(): string
    {
        return (string)setting('miniapp.login_mode', 'hub') === 'direct' ? 'direct' : 'hub';
    }

    private static function hubBase(): string
    {
        return rtrim(trim((string)setting('wxhub.base_url', '')), '/');
    }

    /** 中台小程序登录桥（X-Hub 签名，wxhub-client 站点同款契约） */
    public static function hubMpLogin(string $code): array
    {
        $path = '/api/v1/miniprogram/login';
        $body = json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
        $time = (string)time();
        $nonce = bin2hex(random_bytes(8));
        $sign = hash_hmac('sha256', "POST\n{$path}\n{$time}\n{$nonce}\n" . hash('sha256', $body), trim(Crypto::decrypt((string)setting('wxhub.site_secret', ''))));
        [$ok, $status, $resp] = \app\Core\Http::post(self::hubBase() . $path, $body, [
            'Content-Type: application/json',
            'X-Hub-Site: ' . trim((string)setting('wxhub.site_key', '')),
            'X-Hub-Time: ' . $time,
            'X-Hub-Nonce: ' . $nonce,
            'X-Hub-Signature: ' . $sign,
        ], 15);
        $r = json_decode((string)$resp, true);
        if (!$ok || ($r['ok'] ?? false) !== true) {
            // 中台错误体：{ok:false, code, msg, hint, extra:{extra:{wx_errcode}}}（msg 为微信原文，hint 为人话）
            $extra = (array)($r['extra'] ?? []);
            $wx = (string)($extra['wx_errcode'] ?? ($extra['extra']['wx_errcode'] ?? ''));
            $msg = trim((string)($r['msg'] ?? ($r['message'] ?? ($r['error'] ?? ('HTTP ' . $status)))));
            return [false, $msg, ['wx_errcode' => $wx, 'hint' => (string)($r['hint'] ?? ''), 'code' => (string)($r['code'] ?? '')], $r];
        }
        return [true, '', (array)($r['data'] ?? []), $r];
    }

    /** direct 模式：jscode2session 直连（站点自配小程序 AppID/Secret） */
    public static function directMpLogin(string $code): array
    {
        $appid = trim((string)setting('miniapp.appid', ''));
        $secret = trim(Crypto::decrypt((string)setting('miniapp.secret', '')));
        if ($appid === '' || $secret === '') return [false, '未配置小程序 AppID/Secret', ['retry' => false], null];
        [$ok, $status, $resp] = \app\Core\Http::get(
            'https://api.weixin.qq.com/sns/jscode2session?appid=' . urlencode($appid)
            . '&secret=' . urlencode($secret) . '&js_code=' . urlencode($code) . '&grant_type=authorization_code',
            [], 10
        );
        $r = json_decode((string)$resp, true);
        if (!$ok || empty($r['openid'])) {
            $err = (string)($r['errmsg'] ?? ('HTTP ' . $status));
            $retry = in_array((int)($r['errcode'] ?? 0), [40029, 40163], true);
            return [false, $err . (isset($r['errcode']) ? ' #' . $r['errcode'] : ''), ['retry' => $retry], $r];
        }
        return [true, '', ['openid' => (string)$r['openid'], 'unionid' => (string)($r['unionid'] ?? '')], $r];
    }

    // ==================== 会员身份（认人只写一处） ====================

    /** mp_openid 列幂等迁移（information_schema 守卫，先例 packages-pro） */
    public static function ensureMpOpenidColumn(): void
    {
        try {
            $n = (int)Database::value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME='mp_openid'",
                []
            );
            if ($n === 0) {
                Database::run('ALTER TABLE members ADD COLUMN mp_openid varchar(64) NULL AFTER openid');
                $idx = (int)Database::value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND INDEX_NAME='idx_members_mpopenid'", []);
                if ($idx === 0) Database::run('CREATE INDEX idx_members_mpopenid ON members (mp_openid)');
            }
        } catch (\Throwable $t) {
            Logger::error('miniapp-pro', 'ensure mp_openid: ' . $t->getMessage());
        }
    }

    /**
     * 认人顺序（vip 站 WxLogin 同思路）：unionid 跨端优先 → mp_openid → 开新会员（占位手机号，
     * 绑定手机时并入既有会员或回填真号——uk_phone 唯一约束的安全解）。
     */
    public static function loginOrCreate(array $id): array
    {
        self::ensureMpOpenidColumn();
        $openid = (string)($id['openid'] ?? '');
        $unionid = (string)($id['unionid'] ?? '');
        $m = null;
        if ($unionid !== '') $m = Database::fetch('SELECT * FROM members WHERE unionid=? AND status="active"', [$unionid]);
        if (!$m && $openid !== '') $m = Database::fetch('SELECT * FROM members WHERE mp_openid=? AND status="active"', [$openid]);
        if ($m) {
            Database::run('UPDATE members SET mp_openid=COALESCE(NULLIF(?,""), mp_openid), unionid=COALESCE(NULLIF(?,""), unionid) WHERE id=?',
                [$openid, $unionid, (int)$m['id']]);
            $m = Database::fetch('SELECT * FROM members WHERE id=?', [(int)$m['id']]);
        } else {
            // 占位手机号（uk_phone 唯一）：绑定手机号时回填或并入既有会员
            $placeholder = 'mp_' . bin2hex(random_bytes(5));
            $mid = Database::insert('members', [
                'phone' => $placeholder,
                'mp_openid' => $openid !== '' ? $openid : null,
                'unionid' => $unionid !== '' ? $unionid : null,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $m = Database::fetch('SELECT * FROM members WHERE id=?', [$mid]);
        }
        if ((string)$m['status'] === 'delete_requested') return [false, null, '该账号已提交注销申请，如需恢复请联系机构'];
        return [true, $m, ''];
    }

    /** 小程序会话令牌：HMAC(secret_key) 无表签发，30 天有效 */
    public static function issueToken(int $memberId): string
    {
        $exp = time() + 30 * 86400;
        $payload = $memberId . '.' . $exp;
        return $payload . '.' . hash_hmac('sha256', $payload, (string)(CONFIG['secret_key'] ?? ''));
    }

    /** v1.1.3 咨询师令牌：payload 首段 c 前缀区分角色（c{counselorId}.{exp}.{hmac}） */
    public static function issueCounselorToken(int $counselorId): string
    {
        $exp = time() + 30 * 86400;
        $payload = 'c' . $counselorId . '.' . $exp;
        return $payload . '.' . hash_hmac('sha256', $payload, (string)(CONFIG['secret_key'] ?? ''));
    }

    /** 解析令牌角色：[role, id]（member|counselor|null） */
    public static function tokenRole(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !ctype_digit($parts[1])) return [null, 0];
        if (str_starts_with($parts[0], 'c') && ctype_digit(substr($parts[0], 1))) return ['counselor', (int)substr($parts[0], 1)];
        if (ctype_digit($parts[0])) return ['member', (int)$parts[0]];
        return [null, 0];
    }

    /** v1.1.3 咨询师令牌验证（黑名单与会员共用 mp_tok_bl_ 前缀） */
    public static function counselorByToken(string $token): ?array
    {
        [$role, $cid] = self::tokenRole($token);
        if ($role !== 'counselor' || $cid <= 0) return null;
        $parts = explode('.', $token);
        if ((int)$parts[1] < time()) return null;
        if (!hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], (string)(CONFIG['secret_key'] ?? '')), $parts[2])) return null;
        if (\app\Core\Cache::get('mp_tok_bl_' . hash('sha256', $token)) !== null) return null;
        $c = \app\Core\Database::fetch('SELECT * FROM counselors WHERE id=? AND status=1', [$cid]);
        return $c ?: null;
    }

    /** v1.1.3 counselor_wx 幂等迁移：补 mp_openid 列（小程序 openid 空间与服务号 openid 分列） */
    public static function ensureCounselorWxSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        // v1.1.5 并发保护：cron 与前台请求可能同时触发 DDL，串行化以免 "Duplicate column"
        $fp = @fopen(sys_get_temp_dir() . '/psyisle_miniapp_cwx.lock', 'c');
        if ($fp) {
            @flock($fp, LOCK_EX);
        }
        try {
            $n = (int)\app\Core\Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='counselor_wx'");
            if ($n === 0) {
                \app\Core\Database::run("CREATE TABLE IF NOT EXISTS counselor_wx (
                    id int unsigned NOT NULL AUTO_INCREMENT,
                    counselor_id int unsigned NOT NULL,
                    openid varchar(64) NULL,
                    unionid varchar(64) NULL,
                    nickname varchar(60) NULL,
                    created_at datetime NULL,
                    PRIMARY KEY (id), UNIQUE KEY uk_openid (openid), KEY k_cid (counselor_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            $hasMp = (int)\app\Core\Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='counselor_wx' AND COLUMN_NAME='mp_openid'");
            if (!$hasMp) \app\Core\Database::run("ALTER TABLE counselor_wx ADD COLUMN mp_openid varchar(64) NULL AFTER openid, ADD UNIQUE KEY uk_mp_openid (mp_openid)");
            $hasUk = (int)\app\Core\Database::value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='counselor_wx' AND INDEX_NAME='uk_unionid'");
            if (!$hasUk) \app\Core\Database::run("ALTER TABLE counselor_wx ADD UNIQUE KEY uk_unionid (unionid)");
            // v1.1.3 存量表 openid 可空化（小程序绑定先写 mp_openid，服务号 openid 后补）
            $nullFix = (string)\app\Core\Database::value("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='counselor_wx' AND COLUMN_NAME='openid'");
            if ($nullFix === 'NO') \app\Core\Database::run("ALTER TABLE counselor_wx MODIFY openid varchar(64) NULL");
            $nullFix2 = (string)\app\Core\Database::value("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='counselor_wx' AND COLUMN_NAME='unionid'");
            if ($nullFix2 === 'NO') \app\Core\Database::run("ALTER TABLE counselor_wx MODIFY unionid varchar(64) NULL");
            $done = true;
        } catch (\Throwable $t) {
            // 并发下另一进程已加列/加索引 → 视为已完成，不写错误日志
            $m = $t->getMessage();
            if (stripos($m, 'Duplicate column') === false && stripos($m, 'Duplicate key') === false) {
                \app\Core\Logger::error('miniapp-pro', 'ensureCounselorWxSchema: ' . $m);
            }
        } finally {
            if ($fp) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    /**
     * v1.1.3 咨询师身份识别（登录分流）：unionid → mp_openid → openid 查 counselor_wx。
     * 命中返回 counselors 行，否则 null。
     */
    public static function counselorByWx(string $unionid, string $mpOpenid, string $openid = ''): ?array
    {
        self::ensureCounselorWxSchema();
        if ($unionid !== '') {
            $w = \app\Core\Database::fetch('SELECT counselor_id FROM counselor_wx WHERE unionid=?', [$unionid]);
            if ($w) return \app\Core\Database::fetch('SELECT * FROM counselors WHERE id=? AND status=1', [(int)$w['counselor_id']]);
        }
        if ($mpOpenid !== '') {
            $w = \app\Core\Database::fetch('SELECT counselor_id FROM counselor_wx WHERE mp_openid=?', [$mpOpenid]);
            if ($w) return \app\Core\Database::fetch('SELECT * FROM counselors WHERE id=? AND status=1', [(int)$w['counselor_id']]);
        }
        if ($openid !== '') {
            $w = \app\Core\Database::fetch('SELECT counselor_id FROM counselor_wx WHERE openid=?', [$openid]);
            if ($w) return \app\Core\Database::fetch('SELECT * FROM counselors WHERE id=? AND status=1', [(int)$w['counselor_id']]);
        }
        return null;
    }

    /** v1.1.3 咨询师小程序绑定：手机号+验证码 → 按 counselors.phone 匹配咨询师 → 写 counselor_wx.mp_openid/unionid */
    public static function bindCounselor(string $phone, string $smsCode, string $mpOpenid, string $unionid = ''): array
    {
        self::ensureCounselorWxSchema();
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) return [false, '手机号格式不正确'];
        $key = 'mp_bind:' . hash('sha256', $phone);
        $row = json_decode((string)\app\Core\Cache::get($key), true);
        if (!is_array($row) || !hash_equals((string)($row['code'] ?? ''), trim($smsCode))) {
            return [false, '验证码不正确或已过期'];
        }
        \app\Core\Cache::forget($key);
        $c = \app\Core\Database::fetch('SELECT * FROM counselors WHERE phone=? AND status=1', [$phone]);
        if (!$c) return [false, '该手机号未登记为机构咨询师，请联系管理员核对登记手机号'];
        $cid = (int)$c['id'];
        $dup = \app\Core\Database::fetch('SELECT counselor_id FROM counselor_wx WHERE mp_openid=? AND counselor_id<>?', [$mpOpenid, $cid]);
        if ($dup) return [false, '该微信已绑定其他咨询师账号'];
        $exist = \app\Core\Database::fetch('SELECT id, counselor_id FROM counselor_wx WHERE counselor_id=?', [$cid]);
        if ($exist) {
            \app\Core\Database::run('UPDATE counselor_wx SET mp_openid=?, unionid=COALESCE(NULLIF(?,""),unionid) WHERE id=?', [$mpOpenid, $unionid, (int)$exist['id']]);
        } else {
            \app\Core\Database::insert('counselor_wx', ['counselor_id' => $cid, 'mp_openid' => $mpOpenid, 'unionid' => $unionid !== '' ? $unionid : null, 'created_at' => date('Y-m-d H:i:s')]);
        }
        \app\Services\CounselorNotifyService::push($cid, 'system', ['title' => '微信已绑定小程序工作台', 'body' => '现在可在小程序接收预约提醒并管理工作台']);
        return [true, '绑定成功，已进入咨询师工作台'];
    }

    public static function memberByToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$mid, $exp, $sig] = $parts;
        if (!ctype_digit($mid) || !ctype_digit($exp) || (int)$exp < time()) return null;
        if (!hash_equals(hash_hmac('sha256', $mid . '.' . $exp, (string)(CONFIG['secret_key'] ?? '')), $sig)) return null;
        // v1.1.0：退出登录黑名单（Cache 记到原过期时间为止）
        if (\app\Core\Cache::get('mp_tok_bl_' . hash('sha256', $token)) !== null) return null;
        $m = Database::fetch('SELECT * FROM members WHERE id=? AND status="active"', [(int)$mid]);
        return $m ?: null;
    }

    /** v1.1.0：退出登录——令牌拉黑到原有效期为止 */
    public static function revokeToken(string $token): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !ctype_digit($parts[1])) return;
        $ttl = max(60, min(30 * 86400, (int)$parts[1] - time()));
        \app\Core\Cache::set('mp_tok_bl_' . hash('sha256', $token), 1, $ttl);
    }

    /** 手机号绑定：短信验证码；openid/unionid 并入既有手机号会员（空壳占位号删除） */
    public static function bindPhone(int $memberId, string $phone, string $smsCode): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) return [false, '手机号格式不正确', 0];
        if (!(bool)setting('sms.code_enabled', '0')) return [false, '短信验证码未启用（系统设置→短信），请联系机构绑定', 0];
        $key = 'mp_bind:' . hash('sha256', $phone);
        $row = json_decode((string)Cache::get($key), true);
        if (!is_array($row) || !hash_equals((string)($row['code'] ?? ''), trim($smsCode))) {
            return [false, '验证码不正确或已过期', 0];
        }
        Cache::forget($key);
        $m = Database::fetch('SELECT * FROM members WHERE id=?', [$memberId]);
        if (!$m) return [false, '登录状态失效', 0];
        $exist = Database::fetch('SELECT id FROM members WHERE phone=? AND status="active"', [$phone]);
        if ($exist && (int)$exist['id'] !== $memberId) {
            Database::run('UPDATE members SET mp_openid=COALESCE(NULLIF(?,""), mp_openid), unionid=COALESCE(NULLIF(?,""), unionid) WHERE id=?',
                [(string)$m['mp_openid'], (string)$m['unionid'], (int)$exist['id']]);
            Database::run('DELETE FROM members WHERE id=? AND phone LIKE "mp\\_%"', [$memberId]);
            return [true, '绑定成功', (int)$exist['id']];
        }
        Database::run('UPDATE members SET phone=?, name=COALESCE(NULLIF(name,""), name) WHERE id=? AND phone LIKE "mp\\_%"', [$phone, $memberId]);
        return [true, '绑定成功', $memberId];
    }

    /** 发绑定验证码（复用 sms-notify 通道；60s 冷却） */
    public static function sendBindCode(string $phone): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) return [false, '手机号格式不正确'];
        if (!(bool)setting('sms.code_enabled', '0')) return [false, '短信验证码未启用（系统设置→短信）'];
        if (!RateLimiter::hit('mp_bindcode', $phone, 1, 60)) return [false, '发送太频繁，请 1 分钟后再试'];
        $code = (string)random_int(100000, 999999);
        Cache::set('mp_bind:' . hash('sha256', $phone), json_encode(['code' => $code, 'at' => time()]), 300);
        $site = (string)setting('site.name', '');
        $ok = \plugins\smsnotify\Plugin::sendMemberSms($phone, ['store' => str_take($site, 12, ''), 'event' => str_take('绑定验证码', 20, ''), 'time' => $code]) === true;
        return [$ok, $ok ? '验证码已发送（5 分钟内有效）' : '短信通道未就绪，请联系机构'];
    }

    // ==================== JSAPI 支付（appid 覆盖：小程序 openid 空间） ====================

    /** JSAPI 支付 appid：miniapp.pay_appid → wxpay.jsapi_appid → wxpay.appid */
    public static function jsapiAppid(): string
    {
        foreach (['miniapp.pay_appid', 'wxpay.jsapi_appid', 'wxpay.appid'] as $k) {
            $v = trim((string)setting($k, ''));
            if ($v !== '') return $v;
        }
        return '';
    }

    // ==================== 小程序包生成器 ====================

    /** 生成小程序源码 zip（站点名/主题色/API base 预置，微信开发者工具直接打开） */
    public static function buildZip(): string
    {
        if (!class_exists('ZipArchive')) return '';
        $srcDir = __DIR__ . '/mpsrc';
        $out = STORAGE . '/cache/miniapp-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) return '';
        $siteName = (string)setting('site.name', '');
        $theme = (string)setting('site.theme', 'healing');
        $themeMap = ['healing' => '#0d9488', 'violet-dusk' => '#7c6cf0', 'warm' => '#e07a5f', 'forest' => '#2f6b4f'];
        $color = $themeMap[$theme] ?? '#0d9488';
        $host = preg_replace('#^https?://#', '', rtrim(base_url(), '/'));
        $api = 'https://' . $host . '/plugin/miniapp-pro/api'; // 小程序合法域名只认 https；slug=插件真实目录
        $mpTitle = trim((string)setting('miniapp.title', '')) !== '' ? trim((string)setting('miniapp.title', '')) : $siteName;
        $mpAppid = self::jsapiAppid();
        // v1.1.1 防呆：AppID 未配置或站点域名异常（CLI localhost）时拒绝出包，避免生成串站/不可用包
        if ($mpAppid === '' || $host === '' || $host === 'localhost' || !filter_var('https://' . $host, FILTER_VALIDATE_URL)) {
            \app\Core\Logger::warning('miniapp-pro', "buildZip refused: appid=" . ($mpAppid === '' ? 'empty' : $mpAppid) . " host={$host}（请在站点配置 miniapp.pay_appid 与 site.domain 后重试）");
            return '';
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if (!$f->isFile()) continue;
            $rel = substr($f->getPathname(), strlen($srcDir) + 1);
            if ($rel === '' || str_starts_with($rel, '.')) continue;
            $content = file_get_contents($f->getPathname());
            $content = str_replace(
                ['__SITE_NAME__', '__THEME_COLOR__', '__API_BASE__', '__YEAR__', '__MP_APPID__', '__MP_TITLE__'],
                [$siteName, $color, $api, date('Y'), $mpAppid, $mpTitle],
                $content
            );
            $zip->addFromString($rel, $content);
        }
        $zip->close();
        return $out;
    }
}
