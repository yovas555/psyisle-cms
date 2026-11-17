<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

/** 公开问答（精选留言，匿名化展示）—— 内容 SEO 引擎 */
class Qa
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        // 精选留言：question 在 content 的「问：」段，答在「答：」段
        $rows = Database::fetchAll(
            "SELECT id, content, created_at FROM messages WHERE is_featured=1 AND status='closed' ORDER BY id DESC LIMIT 50"
        );
        $qas = [];
        $seen = [];
        foreach ($rows as $r) {
            $key = md5((string)$r['content']);
            if (isset($seen[$key])) continue; // 防重复展示
            $seen[$key] = true;
            $q = '';
            $a = '';
            if (preg_match('/问：\s*(.+?)\s*\n+答：\s*(.+)/s', (string)$r['content'], $m)) {
                $q = trim($m[1]);
                $a = trim($m[2]);
            } else {
                $q = str_take((string)$r['content'], 60, '…');
            }
            $qas[] = ['id' => $r['id'], 'q' => $q, 'a' => $a, 'time' => $r['created_at']];
        }
        $meta = SeoService::meta(
            '心理咨询问答精选',
            '会员与访客最关心的咨询问题与专业回答：保密原则、费用、咨询过程、效果预期等',
            '心理咨询问答,保密,咨询流程,咨询费用',
            url('/qa')
        );
        View::render('qa/index', [
            'meta' => $meta,
            'qas' => $qas,
            'extraSchema' => ['qa_faq' => $qas ? SeoService::faqSchema(array_map(fn($x) => ['question' => $x['q'], 'answer' => $x['a']], $qas)) : null],
            'crumbs' => ['首页' => url('/'), '问答精选' => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '问答精选' => url('/qa')]),
        ]);
    }
}
