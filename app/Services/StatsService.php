<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

/** 自托管访问统计（隐私优先：IP 哈希、无第三方） */
class StatsService
{
    public static function track(): void
    {
        if ((bool)setting('stats.enabled', '1') === false) return;
        // 只统计页面浏览（跳过资源/后端请求）
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/admin') === 0 || preg_match('#\.(png|jpg|css|js|ico|woff2?)($|\?)#i', $uri)) return;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipHash = md5($ip . (CONFIG['stats_salt'] ?? 'psyisle-salt'));
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190);
        $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
        try {
            Database::insert('visit_logs', [
                'ip_hash' => $ipHash,
                'path' => substr(parse_url($uri, PHP_URL_PATH) ?: '/', 0, 250),
                'referer_host' => substr((string)$ref, 0, 110),
                'ua' => $ua,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            // 采样清理：90 天前日志
            if (random_int(0, 100) === 0) {
                Database::run('DELETE FROM visit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
            }
        } catch (\Throwable $t) { /* 统计失败不影响业务 */ }
    }

    /** 日聚合（cron 调用） */
    public static function aggregate(): array
    {
        $dates = Database::fetchAll(
            'SELECT DATE(created_at) d, COUNT(*) pv, COUNT(DISTINCT ip_hash) uv
             FROM visit_logs GROUP BY DATE(created_at)
             HAVING d >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'
        );
        foreach ($dates as $d) {
            $topPages = Database::fetchAll(
                'SELECT path, COUNT(*) c FROM visit_logs WHERE DATE(created_at)=? GROUP BY path ORDER BY c DESC LIMIT 10',
                [$d['d']]
            );
            $topSources = Database::fetchAll(
                "SELECT IFNULL(referer_host,'(直接访问)') src, COUNT(*) c FROM visit_logs
                 WHERE DATE(created_at)=? GROUP BY src ORDER BY c DESC LIMIT 10",
                [$d['d']]
            );
            Database::run(
                'INSERT INTO visit_stats (date, pv, uv, top_pages, top_sources) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE pv=VALUES(pv), uv=VALUES(uv), top_pages=VALUES(top_pages), top_sources=VALUES(top_sources)',
                [$d['d'], $d['pv'], $d['uv'],
                 json_encode($topPages, JSON_UNESCAPED_UNICODE),
                 json_encode($topSources, JSON_UNESCAPED_UNICODE)]
            );
            // 聚合后清理该日明细
            Database::run('DELETE FROM visit_logs WHERE DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)');
        }
        return ['days' => count($dates)];
    }

    public static function recent(int $days = 7): array
    {
        return Database::fetchAll(
            'SELECT date, pv, uv FROM visit_stats WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY date ASC',
            [$days]
        );
    }
}
