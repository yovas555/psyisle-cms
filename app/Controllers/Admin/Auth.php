<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Cache;
use app\Core\Captcha;
use app\Core\Database;
use app\Core\Logger;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\Session;
use app\Core\View;
use app\Services\NotifyService;

class Auth
{
    public function showLogin(Request $req, array $p): void
    {
        if (Session::get('admin_user')) { header('Location: /admin'); exit; }
        $error = Session::get('_login_error');
        Session::forget('_login_error');
        header('X-Robots-Tag: noindex, nofollow');
        View::admin('login', ['error' => $error], true);
    }

    public function login(Request $req, array $p): void
    {
        \app\Core\Csrf::guard();
        $ip = $req->ip();
        // IP 限流：10 分钟 20 次
        if (!RateLimiter::hit('login', $ip, 20, 600)) {
            $this->fail($req, '尝试过于频繁，请 10 分钟后再试');
            return;
        }
        // 全局限流（防分布式撞库）：全站 15 分钟内失败 > 60 次则暂停登录 10 分钟
        if ((int)(Cache::get('login_global_fail') ?? 0) >= 60) {
            $this->fail($req, '系统检测到异常登录尝试，登录入口已临时暂停 10 分钟，请稍后再试');
            return;
        }
        $username = trim((string)$req->post('username', ''));
        $password = (string)$req->post('password', '');
        if ($username === '' || $password === '') {
            $this->fail($req, '请输入用户名和密码');
            return;
        }
        if (!Captcha::check((string)$req->post('captcha', ''))) {
            $this->fail($req, '验证码不正确');
            return;
        }
        $u = Database::fetch('SELECT * FROM users WHERE username=? AND status=1', [$username]);
        $lockMsg = '';
        if ($u) {
            if ($u['locked_until'] && strtotime((string)$u['locked_until']) > time()) {
                $this->fail($req, '账号已临时锁定（连续失败过多），请 15 分钟后再试');
                return;
            }
            if (password_verify($password, (string)$u['password_hash'])) {
                // 已开启两步验证 → 进入验证阶段
                if (!empty($u['totp_secret'])) {
                    Session::set('totp_pending', ['id' => $u['id'], 'username' => $u['username'],
                        'display_name' => $u['display_name'] ?: $u['username'], 'role_id' => $u['role_id']]);
                    header('Location: /admin/totp'); exit;
                }
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
                // v2.11.0：演示模式——role_id=6（演示管理员）的账号只读
                if ((int)($u['role_id'] ?? 0) === 6) { Session::set('admin_demo', true); }
                session_regenerate_id(true);
                $this->auditLog($u, $ip, 'login.success');
                // v2.20.2：咨询师身份识别——绑定 counselors.user_id 的账号直进咨询师工作台
                $cslBind = Database::value('SELECT id FROM counselors WHERE user_id=? AND status=1', [(int)$u['id']]);
                if ($cslBind) { header('Location: /admin/counselor-home'); exit; }
                header('Location: /admin'); exit;
            }
            // 失败计数
            $failed = (int)$u['failed_count'] + 1;
            $upd = ['failed_count' => $failed];
            if ($failed >= 5) { $upd['locked_until'] = date('Y-m-d H:i:s', time() + 900); $lockMsg = '（已触发锁定）'; }
            Database::update('users', $upd, 'id=?', [$u['id']]);
            if ($failed >= 3) {
                NotifyService::push('login_alert', '后台登录连续失败告警',
                    sprintf('账号 %s 连续 %d 次密码错误%s，IP：%s', $u['username'], $failed, $lockMsg, $ip));
            }
        }
        Logger::info('auth', 'login failed', ['username' => $username, 'ip' => $ip]);
        $gf = (int)(Cache::get('login_global_fail') ?? 0) + 1;
        Cache::set('login_global_fail', $gf, 900);
        $this->fail($req, '用户名或密码不正确' . $lockMsg);
    }

    private function fail(Request $req, string $msg): void
    {
        Session::set('_login_error', $msg);
        header('Location: /admin/login');
        exit;
    }

    private function auditLog(array $u, string $ip, string $action): void
    {
        try {
            Database::insert('audit_logs', [
                'user_id' => $u['id'], 'username' => $u['username'], 'action' => $action,
                'ip' => $ip, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {}
    }

    public function logout(Request $req, array $p): void
    {
        Session::forget('admin_user');
        header('Location: /admin/login');
        exit;
    }

    /** TOTP 第二步验证页 */
    public function showTotp(Request $req, array $p): void
    {
        $pending = Session::get('totp_pending');
        if (!$pending) { header('Location: /admin/login'); exit; }
        View::admin('totp', ['error' => Session::get('_totp_error')], true);
        Session::forget('_totp_error');
    }

    public function verifyTotp(Request $req, array $p): void
    {
        \app\Core\Csrf::guard();
        $pending = Session::get('totp_pending');
        if (!$pending) { header('Location: /admin/login'); exit; }
        $code = (string)$req->post('code', '');
        $u = Database::fetch('SELECT * FROM users WHERE id=? AND status=1', [$pending['id']]);
        if (!$u || empty($u['totp_secret']) || !\app\Core\Totp::verify((string)$u['totp_secret'], $code)) {
            Logger::info('auth', 'totp failed', ['username' => $u['username'] ?? '?']);
            Session::set('_totp_error', '验证码不正确或已过期');
            header('Location: /admin/totp'); exit;
        }
        Session::forget('totp_pending');
        Database::update('users', [
            'failed_count' => 0, 'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $req->ip(),
        ], 'id=?', [$u['id']]);
        Session::set('admin_user', [
            'id' => $u['id'], 'username' => $u['username'],
            'display_name' => $u['display_name'] ?: $u['username'], 'role_id' => $u['role_id'],
        ]);
        Session::set('admin_last_active', time());
        Session::set('admin_ua', hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
        session_regenerate_id(true);
        $this->auditLog($u, $req->ip(), 'login.success.totp');
        header('Location: /admin'); exit;
    }

    /** 图形验证码（前后台共用，无需登录） */
    public function captcha(Request $req, array $p): void
    {
        Captcha::make();
    }
}
