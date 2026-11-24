<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

/**
 * 经营驾驶舱（P2-B）：纯读聚合，零写入。
 * ------------------------------------------------------------------
 * 口径一律以《psyisle-audit/08-经营指标字典.md》冻结稿为准。
 * 时间窗口：[start, end] 闭区间；金额分→元由视图换算。
 * 所有 SQL 参数化，禁止拼字符串。
 */
class BusinessReportService
{
    /** 解析时间窗口：合法返回 [start, end]，否则默认近 30 天 */
    public static function range(?string $from, ?string $to): array
    {
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to) ? (string)$to : date('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) ? (string)$from : date('Y-m-d', strtotime('-29 days'));
        if (strtotime($from) > strtotime($to)) { $t = $from; $from = $to; $to = $t; }
        return [$from, $to];
    }

    /** 预约漏斗 F1..F5 + 转化率 */
    public static function funnel(string $from, string $to): array
    {
        $d0 = $from . ' 00:00:00';
        $d1 = $to . ' 23:59:59';

        $f1pv = (int)Database::value('SELECT COUNT(*) FROM visit_logs WHERE created_at BETWEEN ? AND ?', [$d0, $d1]);
        $f1uv = (int)Database::value('SELECT COUNT(DISTINCT ip_hash) FROM visit_logs WHERE created_at BETWEEN ? AND ?', [$d0, $d1]);

        $f2 = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE created_at BETWEEN ? AND ? AND status<>'cancelled'", [$d0, $d1]);
        $f3 = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE status='confirmed' AND updated_at BETWEEN ? AND ?", [$d0, $d1]);
        $f4 = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE status='completed' AND updated_at BETWEEN ? AND ?", [$d0, $d1]);
        $f5 = (int)Database::value(
            "SELECT COUNT(*) FROM (
                SELECT phone FROM bookings WHERE status='completed' AND updated_at BETWEEN ? AND ?
                GROUP BY phone HAVING COUNT(*) >= 2
             ) t",
            [$d0, $d1]
        );

