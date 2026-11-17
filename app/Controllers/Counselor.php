<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Core\View;
use app\Services\SeoService;
use app\Services\StatsService;

class Counselor
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $spec = trim((string)$req->get('spec', ''));
        $where = 'status=1';
        $params = [];
        if ($spec !== '') {
            $where .= ' AND specialties LIKE ?';
            $params[] = '%' . $spec . '%';
        }
        $rows = Database::fetchAll(
            "SELECT * FROM counselors WHERE $where ORDER BY is_featured DESC, sort, id",
            $params
        );
        // 擅长方向标签（全部）
        $tagRows = Database::fetchAll("SELECT specialties FROM counselors WHERE status=1 AND specialties<>''");
        $tags = [];
        foreach ($tagRows as $r) {
            foreach (preg_split('/[、,，;；\s]+/u', (string)$r['specialties']) as $t) {
                $t = trim($t);
                if ($t !== '' && !isset($tags[$t])) $tags[$t] = 0;
            }
        }
        foreach ($tagRows as $r) {
            foreach (preg_split('/[、,，;；\s]+/u', (string)$r['specialties']) as $t) {
                $t = trim($t);
                if ($t !== '') $tags[$t]++;
            }
        }
        arsort($tags);
        $meta = SeoService::meta(
            '咨询师团队',
            '专业心理咨询师团队：资质、流派、擅长方向与收费标准',
            '心理咨询师,咨询师团队,心理治疗',
            url('/counselors')
        );
        View::render('counselor/index', [
            'meta' => $meta,
            'rows' => $rows,
            'tags' => array_slice($tags, 0, 12, true),
            'currentSpec' => $spec,
            'crumbs' => ['首页' => url('/'), '咨询师团队' => ''],
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '咨询师团队' => url('/counselors')]),
        ]);
    }

    public function show(Request $req, array $p): void
    {
        StatsService::track();
        $c = Database::fetch('SELECT * FROM counselors WHERE slug=? AND status=1', [$p['slug']]);
        if (!$c) { \app\Controllers\StaticPage::notFound($req, []); return; }
        $slots = Database::fetchAll(
            'SELECT weekday, start_time, end_time FROM counselor_slots WHERE counselor_id=? AND status=1 ORDER BY weekday, start_time',
            [$c['id']]
        );
        // 未来 14 天可约日历（按 counselor_slots 模板生成）
        $calendar = [];
        if ($slots) {
            for ($i = 1; $i <= 14; $i++) {
                $ts = strtotime("+{$i} days");
                $wd = (int)date('N', $ts);
                $daySlots = [];
                foreach ($slots as $sl) {
                    if ((int)$sl['weekday'] === $wd) {
                        $daySlots[] = substr((string)$sl['start_time'], 0, 5) . '-' . substr((string)$sl['end_time'], 0, 5);
                    }
                }
                if ($daySlots) {
                    $calendar[] = ['date' => date('m-d', $ts), 'week' => '周' . mb_substr('一二三四五六日', $wd - 1, 1), 'slots' => $daySlots];
                }
            }
        }
        $services = Database::fetchAll('SELECT name, slug, price_from, price_to FROM services WHERE status=1 ORDER BY sort LIMIT 6');

        $meta = SeoService::meta(
            (string)($c['seo_title'] ?: ($c['name'] . ' - 咨询师介绍')),
            (string)($c['seo_description'] ?: str_take((string)$c['introduction'], 200, '')),
            (string)($c['seo_keywords'] ?: $c['specialties']),
            url('/counselors/' . $c['slug'])
        );
        View::render('counselor/show', [
            'meta' => $meta,
            'c' => $c,
            'slots' => $slots,
            'calendar' => $calendar,
            'services' => $services,
            'crumbs' => ['首页' => url('/'), '咨询师团队' => url('/counselors'), $c['name'] => ''],
            'personSchema' => SeoService::counselorSchema($c),
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '咨询师团队' => url('/counselors'), $c['name'] => url('/counselors/' . $c['slug'])]),
        ]);
    }
}
