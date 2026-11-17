<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

class Page
{
    public function about(Request $req, array $p): void
    {
        $this->renderSlug($req, 'about');
    }

    public function contact(Request $req, array $p): void
    {
        $this->renderSlug($req, 'contact');
    }

    public function faq(Request $req, array $p): void
    {
        StatsService::track();
        $rows = Database::fetchAll('SELECT * FROM faqs WHERE status=1 ORDER BY sort, id LIMIT 60');
        $meta = SeoService::meta(
            '常见问题',
            '关于心理咨询流程、收费、保密等常见问题解答',
            '心理咨询常见问题,保密原则,咨询流程',
            url('/faq')
        );
        View::render('page/faq', [
            'meta' => $meta,
            'rows' => $rows,
            'crumbs' => ['首页' => url('/'), '常见问题' => ''],
            'faqSchema' => $rows ? SeoService::faqSchema($rows) : null,
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '常见问题' => url('/faq')]),
        ]);
    }

    public function show(Request $req, array $p): void
    {
        $this->renderSlug($req, $p['slug']);
    }

    private function renderSlug(Request $req, string $slug): void
    {
        StatsService::track();
        $page = Database::fetch("SELECT * FROM pages WHERE slug=? AND status='published' AND deleted_at IS NULL", [$slug]);
        if (!$page) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $meta = SeoService::meta(
            (string)($page['seo_title'] ?: $page['title']),
            (string)($page['seo_description'] ?: str_take(strip_tags((string)$page['content']), 200, '')),
            (string)($page['seo_keywords'] ?: ''),
            url('/p/' . $page['slug'])
        );
        View::render('page/show', [
            'meta' => $meta,
            'page' => $page,
            'crumbs' => ['首页' => url('/'), $page['title'] => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), $page['title'] => url('/p/' . $page['slug'])]),
        ]);
    }
}
