<?php
declare(strict_types=1);

namespace app\Core;

class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(20));
        }
        return $_SESSION['_csrf'];
    }

    public static function check(?string $token = null): bool
    {
        Session::start();
        $token = $token ?? ($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return is_string($token) && $token !== ''
            && hash_equals((string)($_SESSION['_csrf'] ?? ''), $token);
    }

    /** POST 校验，失败直接 419 */
    public static function guard(): void
    {
        if (!self::check()) {
            json_out(['ok' => false, 'msg' => '页面已过期，请刷新后重试（CSRF 校验失败）'], 419);
        }
    }
}
