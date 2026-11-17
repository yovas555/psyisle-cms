<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Cache;
use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;

/** 健康检查（运维/监控探针，限流防探测） */
class Health
{
    public function check(Request $req, array $p): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if (!RateLimiter::hit('health', $req->ip(), 10, 60)) {
            http_response_code(429);
            echo '{"ok":false,"error":"rate limited"}';
            exit;
        }
        $dbOk = false;
        $queuePending = null;
        try {
            $dbOk = (bool)Database::value('SELECT 1');
            $queuePending = (int)Database::value("SELECT COUNT(*) FROM email_queue WHERE status='queued'");
        } catch (\Throwable $t) {
            $dbOk = false;
        }
        $cacheOk = (bool)Cache::remember('health_probe', 10, fn() => true);
        $diskFree = @disk_free_space(ROOT);
        $body = [
            'ok' => $dbOk && $cacheOk,
            'version' => CMS_VERSION,
            'db' => $dbOk,
            'cache' => $cacheOk,
            'mail_queue_pending' => $queuePending,
            'php' => PHP_VERSION,
        ];
        // 磁盘余量仅在告水位以下时暴露（避免信息枚举）
        if ($diskFree !== false && $diskFree < 1024 * 1024 * 1024) {
            $body['disk_warning'] = 'less than 1GB free';
        }
        http_response_code($body['ok'] ? 200 : 503);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
