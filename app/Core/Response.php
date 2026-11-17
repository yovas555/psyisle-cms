<?php
declare(strict_types=1);

namespace app\Core;

class Response
{
    public function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        self::emitSecurityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
    }

    public function redirect(string $to, int $status = 302): void
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    /** 全站安全响应头（index.php 入口统一发射） */
    public static function emitSecurityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 1; mode=block');
        header('Cache-Control: no-cache');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // 宽松 CSP：脚本限本站与内联（旧模板含内联），禁止任意 frame/对象/引用errer
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://g.alicdn.com https://cdnjs.cloudflare.com https://download.agora.io; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; media-src 'self' https: blob:; frame-src https://player.bilibili.com https://v.qq.com; frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
        header_remove('X-Powered-By');
    }
}
