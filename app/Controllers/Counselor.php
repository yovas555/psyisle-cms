<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Core\View;
use app\Services\BookingPricing;
use app\Services\BookingSlots;
use app\Services\CounselorProfile;
use app\Services\SeoService;
use app\Services\StatsService;

class Counselor
{
    private static bool $hasModesColumn = false;

    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $spec = self::queryValue($req, 'spec');
        $mode = self::queryValue($req, 'mode');
        if (!in_array($mode, CounselorProfile::MODES, true)) $mode = '';
        $available = self::queryValue($req, 'available') === '1' ? '1' : '';
        $maxFeeRaw = self::queryValue($req, 'max_fee');
        $maxFee = preg_match('/^\d{1,5}(?:\.\d{1,2})?$/', $maxFeeRaw) ? (float)$maxFeeRaw : 0.0;
        $sort = self::queryValue($req, 'sort');
        if (!in_array($sort, ['recommended', 'price_asc', 'price_desc', 'hours_desc'], true)) $sort = 'recommended';
        $page = max(1, (int)self::queryValue($req, 'page', '1'));

        self::$hasModesColumn = in_array('service_modes', array_column(Database::fetchAll('SHOW COLUMNS FROM counselors'), 'Field'), true);
        $priceJoin = " LEFT JOIN (SELECT cs.counselor_id,
            MIN(CASE WHEN cs.price_cents>0 THEN cs.price_cents/100 WHEN s.price_from>0 THEN s.price_from END) AS price_from,
            MAX(CASE WHEN cs.price_cents>0 THEN cs.price_cents/100
                     WHEN s.price_to>0 THEN s.price_to WHEN s.price_from>0 THEN s.price_from END) AS price_to,
            COUNT(*) AS service_count FROM counselor_services cs JOIN services s ON s.id=cs.service_id AND s.status=1
            GROUP BY cs.counselor_id) cp ON cp.counselor_id=c.id";
        $feeFrom = 'CASE WHEN cp.service_count>0 THEN cp.price_from ELSE c.fee_from END';
        $feeTo = 'CASE WHEN cp.service_count>0 THEN cp.price_to ELSE c.fee_to END';
        $where = 'c.status=1';
        $params = [];
        if ($spec !== '') {
            $where .= ' AND specialties LIKE ?';
            $params[] = '%' . mb_substr($spec, 0, 50) . '%';
        }
        if ($mode !== '' && self::$hasModesColumn) {
            $where .= " AND (service_modes IS NULL OR service_modes='' OR service_modes LIKE ?)";
            $params[] = '%' . $mode . '%';
        }
        if ($maxFee > 0) {
            $where .= " AND {$feeFrom}>0 AND {$feeFrom}<=?";
            $params[] = $maxFee;
        }
        $order = [
            'recommended' => 'c.is_featured DESC, c.sort, c.id',
            'price_asc' => "{$feeFrom} IS NULL, {$feeFrom} ASC, c.is_featured DESC, c.sort, c.id",
            'price_desc' => "{$feeFrom} IS NULL, {$feeFrom} DESC, c.is_featured DESC, c.sort, c.id",
            'hours_desc' => 'c.hours IS NULL, c.hours DESC, c.is_featured DESC, c.sort, c.id',
        ][$sort];

        $firstAvailable = [];
        if ($available === '1') {
            $candidates = Database::fetchAll("SELECT c.*, {$feeFrom} AS display_fee_from, {$feeTo} AS display_fee_to FROM counselors c{$priceJoin} WHERE $where ORDER BY $order", $params);
            $firstAvailable = BookingSlots::firstAvailableBatch(array_column($candidates, 'id'));
            $candidates = array_values(array_filter($candidates, static function (array $c) use ($firstAvailable): bool {
                return !empty($firstAvailable[(int)$c['id']]);
            }));
            $total = count($candidates);
        } else {
            $total = (int)Database::value("SELECT COUNT(*) FROM counselors c{$priceJoin} WHERE $where", $params);
            $candidates = null;
        }

        $size = 12;
        $pages = max(1, (int)ceil($total / $size));
        $page = min($page, $pages);
        $offset = ($page - 1) * $size;
        if ($candidates === null) {
            $rows = Database::fetchAll("SELECT c.*, {$feeFrom} AS display_fee_from, {$feeTo} AS display_fee_to FROM counselors c{$priceJoin} WHERE $where ORDER BY $order LIMIT $size OFFSET $offset", $params);
            $firstAvailable = BookingSlots::firstAvailableBatch(array_column($rows, 'id'));
        } else {
            $rows = array_slice($candidates, $offset, $size);
        }

        $tagRows = Database::fetchAll("SELECT specialties FROM counselors WHERE status=1 AND specialties<>''");
        $tags = [];
        foreach ($tagRows as $r) {
            foreach (preg_split('/[、,，;；\s]+/u', (string)$r['specialties']) ?: [] as $t) {
                $t = trim($t);
                if ($t !== '') $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
        }
        arsort($tags);
        $filters = [
            'spec' => $spec,
            'mode' => $mode,
            'available' => $available,
            'max_fee' => $maxFee > 0 ? rtrim(rtrim(number_format($maxFee, 2, '.', ''), '0'), '.') : '',
            'sort' => $sort === 'recommended' ? '' : $sort,
        ];
        $meta = SeoService::meta(
            '咨询师团队',
            '按擅长方向、服务方式、可约时间与收费筛选专业心理咨询师，查看真实档案并预约',
            '心理咨询师,咨询师团队,心理治疗,预约咨询',
            url('/counselors')
        );
        View::render('counselor/index', [
            'meta' => $meta,
            'rows' => $rows,
            'tags' => array_slice($tags, 0, 12, true),
            'currentSpec' => $spec,
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'firstAvailable' => $firstAvailable,
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
            'SELECT ws.weekday, ws.start_time, ws.end_time, ws.service_id, s.name AS service_name
             FROM counselor_slots ws LEFT JOIN services s ON s.id=ws.service_id AND s.status=1
             WHERE ws.counselor_id=? AND ws.status=1 ORDER BY ws.weekday, ws.start_time',
            [$c['id']]
        );
        $calendar = BookingSlots::days((int)$c['id'], 14, 1, true);
        $firstAvailable = null;
        foreach ($calendar as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['available']) { $firstAvailable = $day['ymd'] . ' ' . $slot['start']; break 2; }
            }
        }
        $services = BookingPricing::forCounselor((int)$c['id']);
        $priceRange = BookingPricing::ranges([(int)$c['id']])[(int)$c['id']] ?? ['from' => null, 'to' => null];

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
            'firstAvailable' => $firstAvailable,
            'services' => $services,
            'priceRange' => $priceRange,
            'serviceModes' => CounselorProfile::modes($c['service_modes'] ?? ''),
            'trust' => \app\Services\CounselorTrust::publicProfile($c, \app\Services\CounselorTrust::latestFor((int)$c['id'])),
            'crumbs' => ['首页' => url('/'), '咨询师团队' => url('/counselors'), $c['name'] => ''],
            'personSchema' => SeoService::counselorSchema($c),
            'breadcrumbSchema' => SeoService::breadcrumbSchema(['首页' => url('/'), '咨询师团队' => url('/counselors'), $c['name'] => url('/counselors/' . $c['slug'])]),
        ]);
    }

    private static function queryValue(Request $req, string $key, string $default = ''): string
    {
        $value = $req->get($key, $default);
        return is_scalar($value) ? trim((string)$value) : $default;
    }
}
