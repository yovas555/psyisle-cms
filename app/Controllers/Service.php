<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

class Service
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $rows = Database::fetchAll('SELECT * FROM services WHERE status=1 ORDER BY is_featured DESC, sort, id');
        $meta = SeoService::meta(
            '咨询服务',
            '个体心理咨询、伴侣咨询、家庭咨询等服务项目，形式与收费说明',
            '心理咨询,个体咨询,伴侣咨询,家庭咨询',
            url('/services')
        );
        View::render('service/index', [
            'meta' => $meta,
            'rows' => $rows,
            'crumbs' => ['首页' => url('/'), '咨询服务' => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '咨询服务' => url('/services')]),
        ]);
    }

    public function show(Request $req, array $p): void
    {
        StatsService::track();
        $s = Database::fetch('SELECT * FROM services WHERE slug=? AND status=1', [$p['slug']]);
        if (!$s) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $meta = SeoService::meta(
            (string)($s['seo_title'] ?: $s['name']),
            (string)($s['seo_description'] ?: $s['summary']),
            (string)($s['seo_keywords'] ?: ''),
            url('/services/' . $s['slug'])
        );
        View::render('service/show', [
            'meta' => $meta,
            's' => $s,
            'counselors' => Database::fetchAll('SELECT name, slug, title FROM counselors WHERE status=1 ORDER BY sort LIMIT 6'),
            'crumbs' => ['首页' => url('/'), '咨询服务' => url('/services'), $s['name'] => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '咨询服务' => url('/services'), $s['name'] => url('/services/' . $s['slug'])]),
        ]);
    }
}
