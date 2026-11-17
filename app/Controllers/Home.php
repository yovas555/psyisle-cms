<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

class Home
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        // 首页数据缓存 300s（后台保存内容后最长 5 分钟生效）
        $data = \app\Core\Cache::remember('home_data', 300, function () {
            return $this->homeData();
        });
        [$banners, $featured, $services, $news, $faqs, $voices, $about, $events] = $data;

        $meta = SeoService::meta(
            (string)setting('site.name', '首页'),
            (string)setting('seo.description', ''),
            (string)setting('seo.keywords', ''),
            url('/')
        );
        View::render('home/index', [
            'meta' => $meta,
            'banners' => $banners,
            'featured' => $featured,
            'services' => $services,
            'news' => $news,
            'faqs' => $faqs,
            'voices' => $voices,
            'about' => $about,
            'events' => $events,
            'orgSchema' => SeoService::orgSchema(),
            'faqSchema' => $faqs ? SeoService::faqSchema($faqs) : null,
        ]);
    }

    private function homeData(): array
    {
        $banners = Database::fetchAll("SELECT * FROM banners WHERE position='home' AND status=1 ORDER BY sort LIMIT 5");
        $featured = Database::fetchAll(
            "SELECT name, slug, title, avatar, specialties, orientation FROM counselors WHERE status=1 AND is_featured=1 ORDER BY sort LIMIT 4"
        );
        if (!$featured) {
            $featured = Database::fetchAll(
                "SELECT name, slug, title, avatar, specialties, orientation FROM counselors WHERE status=1 ORDER BY sort LIMIT 4"
            );
        }
        $services = Database::fetchAll("SELECT name, slug, summary, format, price_from, price_to, cover FROM services WHERE status=1 AND is_featured=1 ORDER BY sort LIMIT 4");
        if (!$services) {
            $services = Database::fetchAll("SELECT name, slug, summary, format, price_from, price_to, cover FROM services WHERE status=1 ORDER BY sort LIMIT 4");
        }
        $news = Database::fetchAll(
            "SELECT id, title, slug, summary, cover, published_at FROM articles WHERE status='published' AND deleted_at IS NULL ORDER BY is_top DESC, published_at DESC LIMIT 6"
        );
        $faqs = Database::fetchAll("SELECT question, answer FROM faqs WHERE status=1 ORDER BY sort LIMIT 6");
        $voices = Database::fetchAll("SELECT alias, content, meta FROM testimonials WHERE status=1 ORDER BY sort, id LIMIT 6");
        $events = Database::fetchAll(
            "SELECT title, slug, event_date, location, fee, summary FROM events
             WHERE status='published' AND event_date >= NOW() ORDER BY event_date ASC LIMIT 3"
        );
        $about = Database::fetch("SELECT title, content FROM pages WHERE slug='about' AND status='published' AND deleted_at IS NULL");
        return [$banners, $featured, $services, $news, $faqs, $voices, $about, $events];
    }
}
