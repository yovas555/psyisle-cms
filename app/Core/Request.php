<?php
declare(strict_types=1);

namespace app\Core;

class Request
{
    /** 解析后的路径（不含 query） */
    public function uri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        // 去除入口名
        $path = preg_replace('#^/index\.php#', '', $path) ?: '/';
        $path = '/' . trim($path, '/');
        if ($path === '//' ) $path = '/';
        return $path;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool { return $this->method() === 'POST'; }

    public function get(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public function post(string $key, $default = null)
    {
        $v = $_POST[$key] ?? $default;
        if (is_string($v)) { $v = trim($v); }
        return $v;
    }

    public function postRaw(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    public function posts(): array { return $_POST; }

    public function ip(): string
    {
        // 宝塔/nginx 常规：REMOTE_ADDR 为真实来源；不接受不可信 XFF 链
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { $ip = '0.0.0.0'; }
        return $ip;
    }

    public function userAgent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public function host(): string
    {
        $h = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        return preg_replace('/[^a-z0-9\.\-:]/i', '', strtolower($h));
    }

    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!$f || ($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) return null;
        return $f;
    }
}
