<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;

/**
 * JSON API v1（只读，面向未来：小程序 / 公众号 / 第三方集成）
 * 鉴权：Authorization: Bearer <token> 或 ?token=；token 在后台系统设置-安全页生成
 * 资源：articles / counselors / services / events / faqs
 */
class Api
{
    private function auth(Request $req): bool
    {
        $expect = (string)setting('api.token', '');
        if ($expect === '') return false;
        $given = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $given, $m)) {
            $given = trim($m[1]);
        } else {
            $given = (string)$req->get('token', '');
        }
        return $given !== '' && hash_equals($expect, $given);
    }

    public function handle(Request $req, array $p): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if (!$this->auth($req)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!RateLimiter::hit('api', $req->ip(), 60, 60)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate limited'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $resource = (string)($p['resource'] ?? '');
        $page = max(1, (int)$req->get('page', 1));
        $size = min(50, max(1, (int)$req->get('size', 20)));
        $offset = ($page - 1) * $size;

        switch ($resource) {
            case 'articles':
                $total = (int)Database::value("SELECT COUNT(*) FROM articles WHERE status='published' AND deleted_at IS NULL");
                $rows = Database::fetchAll(
                    "SELECT title, slug, summary, author, tags, published_at FROM articles
                     WHERE status='published' AND deleted_at IS NULL ORDER BY published_at DESC LIMIT $size OFFSET $offset"
                );
                break;
            case 'counselors':
                // 注意隐私：不含联系方式类字段
                $total = (int)Database::value('SELECT COUNT(*) FROM counselors WHERE status=1');
                $rows = Database::fetchAll(
                    "SELECT id, name, slug, title, orientation, specialties, fee_from, fee_to, credentials, supervision, audiences, unsuitable_topics, personal_therapy FROM counselors
                     WHERE status=1 ORDER BY sort LIMIT $size OFFSET $offset"
                );
                break;
            case 'services':
                $total = (int)Database::value('SELECT COUNT(*) FROM services WHERE status=1');
                $rows = Database::fetchAll(
                    "SELECT name, slug, summary, format, duration, price_from, price_to, price_note FROM services
                     WHERE status=1 ORDER BY sort LIMIT $size OFFSET $offset"
                );
                break;
            case 'events':
                $total = (int)Database::value("SELECT COUNT(*) FROM events WHERE status IN ('published','full')");
                $rows = Database::fetchAll(
                    "SELECT title, slug, summary, event_date, location, fee, capacity, status FROM events
                     WHERE status IN ('published','full') AND (event_date IS NULL OR event_date >= DATE_SUB(NOW(), INTERVAL 1 DAY))
                     ORDER BY event_date ASC LIMIT $size OFFSET $offset"
                );
                break;
            case 'faqs':
                $total = (int)Database::value('SELECT COUNT(*) FROM faqs WHERE status=1');
                $rows = Database::fetchAll(
                    'SELECT question, answer FROM faqs WHERE status=1 ORDER BY sort LIMIT ' . min(60, $size) . " OFFSET $offset"
                );
                break;
            case 'assessments':
                $total = (int)Database::value('SELECT COUNT(*) FROM assessments WHERE status=1');
                $rows = Database::fetchAll(
                    'SELECT slug, name, description FROM assessments WHERE status=1 ORDER BY sort LIMIT ' . min(20, $size) . " OFFSET $offset"
                );
                break;
            default:
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'unknown resource'], JSON_UNESCAPED_UNICODE);
                exit;
        }
        $latest = $resource === 'counselors' ? \app\Services\CounselorTrust::latestForMany($rows) : [];
        echo json_encode([
            'ok' => true,
            'resource' => $resource,
            'page' => $page,
            'size' => $size,
            'total' => $total,
            'data' => array_map(function ($r) use ($latest, $resource) {
                if ($resource === 'counselors') {
                    $trust = \app\Services\CounselorTrust::publicProfile($r, $latest[(int)$r['id']] ?? []);
                    $r['service_boundaries'] = ['audiences' => $trust['audiences'], 'unsuitable_topics' => $trust['unsuitable_topics']];
                    $r['trust'] = $trust['verifications'];
                    unset($r['personal_therapy']);
                }
                foreach ($r as $k => $v) { if (is_string($v)) { $r[$k] = strip_tags($v); } }
                return $r;
            }, $rows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
