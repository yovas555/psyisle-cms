<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\NotifyService;

class Booking extends Base
{
    protected string $table = 'bookings';
    protected string $permNode = 'bookings';
    protected string $title = '预约管理';
    protected string $orderBy = 'id DESC';
    protected string $baseSeg = 'bookings';
    protected array $searchFields = ['name', 'phone', 'code'];
    protected array $columns = [
        'name' => ['姓名', 'text'],
        'phone' => ['手机', 'masked_phone'],
        'svc' => ['服务', 'text'],
        'prefer_date' => ['期望时间', 'text'],
        'urgency' => ['紧急', 'yesno'],
        'status' => ['状态', 'booking_status'],
        'created_at' => ['提交时间', 'datetime'],
    ];

    public function index(Request $req, array $p): void
    {
        $page = max(1, (int)$req->get('page', 1));
        $kw = trim((string)$req->get('q', ''));
        $st = (string)$req->get('status', '');
        $where = '1=1';
        $params = [];
        if ($st !== '') { $where .= ' AND b.status=?'; $params[] = $st; }
        if ($kw !== '') {
            $where .= ' AND (b.name LIKE ? OR b.phone LIKE ? OR b.code=?)';
            $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%', strtoupper($kw)]);
        }
        $size = 15;
        $offset = ($page - 1) * $size;
        $total = Database::value(
            "SELECT COUNT(*) FROM bookings b LEFT JOIN services s ON s.id=b.service_id WHERE $where",
            $params
        );
        $rows = Database::fetchAll(
            "SELECT b.*, s.name svc, c.name cname FROM bookings b
             LEFT JOIN services s ON s.id=b.service_id
             LEFT JOIN counselors c ON c.id=b.counselor_id
             WHERE $where ORDER BY b.id DESC LIMIT $size OFFSET $offset",
            $params
        );
        $this->view('booking/list', [
            'rows' => $rows, 'total' => (int)$total, 'page' => $page,
            'pages' => max(1, (int)ceil((int)$total / $size)), 'kw' => $kw, 'st' => $st,
            'title' => $this->title,
        ]);
    }

    public function edit(Request $req, array $p): void
    {
        $b = Database::fetch(
            'SELECT b.*, s.name svc, c.name cname FROM bookings b
             LEFT JOIN services s ON s.id=b.service_id
             LEFT JOIN counselors c ON c.id=b.counselor_id
             WHERE b.id=?', [(int)$p['id']]
        );
        if (!$b) { Session::flash('error', '预约不存在'); \app\Core\ResponseRedirect('/admin/bookings'); }
        $this->view('booking/detail', ['b' => $b, 'title' => '预约详情 #' . $b['id']]);
    }

    public function setStatus(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $status = (string)$req->post('status', '');
        $note = trim((string)$req->post('admin_note', ''));
        if (!in_array($status, ['pending','confirmed','rescheduled','completed','cancelled'], true)) {
            Session::flash('error', '状态不合法'); \app\Core\ResponseRedirect('/admin/bookings/' . $id . '/edit');
        }
        Database::update('bookings', [
            'status' => $status,
            'admin_note' => $note ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
        $this->audit('booking.status', 'bookings', $id, ['status' => $status, 'note' => $note]);
        // v2.9.0：预约状态变更 → 通知会员（邮件主通道 + 微信双发，按偏好路由）
        $b = Database::fetch('SELECT code, phone, name, prefer_date, prefer_slot FROM bookings WHERE id=?', [$id]);
        // v2.12.0：预约状态变更 Hook（referral-pro 用于确认推荐发放积分；须在 $b 取得后携带完整上下文）
        \app\Core\Hook::fire('booking.status_changed', ['booking_id' => $id, 'status' => $status, 'phone' => $b['phone'] ?? '', 'name' => $b['name'] ?? '']);
        $mid = \app\Services\MemberNotifyService::memberIdByPhone((string)($b['phone'] ?? ''));
        if ($mid > 0) {
            $evMap = ['confirmed' => 'booking_confirm', 'cancelled' => 'booking_cancel', 'rescheduled' => 'booking_reschedule'];
            $ev = $evMap[$status] ?? '';
            if ($ev !== '') {
                $verbed = ['confirmed' => '已确认', 'cancelled' => '已取消', 'rescheduled' => '已改期'][$status];
                \app\Services\MemberNotifyService::push($mid, $ev, [
                    'title' => '您的咨询预约' . $verbed,
                    'lines' => [
                        '查询码' => (string)($b['code'] ?? ''),
                        '预约人' => (string)($b['name'] ?? ''),
                        '期望时间' => trim((string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '')) ?: '待确认',
                        '当前状态' => ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'][$status] ?? $status,
                    ],
                    'note' => (string)$note !== '' ? '机构留言：' . $note : '',
                    'url' => base_url() . '/member/bookings',
                ]);
            }
        }
        Session::flash('success', '预约状态已更新');
        \app\Core\ResponseRedirect('/admin/bookings');
    }

    public function export(Request $req, array $p): void
    {
        $rows = Database::fetchAll(
            'SELECT b.*, s.name svc, c.name cname FROM bookings b
             LEFT JOIN services s ON s.id=b.service_id
             LEFT JOIN counselors c ON c.id=b.counselor_id ORDER BY b.id DESC LIMIT 5000'
        );
        $this->csvOut('bookings-' . date('Ymd-Hi') . '.csv', [
            ['ID', '查询码', '姓名', '手机', '邮箱', '微信', '服务', '咨询师', '方式', '期望日期', '期望时段', '年龄段', '紧急', '主诉', '状态', '备注', '提交时间'],
            array_map(fn($b) => [$b['id'], $b['code'], $b['name'], $b['phone'], $b['email'], $b['wechat'], $b['svc'], $b['cname'], $b['method'], $b['prefer_date'], $b['prefer_slot'], $b['age_range'], $b['urgency'] ? '是' : '否', $b['issue'], $b['status'], $b['admin_note'], $b['created_at']], $rows),
        ]);
        $this->audit('booking.export');
    }

    private function csvOut(string $name, array $table): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo "\xEF\xBB\xBF"; // BOM 防 Excel 乱码
        foreach ($table as $line) {
            $f = fopen('php://output', 'w');
            fputcsv($f, array_map(fn($v) => (string)$v, $line));
            fclose($f);
        }
        exit;
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        Database::delete('bookings', 'id=?', [(int)$p['id']]);
        $this->audit('booking.delete', 'bookings', (int)$p['id']);
        Session::flash('success', '已删除');
        \app\Core\ResponseRedirect('/admin/bookings');
    }
}
