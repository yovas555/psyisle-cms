<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

class Search
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $q = trim((string)$req->get('q', ''));
        $results = [];
        $total = 0;
        if ($q !== '') {
            if (!RateLimiter::hit('search', $req->ip(), 30, 60)) {
                $results = ['error' => '搜索过于频繁'];
            } elseif (mb_strlen($q) > 50) {
                $results = ['error' => '关键词过长'];
            } else {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $results['articles'] = Database::fetchAll(
                    "SELECT title, slug, summary, published_at FROM articles
                     WHERE status='published' AND deleted_at IS NULL AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)
                     ORDER BY published_at DESC LIMIT 20",
                    [$like, $like, $like]
                );
                $results['counselors'] = Database::fetchAll(
                    "SELECT name, slug, title, specialties FROM counselors
                     WHERE status=1 AND (name LIKE ? OR title LIKE ? OR specialties LIKE ? OR introduction LIKE ?)
                     ORDER BY sort LIMIT 10",
                    [$like, $like, $like, $like]
                );
                $results['services'] = Database::fetchAll(
                    "SELECT name, slug, summary FROM services
                     WHERE status=1 AND (name LIKE ? OR summary LIKE ? OR content LIKE ?) ORDER BY sort LIMIT 10",
                    [$like, $like, $like]
                );
                $results['faqs'] = Database::fetchAll(
                    'SELECT question, answer FROM faqs WHERE status=1 AND (question LIKE ? OR answer LIKE ?) ORDER BY sort LIMIT 10',
                    [$like, $like]
                );
                $total = count($results['articles']) + count($results['counselors'])
                    + count($results['services']) + count($results['faqs']);
            }
        }
        $meta = SeoService::meta('站内搜索' . ($q !== '' ? '：' . $q : ''), '', '', url('/search?q=' . urlencode($q)));
        View::render('search/index', [
            'meta' => $meta,
            'q' => $q,
            'results' => $results,
            'total' => $total,
            'noindex' => true,
            'crumbs' => ['首页' => url('/'), '搜索' => ''],
        ]);
    }
}