        $pct = function (int $n, int $d): string {
            return $d > 0 ? round($n * 100 / $d, 1) . '%' : '—';
        };
        return [
            'pv' => $f1pv, 'uv' => $f1uv,
            'created' => $f2, 'confirmed' => $f3, 'completed' => $f4, 'rebuy' => $f5,
            'rates' => [
                'created_to_pv' => $pct($f2, $f1pv),
                'confirmed_to_created' => $pct($f3, $f2),
                'completed_to_confirmed' => $pct($f4, $f3),
                'rebuy_rate' => $pct($f5, max(1, (int)Database::value(
                    "SELECT COUNT(DISTINCT phone) FROM bookings WHERE status='completed' AND updated_at BETWEEN ? AND ?", [$d0, $d1]))),
            ],
        ];
    }

    /** 渠道来源：预约 source 分布 + 流量 referer 分布 */
    public static function channels(string $from, string $to): array
    {
        $d0 = $from . ' 00:00:00';
        $d1 = $to . ' 23:59:59';
        $src = Database::fetchAll(
            "SELECT COALESCE(NULLIF(TRIM(source),''),'web') AS k, COUNT(*) AS c
             FROM bookings WHERE created_at BETWEEN ? AND ? AND status<>'cancelled'
             GROUP BY k ORDER BY c DESC LIMIT 20", [$d0, $d1]);
        $ref = Database::fetchAll(
            "SELECT COALESCE(NULLIF(TRIM(referer_host),''),'直接访问') AS k, COUNT(DISTINCT ip_hash) AS c
             FROM visit_logs WHERE created_at BETWEEN ? AND ?
             GROUP BY k ORDER BY c DESC LIMIT 20", [$d0, $d1]);
        return ['source' => $src, 'referer' => $ref];
    }

    /** 咨询师产能：完成会次 + 到访率 + 排行 */
    public static function counselors(string $from, string $to): array
    {
        $d0 = $from . ' 00:00:00';
        $d1 = $to . ' 23:59:59';
        $held = Database::fetchAll(
            "SELECT cs.counselor_id, COUNT(*) AS c
             FROM case_sessions cs WHERE cs.attendance='held' AND cs.held_at BETWEEN ? AND ?
             GROUP BY cs.counselor_id", [$from, $to]);
        $confirmed = Database::fetchAll(
            "SELECT counselor_id, COUNT(*) AS c FROM bookings
             WHERE status='confirmed' AND updated_at BETWEEN ? AND ? AND counselor_id IS NOT NULL
             GROUP BY counselor_id", [$d0, $d1]);
        $completed = Database::fetchAll(
            "SELECT counselor_id, COUNT(*) AS c FROM bookings
             WHERE status='completed' AND updated_at BETWEEN ? AND ? AND counselor_id IS NOT NULL
             GROUP BY counselor_id", [$d0, $d1]);
        $map = [];
        foreach ($held as $h) $map[(int)$h['counselor_id']]['held'] = (int)$h['c'];
        foreach ($confirmed as $h) $map[(int)$h['counselor_id']]['confirmed'] = (int)$h['c'];
        foreach ($completed as $h) $map[(int)$h['counselor_id']]['completed'] = (int)$h['c'];

        $names = Database::fetchAll('SELECT id, name FROM counselors WHERE status=1');
        $rows = [];
        foreach ($names as $n) {
            $id = (int)$n['id'];
            $c = $map[$id]['completed'] ?? 0;
            $cf = $map[$id]['confirmed'] ?? 0;
            $rows[] = [
                'name' => (string)$n['name'],
                'held' => $map[$id]['held'] ?? 0,
                'confirmed' => $cf,
                'completed' => $c,
                'show_rate' => $cf > 0 ? round($c * 100 / $cf, 1) . '%' : '—',
            ];
        }
        usort($rows, fn($a, $b) => $b['held'] <=> $a['held']);
        $totalCounselors = count($names);
        $totalHeld = array_sum(array_column($rows, 'held'));
        return [
            'rows' => $rows,
            'total_counselors' => $totalCounselors,
            'total_held' => $totalHeld,
            'avg_held' => $totalCounselors > 0 ? round($totalHeld / $totalCounselors, 1) : 0,
        ];
    }

    /** 收入：线上咨询已支付 + 课时包售课/退款流水 */
    public static function revenue(string $from, string $to): array
    {
        $d0 = $from . ' 00:00:00';
        $d1 = $to . ' 23:59:59';
        $online = (int)Database::value(
            "SELECT COALESCE(SUM(amount_cents),0) FROM bookings
             WHERE pay_status='paid' AND amount_cents IS NOT NULL AND updated_at BETWEEN ? AND ?", [$d0, $d1]);
        $sale = (int)Database::value(
            "SELECT COALESCE(SUM(amount_cents),0) FROM package_ledger WHERE kind='sale' AND created_at BETWEEN ? AND ?", [$d0, $d1]);
        $refund = (int)Database::value(
            "SELECT COALESCE(SUM(amount_cents),0) FROM package_ledger WHERE kind='refund' AND created_at BETWEEN ? AND ?", [$d0, $d1]);
        return [
            'online_cents' => $online,
            'package_sale_cents' => $sale,
            'package_refund_cents' => $refund,
            'package_net_cents' => $sale - $refund,
            'total_cents' => $online + ($sale - $refund),
        ];
    }

    /** 会员：新增 + 活跃（完成预约去重） */
    public static function members(string $from, string $to): array
    {
        $d0 = $from . ' 00:00:00';
        $d1 = $to . ' 23:59:59';
        $new = (int)Database::value('SELECT COUNT(*) FROM members WHERE created_at BETWEEN ? AND ?', [$d0, $d1]);
        $active = (int)Database::value(
            "SELECT COUNT(DISTINCT phone) FROM bookings WHERE status='completed' AND updated_at BETWEEN ? AND ?", [$d0, $d1]);
        $total = (int)Database::value("SELECT COUNT(*) FROM members WHERE status='active'");
        return ['new' => $new, 'active' => $active, 'total' => $total];
    }

    /** 汇总（页面一次取全） */
    public static function summary(string $from, string $to): array
    {
        return [
            'from' => $from, 'to' => $to,
            'funnel' => self::funnel($from, $to),
            'channels' => self::channels($from, $to),
            'counselors' => self::counselors($from, $to),
            'revenue' => self::revenue($from, $to),
            'members' => self::members($from, $to),
        ];
    }

    /** CSV 导出：预约明细 + 收入流水（脱敏 phone） */
    public static function exportRows(string $from, string $to): array
    {
        $d0 = $from . ' 00:00:00';
        $d1 = $to . ' 23:59:59';
        $bookings = Database::fetchAll(
            "SELECT b.code, b.name, b.phone, b.source, b.status, b.pay_status, b.amount_cents, b.created_at,
                    c.name AS counselor_name
             FROM bookings b LEFT JOIN counselors c ON c.id=b.counselor_id
             WHERE b.created_at BETWEEN ? AND ? ORDER BY b.id DESC LIMIT 5000", [$d0, $d1]);
        $ledger = Database::fetchAll(
            "SELECT l.kind, l.sessions, l.amount_cents, l.created_at, m.phone
             FROM package_ledger l LEFT JOIN members m ON m.id=l.member_id
             WHERE l.created_at BETWEEN ? AND ? ORDER BY l.id DESC LIMIT 5000", [$d0, $d1]);
        return ['bookings' => $bookings, 'ledger' => $ledger];
    }
}
