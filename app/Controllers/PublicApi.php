<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;

/**
 * 公开只读 JSON API（v2.18 · AI Agent 就绪）
 * ------------------------------------------------------------------
 * 开关：settings api.public_enabled=1 才启用（默认关）。
 * 设计原则：
 *  - 只读、无鉴权（站点公开信息本就公开）、限流 60 次/分钟/IP
 *  - 脱敏：不输出手机号/邮箱/内部字段/未发布内容；联系电话只用公开设置
 *  - ETag（sha1 of body）→ 304 协商缓存，省带宽也给 agent 友好缓存语义
 *  - OpenAPI 3.1 规范自描述（/api/public/openapi.json）
 */
class PublicApi
{
    public function dispatch(Request $req, array $p): void
    {
        if (!(bool)setting('api.public_enabled', '0')) {
            $this->json(['ok' => false, 'error' => 'not_enabled'], 404);
        }
        if (!RateLimiter::hit('public_api', $req->ip(), 60, 60)) {
            $this->json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $path = (string)($p['path'] ?? '');
        $this->json($this->serve($path));
    }

    private function serve(string $path): array
    {
        switch ($path) {
            case 'site': return $this->site();
            case 'services': return ['services' => $this->services()];
            case 'counselors': return ['counselors' => $this->counselors()];
            case 'faq': return ['faq' => $this->faq()];
            case 'openapi': return ['openapi' => $this->openapi()];
            default:
                http_response_code(404);
                return ['ok' => false, 'error' => 'unknown_endpoint'];
        }
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *'); // 公开只读数据，允许跨域供 agent 网页端调用
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($body) . '"';
        header('ETag: ' . $etag);
        if ($status === 200 && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        echo $body;
        exit;
    }

    private function site(): array
    {
        return [
            'ok' => true,
            'name' => (string)setting('site.name', ''),
            'description' => (string)setting('seo.description', ''),
            'phone' => (string)setting('contact.phone', ''),      // 公开联系电话
            'address' => (string)setting('contact.address', ''),
            'hours' => (string)setting('contact.hours', ''),
            'booking_url' => url('/booking'),
            'llms_txt' => url('/llms.txt'),
            'openapi' => url('/api/public/openapi.json'),
            'mcp_enabled' => (bool)setting('mcp_enabled', '0'),
            'mcp_endpoint' => (bool)setting('mcp_enabled', '0') ? url('/mcp') : null,
            'crisis_hotline' => '12356',
        ];
    }

    private function services(): array
    {
        $rows = Database::fetchAll("SELECT name, slug, summary, price_from, price_to, format, duration FROM services WHERE status=1 ORDER BY sort LIMIT 50");
        return array_map(fn($s) => [
            'name' => (string)$s['name'],
            'url' => url('/services/' . $s['slug']),
            'summary' => str_take((string)$s['summary'], 200, ''),
            'price_from' => (float)$s['price_from'] > 0 ? (float)$s['price_from'] : null,
            'price_to' => (float)$s['price_to'] > 0 ? (float)$s['price_to'] : null,
            'format' => (string)($s['format'] ?? ''),
            'duration' => (string)($s['duration'] ?? ''),
        ], $rows);
    }

    private function counselors(): array
    {
        $rows = Database::fetchAll("SELECT name, slug, title, specialties, introduction FROM counselors WHERE status=1 ORDER BY sort LIMIT 50");
        return array_map(fn($c) => [
            'name' => (string)$c['name'],
            'title' => (string)$c['title'],
            'url' => url('/counselors/' . $c['slug']),
            'specialties' => (string)$c['specialties'],
            'introduction' => str_take(strip_tags((string)$c['introduction']), 300, ''),
        ], $rows);
    }

    private function faq(): array
    {
        $rows = Database::fetchAll("SELECT question, answer FROM faqs WHERE status=1 ORDER BY sort LIMIT 30");
        return array_map(fn($r) => [
            'question' => (string)$r['question'],
            'answer' => str_take(strip_tags((string)$r['answer']), 300, ''),
        ], $rows);
    }

    private function openapi(): array
    {
        $base = rtrim(base_url(), '/');
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string)setting('site.name', '') . ' 公开信息 API',
                'version' => '1.0.0',
                'description' => '心理咨询机构公开只读信息（服务/咨询师/FAQ/站点信息）。预约请走 /booking 或 MCP server（如启用）。禁止抓取会员个人数据。',
            ],
            'servers' => [['url' => $base]],
            'paths' => [
                '/api/public/site' => ['get' => ['summary' => '机构信息', 'responses' => ['200' => ['description' => 'ok']]]],
                '/api/public/services' => ['get' => ['summary' => '咨询服务列表', 'responses' => ['200' => ['description' => 'ok']]]],
                '/api/public/counselors' => ['get' => ['summary' => '咨询师团队', 'responses' => ['200' => ['description' => 'ok']]]],
                '/api/public/faq' => ['get' => ['summary' => '常见问题', 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ];
    }
}
