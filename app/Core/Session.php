<?php
declare(strict_types=1);

namespace app\Core;

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) return;
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        self::$started = true;
        // 后台空闲超时
        if (isset($_SESSION['admin_user'])) {
            // 会话绑定：UA 指纹变化（疑似会话劫持）强制重新登录
            $ua = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            if (!empty($_SESSION['admin_ua']) && !hash_equals((string)$_SESSION['admin_ua'], $ua)) {
                Logger::error('session', 'admin session UA mismatch - forced logout');
                unset($_SESSION['admin_user'], $_SESSION['admin_ua']);
            }
            $idle = (int)setting('security.admin_idle_timeout', 7200);
            $last = $_SESSION['admin_last_active'] ?? time();
            if (time() - $last > $idle) {
                unset($_SESSION['admin_user']);
            }
            $_SESSION['admin_last_active'] = time();
        }
    }

    public static function set(string $k, $v): void
    {
        self::start();
        $_SESSION[$k] = $v;
    }

    public static function get(string $k, $default = null)
    {
        self::start();
        return $_SESSION[$k] ?? $default;
    }

    public static function forget(string $k): void
    {
        self::start();
        unset($_SESSION[$k]);
    }

    /** 一次性闪存消息 */
    public static function flash(string $type, string $msg): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
    }

    public static function takeFlashes(): array
    {
        self::start();
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
