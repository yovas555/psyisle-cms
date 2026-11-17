<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

/** 标签专题聚合页（长尾 SEO 引擎）：文章 + 咨询师 + 服务 + FAQ */
class Tag
{
    public function show(Request $req, array $p): void
    {
        StatsService::track();
        $tag = trim((string)$p['tag']);
        if ($tag === '' || mb_strlen($tag) > 30) { \app\Controllers\StaticPage::notFound($req, []); return; }
        if (!RateLimiter::hit('tag', $req->ip(), 20, 60)) { http_response_code(429); echo '频繁'; exit; }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $tag) . '%';

        $articles = Database::fetchAll(
            "SELECT title, slug, summary, published_at FROM articles
             WHERE status='published' AND deleted_at IS NULL AND (tags LIKE ? OR title LIKE ? OR summary LIKE ?)
             ORDER BY published_at DESC LIMIT 12",
            [$like, $like, $like]
        );
        $counselors = Database::fetchAll(
            "SELECT name, slug, title, specialties FROM counselors
             WHERE status=1 AND (specialties LIKE ? OR orientation LIKE ? OR tags LIKE ?) ORDER BY sort LIMIT 8",
            [$like, $like, $like]
        );
        $services = Database::fetchAll(
            "SELECT name, slug, summary FROM services
             WHERE status=1 AND (name LIKE ? OR summary LIKE ?) ORDER BY sort LIMIT 8",
            [$like, $like]
        );
        $faqs = Database::fetchAll(
            "SELECT question, answer FROM faqs WHERE status=1 AND (question LIKE ? OR answer LIKE ?) ORDER BY sort LIMIT 6",
            [$like, $like]
        );
        $total = count($articles) + count($counselors) + count($services) + count($faqs);
        if ($total === 0) { \app\Controllers\StaticPage::notFound($req, []); return; }

        $meta = SeoService::meta(
            $tag . ' · 专题',
            '关于「' . $tag . '」的文章科普、专业咨询师、相关服务与常见问题一站式聚合',
            $tag . ',心理咨询,' . $tag . '怎么办',
            url('/tag/' . urlencode($tag))
        );
        View::render('tag/show', [
            'meta' => $meta,
            'tag' => $tag,
            'articles' => $articles,
            'counselors' => $counselors,
            'services' => $services,
            'faqs' => $faqs,
            'total' => $total,
            'extraSchema' => ['tag_faq' => $faqs ? SeoService::faqSchema($faqs) : null],
            'crumbs' => ['首页' => url('/'), '专题' => null, $tag => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), $tag . ' 专题' => url('/tag/' . urlencode($tag))]),
        ]);
    }
}
