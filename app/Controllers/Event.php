<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Captcha;
use app\Core\Csrf;
use app\Core\Database;
use app\Core\Logger;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\Session;
use app\Core\Validator;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

/** 活动/工作坊（列表 + 详情 + 报名） */
class Event
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $rows = Database::fetchAll(
            "SELECT id, title, slug, cover, summary, event_date, location, fee, capacity, status,
             (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=events.id AND r.status<>'cancelled') registered
             FROM events WHERE status IN ('published','full') AND (event_date IS NULL OR event_date >= DATE_SUB(NOW(), INTERVAL 1 DAY))
             ORDER BY event_date ASC LIMIT 50"
        );
        $meta = SeoService::meta('活动与工作坊', '心理讲座、正念工作坊、家长课堂等线下线上活动，欢迎报名参加', '心理讲座,工作坊,家长课堂,正念', url('/events'));
        View::render('event/index', [
            'meta' => $meta,
            'rows' => $rows,
            'crumbs' => ['首页' => url('/'), '活动报名' => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '活动报名' => url('/events')]),
        ]);
    }

    public function show(Request $req, array $p): void
    {
        StatsService::track();
        $e = Database::fetch("SELECT * FROM events WHERE slug=? AND status IN ('published','full')", [$p['slug']]);
        if (!$e) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $registered = (int)Database::value(
            "SELECT COALESCE(SUM(people_count),0) FROM event_registrations WHERE event_id=? AND status<>'cancelled'",
            [$e['id']]
        );
        $meta = SeoService::meta(
            (string)($e['seo_title'] ?: $e['title']),
            (string)($e['seo_description'] ?: $e['summary']),
            '心理活动,' . $e['title'],
            url('/events/' . $e['slug'])
        );
        View::render('event/show', [
            'meta' => $meta,
            'e' => $e,
            'registered' => $registered,
            'left' => $e['capacity'] ? max(0, (int)$e['capacity'] - $registered) : null,
            'crumbs' => ['首页' => url('/'), '活动报名' => url('/events'), $e['title'] => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '活动报名' => url('/events'), $e['title'] => url('/events/' . $e['slug'])]),
        ]);
    }

    public function register(Request $req, array $p): void
    {
        Csrf::guard();
        StatsService::track();
        if (!RateLimiter::hit('event_reg', $req->ip(), 5, 3600)) {
            Session::flash('error', '报名过于频繁，请稍后再试');
            \app\Core\ResponseRedirect('/events');
        }
        $e = Database::fetch("SELECT * FROM events WHERE slug=? AND status IN ('published','full')", [$p['slug']]);
        if (!$e) { \app\Controllers\StaticPage::notFound($req, []); return; }

        $data = $req->posts();
        $v = new Validator($data, [
            'name' => 'required|maxlen:40|safe_text',
            'phone' => 'required|phone',
            'people_count' => 'int',
            'note' => 'maxlen:300|safe_text',
            'captcha' => 'required',
        ], ['name' => '姓名', 'phone' => '手机号', 'captcha' => '验证码']);
        if ($v->fails() || !Captcha::check((string)$data['captcha'])) {
            Session::flash('error', $v->firstError() ?: '验证码不正确');
            \app\Core\ResponseRedirect('/events/' . $e['slug']);
        }
        $people = min(10, max(1, (int)($data['people_count'] ?: 1)));

        // 名额校验
        if ($e['capacity']) {
            $used = (int)Database::value(
                "SELECT COALESCE(SUM(people_count),0) FROM event_registrations WHERE event_id=? AND status<>'cancelled'",
                [$e['id']]
            );
            if ($used + $people > (int)$e['capacity']) {
                Session::flash('error', '剩余名额不足（剩 ' . max(0, (int)$e['capacity'] - $used) . ' 个）');
                \app\Core\ResponseRedirect('/events/' . $e['slug']);
            }
        }
        Database::insert('event_registrations', [
            'event_id' => (int)$e['id'],
            'name' => (string)$data['name'],
            'phone' => (string)$data['phone'],
            'people_count' => $people,
            'note' => mb_substr((string)($data['note'] ?? ''), 0, 299),
            'status' => 'pending',
            'ip' => $req->ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // 满员自动标记
        if ($e['capacity']) {
            $used = (int)Database::value(
                "SELECT COALESCE(SUM(people_count),0) FROM event_registrations WHERE event_id=? AND status<>'cancelled'",
                [$e['id']]
            );
            if ($used >= (int)$e['capacity']) {
                Database::run("UPDATE events SET status='full' WHERE id=?", [$e['id']]);
                \app\Core\Cache::forget('home_data');
            }
        }
        \app\Services\NotifyService::push('event', '新活动报名：' . $e['title'],
            sprintf("姓名：%s\n手机：%s\n人数：%d\n备注：%s", $data['name'], mask_phone((string)$data['phone']), $people, str_take((string)($data['note'] ?? ''), 100, '…')),
            ['type' => 'event', 'id' => $e['id']]);
        Logger::info('event', 'new registration', ['event' => $e['id']]);
        Session::flash('success', '报名成功！我们会在活动开始前与你确认。');
        \app\Core\ResponseRedirect('/events/' . $e['slug']);
    }
}
