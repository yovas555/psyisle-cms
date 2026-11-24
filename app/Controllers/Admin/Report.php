<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Request;
use app\Services\BusinessReportService;

/**
 * 经营驾驶舱（P2-B）：纯读看板 + CSV 导出。
 * ------------------------------------------------------------------
 * 权限节点：reporting（默认仅 * 与显式含 reporting 的角色可看，不开放给咨询师）。
 * 只读：不写任何业务数据，仅查看时写一条 audit。
 */
class Report extends Base
{
    protected string $title = '经营驾驶舱';
    protected string $permNode = 'reporting';

    public function index(Request $req, array $p): void
    {
        [$from, $to] = BusinessReportService::range((string)$req->get('from', ''), (string)$req->get('to', ''));
        $data = BusinessReportService::summary($from, $to);
        $this->audit('report.view', 'business', null, ['from' => $from, 'to' => $to]);
        $this->view('report/index', [
            'title' => '经营驾驶舱',
            'd' => $data,
        ]);
    }

    public function export(Request $req, array $p): void
    {
        Csrf::guard();
        [$from, $to] = BusinessReportService::range((string)$req->get('from', ''), (string)$req->get('to', ''));
        $rows = BusinessReportService::exportRows($from, $to);
        $this->audit('report.export', 'business', null, ['from' => $from, 'to' => $to]);

        $out = fopen('php://output', 'w');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="business-' . $from . '_' . $to . '.csv"');
        fwrite($out, "\xEF\xBB\xBF"); // BOM，Excel 中文不乱码

        fputcsv($out, ['# 经营明细导出', $from, '至', $to]);
        fputcsv($out, []);
        fputcsv($out, ['== 预约明细 ==']);
        fputcsv($out, ['查询码', '姓名', '手机', '来源', '状态', '支付', '金额(元)', '咨询师', '时间']);
        foreach ($rows['bookings'] as $b) {
            fputcsv($out, [
                (string)$b['code'], (string)$b['name'], mask_phone((string)$b['phone']),
                (string)($b['source'] ?: 'web'), (string)$b['status'], (string)$b['pay_status'],
                $b['amount_cents'] !== null ? number_format((int)$b['amount_cents'] / 100, 2) : '',
                (string)($b['counselor_name'] ?? ''), (string)$b['created_at'],
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['== 课时包流水 ==']);
        fputcsv($out, ['类型', '次数', '金额(元)', '会员', '时间']);
        foreach ($rows['ledger'] as $l) {
            fputcsv($out, [
                (string)$l['kind'], (int)$l['sessions'],
                number_format((int)$l['amount_cents'] / 100, 2),
                mask_phone((string)($l['phone'] ?? '')), (string)$l['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }
}
