<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Model;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

class Article
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $page = max(1, (int)$req->get('page', 1));
        $data = Model::paginate('articles', [
            'where' => "status='published' AND deleted_at IS NULL",
            'order' => 'is_top DESC, published_at DESC',
            'page' => $page, 'size' => 12,
        ]);
        $cats = Database::fetchAll("SELECT * FROM categories WHERE type='article' AND status=1 AND pid=0 ORDER BY sort");
        $meta = SeoService::meta('心理资讯', '心理咨询知识科普、机构动态与活动通知', '心理咨询,心理科普,机构动态', url('/news'));
        View::render('article/index', [
            'meta' => $meta,
            'data' => $data,
            'cats' => $cats,
            'crumbs' => ['首页' => url('/'), '心理资讯' => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '心理资讯' => url('/news')]),
        ]);
    }

    public function category(Request $req, array $p): void
    {
        StatsService::track();
        $cat = Database::fetch("SELECT * FROM categories WHERE slug=? AND type IN ('article','news') AND status=1", [$p['slug']]);
        if (!$cat) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $page = max(1, (int)$req->get('page', 1));
        $data = Model::paginate('articles', [
            'where' => "status='published' AND deleted_at IS NULL AND category_id=?",
            'params' => [$cat['id']],
            'order' => 'is_top DESC, published_at DESC',
            'page' => $page, 'size' => 12,
        ]);
        $meta = SeoService::meta(
            $cat['seo_title'] ?: $cat['name'],
            $cat['seo_description'] ?: (string)$cat['description'],
            $cat['seo_keywords'] ?: '',
            url('/news/category/' . $cat['slug'])
        );
        View::render('article/index', [
            'meta' => $meta,
            'data' => $data,
            'cats' => Database::fetchAll("SELECT * FROM categories WHERE type='article' AND status=1 AND pid=0 ORDER BY sort"),
            'currentCat' => $cat,
            'crumbs' => ['首页' => url('/'), '心理资讯' => url('/news'), $cat['name'] => ''],
        ]);
    }

    public function show(Request $req, array $p): void
    {
        StatsService::track();
        $a = Database::fetch("SELECT * FROM articles WHERE slug=? AND status='published' AND deleted_at IS NULL", [$p['slug']]);
        if (!$a) { \app\Controllers\StaticPage::notFound($req, []); return; }

        Database::run('UPDATE articles SET views=views+1 WHERE id=?', [$a['id']]);
        $cat = null;
        if ($a['category_id']) {
            $cat = Database::fetch('SELECT * FROM categories WHERE id=?', [$a['category_id']]);
        }
        $prev = Database::fetch(
            "SELECT title, slug FROM articles WHERE status='published' AND deleted_at IS NULL AND published_at < ? ORDER BY published_at DESC LIMIT 1",
            [$a['published_at']]
        );
        $next = Database::fetch(
            "SELECT title, slug FROM articles WHERE status='published' AND deleted_at IS NULL AND published_at > ? ORDER BY published_at ASC LIMIT 1",
            [$a['published_at']]
        );
        $related = Database::fetchAll(
            "SELECT title, slug, summary, cover FROM articles WHERE status='published' AND deleted_at IS NULL AND id<>? AND category_id=? ORDER BY published_at DESC LIMIT 4",
            [$a['id'], $a['category_id']]
        );

        $meta = SeoService::meta(
            (string)($a['seo_title'] ?: $a['title']),
            (string)($a['seo_description'] ?: $a['summary']),
            (string)($a['seo_keywords'] ?: $a['tags']),
            url('/news/' . $a['slug'])
        );
        $meta['og'] = ['type' => 'article', 'image' => $a['cover'] ? url($a['cover']) : null];

        // 关键词自动内链（SEO 内链引擎：设置-SEO 配置「关键词|目标」每行一条）
        // 分段处理：仅对文本节点做替换（跳过 HTML 标签与既有 <a> 整体），每个关键词只链接首现
        $html = eraw($a['content']);
        $mapRaw = (string)setting('seo.autolink', '');
        if ($mapRaw !== '') {
            $pairs = [];
            foreach (preg_split('/\r\n|\r|\n/', $mapRaw) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '|') === false) continue;
                [$kw, $target] = array_map('trim', explode('|', $line, 2));
                if ($kw === '' || $target === '') continue;
                $pairs[$kw] = preg_match('#^https?://#', $target) ? $target : url($target);
            }
            if ($pairs) {
                $used = [];
                $html = preg_replace_callback('/<a\b[^>]*>.*?<\/a>|<[^>]+>|[^<]+/is', function ($m) use ($pairs, &$used) {
                    $seg = $m[0];
                    if ($seg !== '' && $seg[0] === '<') return $seg; // 标签/链接整体保留
                    foreach ($pairs as $kw => $href) {
                        if (isset($used[$kw])) continue;
                        $pos = mb_stripos($seg, $kw);
                        if ($pos === false) continue;
                        $len = mb_strlen($kw);
                        $seg = mb_substr($seg, 0, $pos)
                            . '<a href="' . e($href) . '" title="' . e($kw) . '">' . mb_substr($seg, $pos, $len) . '</a>'
                            . mb_substr($seg, $pos + $len);
                        $used[$kw] = true;
                        break;
                    }
                    return $seg;
                }, $html);
            }
        }
        $a['_content_html'] = $html;

        $catUrl = $cat ? url('/news/category/' . $cat['slug']) : url('/news');
        View::render('article/show', [
            'meta' => $meta,
            'a' => $a,
            'cat' => $cat,
            'prev' => $prev, 'next' => $next,
            'related' => $related,
            'crumbs' => ['首页' => url('/'), '心理资讯' => url('/news'), ($cat['name'] ?? null) => $catUrl, $a['title'] => ''],
            'articleSchema' => SeoService::articleSchema($a),
            'breadcrumbSchema' => SeoService::breadcrumbSchema(
                array_filter(['首页' => url('/'), '心理资讯' => url('/news')] + ($cat ? [$cat['name'] => $catUrl] : []) + [$a['title'] => url('/news/' . $a['slug'])])
            ),
        ]);
    }
}
