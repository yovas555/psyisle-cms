<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Request;
use app\Core\View;
use app\Services\LicenseService;
use app\Services\SeoService;

/** SEO 动态资产 + 错误页 + 维护页 */
class StaticPage
{
    public function robots(Request $req, array $p): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo SeoService::robots();
    }

    public function sitemap(Request $req, array $p): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        echo \app\Core\Cache::remember('sitemap_xml', 600, fn() => SeoService::sitemap());
    }

    public function rss(Request $req, array $p): void
    {
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        echo \app\Core\Cache::remember('rss_xml', 600, fn() => SeoService::rss());
    }

    public function llms(Request $req, array $p): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        echo \app\Core\Cache::remember('llms_txt', 600, fn() => SeoService::llms());
    }

    /** IndexNow key 验证文件（key 与后台 seo.indexnow_key 一致才返回） */
    public function indexNowKey(Request $req, array $p): void
    {
        $key = (string)setting('seo.indexnow_key', '');
        if ($key !== '' && hash_equals($key, strtolower((string)$p['keytxt']))) {
            header('Content-Type: text/plain');
            echo $key;
        } else {
            http_response_code(404);
            echo 'not found';
        }
        exit;
    }

    public function favicon(Request $req, array $p): void
    {
        $file = PUBLIC_PATH . '/assets/img/favicon.ico';
        if (is_file($file)) {
            header('Content-Type: image/x-icon');
            header('Cache-Control: public, max-age=86400');
            readfile($file);
        } else {
            http_response_code(204);
        }
        exit;
    }

    public static function notFound(Request $req, array $p): void
    {
        http_response_code(404);
        $meta = ['title' => '页面未找到', 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => []];
        View::render('error', ['meta' => $meta, 'code' => 404, 'msg' => '页面不存在或已下线，您可以返回首页继续浏览。'], 'layout');
    }
}
