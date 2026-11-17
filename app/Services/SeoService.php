<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

/** SEO + GEO 资产生成（sitemap / robots / rss / llms.txt / JSON-LD） */
class SeoService
{
    /** 页面 meta 包（title/keywords/description/canonical） */
    public static function meta(string $title, string $desc = '', string $keywords = '', ?string $canonical = null, array $og = []): array
    {
        $site = (string)setting('site.name', '一二三');
        $fullTitle = $title === $site ? $site : $title . ' - ' . $site;
        return [
            'title' => $fullTitle,
            'keywords' => $keywords ?: (string)setting('seo.keywords', ''),
            'description' => str_take($desc, 300, ''),
            'canonical' => $canonical,
            'og' => $og,
        ];
    }

    /** Organization / ProfessionalService JSON-LD */
    public static function orgSchema(): array
    {
        $name = (string)setting('site.name', '');
        $addr = (string)setting('contact.address', '');
        $tel = (string)setting('contact.phone', '');
        $geo = [
            '@type' => 'ProfessionalService',
            '@id' => url('/') . '#org',
            'name' => $name,
            'url' => url('/'),
            'description' => (string)setting('seo.description', ''),
            'logo' => url('/assets/img/logo.png'),
        ];
        if ($tel) { $geo['telephone'] = $tel; }
        if ($addr) {
            $geo['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $addr,
                'addressLocality' => (string)setting('contact.city', ''), 'addressCountry' => 'CN'];
        }
        $hours = (string)setting('contact.hours', '');
        if ($hours) {
            $geo['openingHoursSpecification'] = ['@type' => 'OpeningHoursSpecification',
                'description' => $hours, 'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']];
        }
        return $geo;
    }

    /** Article JSON-LD */
    public static function articleSchema(array $a): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $a['title'],
            'description' => (string)($a['summary'] ?? ''),
            'datePublished' => date('c', strtotime((string)$a['published_at'])),
            'dateModified' => date('c', strtotime((string)($a['updated_at'] ?: $a['published_at']))),
            'author' => ['@type' => 'Person', 'name' => (string)($a['author'] ?: setting('site.name'))],
            'publisher' => ['@type' => 'Organization', 'name' => (string)setting('site.name')],
            'mainEntityOfPage' => url('/news/' . $a['slug']),
        ];
    }

    /** Person（咨询师）JSON-LD */
    public static function counselorSchema(array $c): array
    {
        $p = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $c['name'],
            'jobTitle' => (string)$c['title'],
            'url' => url('/counselors/' . $c['slug']),
            'description' => str_take((string)$c['introduction'], 200, ''),
        ];
        if ($c['avatar']) { $p['image'] = url($c['avatar']); }
        $spec = (string)$c['specialties'];
        if ($spec) { $p['knowsAbout'] = preg_split('/[、,，\s]+/u', $spec); }
        return $p;
    }

    /** FAQPage JSON-LD */
    public static function faqSchema(array $rows): array
    {
        $entities = [];
        foreach ($rows as $r) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $r['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string)$r['answer'])],
            ];
        }
        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
    }

    /** 面包屑 JSON-LD */
    public static function breadcrumbSchema(array $items): array
    {
        $entities = [];
        $i = 1;
        foreach ($items as $t => $u) {
            $entities[] = ['@type' => 'ListItem', 'position' => $i++, 'name' => $t, 'item' => $u];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $entities];
    }

    /** sitemap.xml 内容 */
    public static function sitemap(): string
    {
        $today = date('Y-m-d');
        $urls = [];
        $add = function ($loc, $pri = '0.7', $freq = 'weekly', $lastmod = null) use (&$urls, $today) {
            $lastmod = $lastmod ?: $today;
            $urls[] = compact('loc', 'pri', 'freq', 'lastmod');
        };
        $add(url('/'), '1.0', 'daily');
        $add(url('/news'), '0.9', 'daily');
        $add(url('/counselors'), '0.9', 'weekly');
        $add(url('/services'), '0.9', 'weekly');
        $add(url('/faq'), '0.6', 'weekly');
        $add(url('/booking'), '0.8', 'monthly');
        $add(url('/message'), '0.6', 'monthly');

        $pages = Database::fetchAll("SELECT slug, updated_at FROM pages WHERE status='published' AND deleted_at IS NULL");
        foreach ($pages as $p) { $add(url('/p/' . $p['slug']), '0.6', 'monthly', substr((string)$p['updated_at'], 0, 10)); }
        $arts = Database::fetchAll("SELECT slug, published_at, updated_at FROM articles WHERE status='published' AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 5000");
        foreach ($arts as $a) {
            $add(url('/news/' . $a['slug']), '0.8', 'weekly',
                substr((string)($a['updated_at'] ?: $a['published_at']), 0, 10));
        }
        $cs = Database::fetchAll('SELECT slug, updated_at FROM counselors WHERE status=1');
        foreach ($cs as $c) { $add(url('/counselors/' . $c['slug']), '0.7', 'monthly', substr((string)$c['updated_at'], 0, 10)); }
        $svs = Database::fetchAll('SELECT slug, updated_at FROM services WHERE status=1');
        foreach ($svs as $s) { $add(url('/services/' . $s['slug']), '0.7', 'monthly', substr((string)$s['updated_at'], 0, 10)); }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= "<url><loc>" . e($u['loc']) . "</loc><lastmod>{$u['lastmod']}</lastmod><changefreq>{$u['freq']}</changefreq><priority>{$u['pri']}</priority></url>\n";
        }
        $xml .= '</urlset>';
        return $xml;
    }

    /** robots.txt（支持后台自定义追加） */
    public static function robots(): string
    {
        $base = "User-agent: *\nDisallow: /admin\nDisallow: /admin/\nDisallow: /storage\nDisallow: /*?_token\n";
        $base .= "Allow: /\n\n";
        $base .= "Sitemap: " . url('/sitemap.xml') . "\n";
        $custom = (string)setting('seo.robots_extra', '');
        if ($custom) { $base .= "\n" . $custom . "\n"; }
        return $base;
    }

    /** llms.txt（GEO：供大模型引用的站点说明） */
    public static function llms(): string
    {
        $lines = [];
        $lines[] = '# ' . setting('site.name', '');
        $lines[] = '';
        $lines[] = '> ' . setting('seo.description', '');
        $lines[] = '';
        $lines[] = '本站为心理咨询机构官方网站。提供心理咨询预约、心理科普文章、咨询师团队介绍与常见问题解答。';
        $lines[] = '';
        $lines[] = '## 核心页面';
        $lines[] = '- [首页](' . url('/') . '): 机构总览';
        $lines[] = '- [咨询师团队](' . url('/counselors') . '): 资质、流派、擅长方向';
        $lines[] = '- [咨询服务](' . url('/services') . '): 项目、形式与收费';
        $lines[] = '- [预约咨询](' . url('/booking') . '): 在线预约表单';
        $lines[] = '- [常见问题](' . url('/faq') . '): 就诊流程与咨询答疑';
        $lines[] = '- [心理资讯](' . url('/news') . '): 心理科普与机构动态';
        $c = (string)setting('contact.phone', '');
        if ($c) { $lines[] = ''; $lines[] = '## 联系方式'; $lines[] = '- 电话: ' . $c; }
        $a = (string)setting('contact.address', '');
        if ($a) { $lines[] = '- 地址: ' . $a; }
        $h = (string)setting('contact.hours', '');
        if ($h) { $lines[] = '- 接待时间: ' . $h; }
        $lines[] = '';
        $lines[] = '## 重要提示';
        $lines[] = '- 心理咨询不等于医疗诊断，若有强烈困扰请通过预约咨询获得专业支持。';
        // 精选文章
        $arts = Database::fetchAll("SELECT title, slug, summary FROM articles WHERE status='published' AND deleted_at IS NULL AND is_top=1 ORDER BY published_at DESC LIMIT 10");
        if ($arts) {
            $lines[] = '';
            $lines[] = '## 重点文章';
            foreach ($arts as $a) {
                $lines[] = '- [' . $a['title'] . '](' . url('/news/' . $a['slug']) . '): ' . str_take((string)$a['summary'], 80, '');
            }
        }
        return implode("\n", $lines);
    }

    /** RSS 内容 */
    public static function rss(): string
    {
        $site = (string)setting('site.name', '');
        $rows = Database::fetchAll("SELECT title, slug, summary, published_at FROM articles WHERE status='published' AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 30");
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0"><channel><title>' . e($site) . '</title><link>' . e(url('/')) . '</link>'
            . '<description>' . e(setting('seo.description', '')) . '</description><language>zh-CN</language>';
        foreach ($rows as $r) {
            $xml .= '<item><title>' . e($r['title']) . '</title><link>' . e(url('/news/' . $r['slug'])) . '</link>'
                . '<guid>' . e(url('/news/' . $r['slug'])) . '</guid>'
                . '<description>' . e(str_take((string)$r['summary'], 200, '')) . '</description>'
                . '<pubDate>' . date('r', strtotime((string)$r['published_at'])) . '</pubDate></item>';
        }
        $xml .= '</channel></rss>';
        return $xml;
    }

    /** 百度主动推送（站长平台 token） */
    public static function pushBaidu(array $urls): array
    {
        $token = (string)setting('seo.baidu_token', '');
        $host = (string)setting('site.domain') ?: parse_url(base_url(), PHP_URL_HOST);
        if ($token === '' || !$host) return [false, '未配置百度推送 token 或站点域名'];
        $api = 'http://data.zz.baidu.com/urls?site=' . $host . '&token=' . $token;
        [$ok, $status, $body] = \app\Core\Http::post($api, implode("\n", $urls), ['Content-Type: text/plain'], 15);
        if (!$ok) return [false, '请求失败：' . $body];
        $data = json_decode($body, true);
        $success = (int)($data['success'] ?? 0);
        return [$success > 0 || $status < 400, '百度推送：成功 ' . $success . ' 条 ' . str_take($body, 80, '')];
    }

    /** IndexNow（Bing/Yandex/Seznam 通用） */
    public static function pushIndexNow(array $urls): array
    {
        $key = (string)setting('seo.indexnow_key', '');
        $host = (string)setting('site.domain') ?: parse_url(base_url(), PHP_URL_HOST);
        if ($key === '' || !$host) return [false, '未配置 IndexNow key 或站点域名'];
        $payload = json_encode([
            'host' => $host,
            'key' => $key,
            'urlList' => $urls,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$ok, $status, $body] = \app\Core\Http::post('https://api.indexnow.org/indexnow', $payload, ['Content-Type: application/json; charset=utf-8'], 15);
        return [$ok && $status >= 200 && $status < 300, 'IndexNow status=' . $status];
    }

    /** 站内最近更新 URL 列表（推送用） */
    public static function recentUrls(int $limit = 50): array
    {
        $urls = [url('/'), url('/counselors'), url('/services')];
        $rows = \app\Core\Database::fetchAll(
            "SELECT slug FROM articles WHERE status='published' AND deleted_at IS NULL ORDER BY published_at DESC LIMIT " . (int)$limit
        );
        foreach ($rows as $r) { $urls[] = url('/news/' . $r['slug']); }
        return $urls;
    }
}