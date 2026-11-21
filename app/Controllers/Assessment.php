<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Hook;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

/** 心理自评量表（仅筛查提示，不构成诊断） */
class Assessment
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $rows = Database::fetchAll('SELECT id, slug, name, description, disclaimer FROM assessments WHERE status=1 ORDER BY sort, id');
        $meta = SeoService::meta('心理自评量表', 'PHQ-9 抑郁自评、GAD-7 焦虑自评等国际通用筛查量表，在线自测并获取专业建议', '心理测试,抑郁自评,焦虑自评,PHQ-9,GAD-7', url('/assessment'));
        View::render('assessment/index', [
            'meta' => $meta,
            'rows' => $rows,
            'crumbs' => ['首页' => url('/'), '心理自评量表' => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '心理自评量表' => url('/assessment')]),
        ]);
    }

    public function show(Request $req, array $p): void
    {
        StatsService::track();
        $a = Database::fetch('SELECT * FROM assessments WHERE slug=? AND status=1', [$p['slug']]);
        if (!$a) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $items = json_decode((string)$a['items'], true) ?: [];
        $meta = SeoService::meta((string)$a['name'], (string)$a['description'], '心理测评,' . $a['name'], url('/assessment/' . $a['slug']));
        View::render('assessment/show', [
            'meta' => $meta,
            'a' => $a,
            'items' => $items,
            'crumbs' => ['首页' => url('/'), '心理自评量表' => url('/assessment'), $a['name'] => ''],
        ]);
    }

    public function submit(Request $req, array $p): void
    {
        Csrf::guard();
        StatsService::track();
        // v2.14.0 提案：测评可选要求登录（assess.require_member 默认 0 关闭）
        $mNow = \app\Services\MemberService::current();
        if (!$mNow && (bool)setting('assess.require_member', '0')) {
            \app\Core\Session::flash('error', '测评请先登录会员账号，结果将存档到「我的测评」');
            \app\Core\ResponseRedirect('/member/login');
        }
        if (!RateLimiter::hit('assessment', $req->ip(), 10, 600)) {
            \app\Core\Session::flash('error', '提交过于频繁，请稍后再试');
            \app\Core\ResponseRedirect('/assessment');
        }
        $a = Database::fetch('SELECT * FROM assessments WHERE slug=? AND status=1', [$p['slug']]);
        if (!$a) { \app\Controllers\StaticPage::notFound($req, []); return; }

        // v2.20.0：计分/落库/通知抽至 AssessmentService（WEB 与小程序 BFF 共用）
        $answers = [];
        foreach (json_decode((string)$a['items'], true) ?: [] as $i => $item) { $answers[$i] = (int)($req->post('q' . $i, -1) ?: 0); }
        [$ok, $result, $err] = \app\Services\AssessmentService::submit($a, $answers, \app\Services\MemberService::current()['id'] ?? null, $req->ip());
        if (!$ok) {
            \app\Core\Session::flash('error', $err);
            \app\Core\ResponseRedirect('/assessment/' . $a['slug']);
        }
        $score = (int)$result['score'];
        $level = (string)$result['level'];
        $advice = (string)$result['advice'];
        $reportToken = (string)$result['report_token'];
        $meta = SeoService::meta($a['name'] . '结果', '', '', url('/assessment/' . $a['slug']));
        View::render('assessment/result', [
            'meta' => $meta,
            'a' => $a,
            'score' => $score,
            'level' => $level,
            'advice' => $advice,
            'reportToken' => $reportToken,
            'disclaimer' => $a['disclaimer'],
            'crumbs' => ['首页' => url('/'), '心理自评量表' => url('/assessment'), '测试结果' => ''],
        ]);
    }

    /**
     * 测评报告页（v2.4.0）：凭 report_token 脱敏回看，可打印/存 PDF
     * 维度计分由插件通过 Hook report.dimensions 附加（内置量表无维度时只显示总分/分级）
     */
    public function report(Request $req, array $p): void
    {
        StatsService::track();
        $token = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)($p['token'] ?? '')));
        if (strlen($token) !== 32) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $r = Database::fetch('SELECT r.*, a.name AS a_name, a.slug AS a_slug, a.disclaimer FROM assessment_results r JOIN assessments a ON a.id=r.assessment_id WHERE r.report_token=?', [$token]);
        if (!$r) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $answers = json_decode((string)$r['answers'], true) ?: [];
        $aRow = Database::fetch('SELECT items, scoring FROM assessments WHERE id=?', [(int)$r['assessment_id']]);
        $items = json_decode((string)($aRow['items'] ?? ''), true) ?: [];
        $scoring = json_decode((string)($aRow['scoring'] ?? ''), true) ?: [];
        // 维度分（插件注册：slug 前缀 pro- 的量表含维度定义）
        $dimensions = Hook::fire('report.dimensions', ['slug' => $r['a_slug'], 'answers' => $answers, 'dimensions' => null])['dimensions'] ?? null;
        $site = (string)setting('site.name', '');
        $phone = (string)setting('contact.phone', '');
        $meta = SeoService::meta('测评报告', '', '', url('/report/' . $token));
        View::render('assessment/report', [
            'meta' => $meta, 'r' => $r, 'items' => $items, 'answers' => $answers,
            'dimensions' => $dimensions, 'scoring' => $scoring, 'site' => $site, 'phone' => $phone,
            'reportToken' => $token,
        ]);
    }
}
