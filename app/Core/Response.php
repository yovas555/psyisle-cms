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
        // v2.24.2：放开摄像头/麦克风给本站（音视频咨询必需）；其余保持禁用
        header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // script-src 的 blob: 供 RTC SDK；img-src 的 blob: 仅用于本地图片裁剪预览
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://g.alicdn.com https://cdnjs.cloudflare.com https://download.agora.io; worker-src 'self' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' https: blob:; connect-src 'self' https: wss:; frame-src https://player.bilibili.com https://v.qq.com; frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
        header_remove('X-Powered-By');
    }
}
