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

    /** v2.14.0：机构侧状态变更 → 会员+咨询师通知（站内/微信/短信矩阵） */
    private string $oldStatus = '';

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if ($mode === 'update' && $id) {
            $this->oldStatus = (string)(Database::value('SELECT status FROM bookings WHERE id=?', [$id]) ?? '');
        }
        parent::beforeSave($data, $id, $mode);
    }

    protected function afterSave(array $data, int $id, string $mode): void
    {
        parent::afterSave($data, $id, $mode);
        $row = Database::fetch('SELECT * FROM bookings WHERE id=?', [$id]);
        if (!$row) return;
        $newStatus = (string)($row['status'] ?? '');
        if ($newStatus === $this->oldStatus || $newStatus === '') return;
        $m = Database::fetch('SELECT id FROM members WHERE phone=? AND status="active"', [(string)$row['phone']]);
        $statusMap = ['confirmed' => '预约已确认', 'completed' => '咨询已完成', 'cancelled' => '预约已取消', 'rescheduled' => '预约已改期'];
        $label = $statusMap[$newStatus] ?? $newStatus;
        $when = (string)($row['prefer_date'] ?? '') . ' ' . (string)($row['prefer_slot'] ?? '');
        $dataN = [
            'title' => $label . '：' . (string)$row['code'],
            'body' => $when . ' · ' . (string)($row['method'] ?? ''),
            'lines' => ['查询码' => (string)$row['code'], '时间' => $when, '方式' => (string)($row['method'] ?? ''), '状态' => $label],
            'inapp_url' => '/member/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$row['code'],
            'booking' => ['id' => (int)$row['id'], 'code' => (string)$row['code'], 'phone' => (string)$row['phone']],
        ];
        $event = $newStatus === 'confirmed' ? 'booking_confirm'
            : ($newStatus === 'cancelled' ? 'booking_cancel'
            : ($newStatus === 'rescheduled' ? 'booking_reschedule' : null));
        if ($m && $event !== null) {
            \app\Services\MemberNotifyService::push((int)$m['id'], $event, $dataN);
        }
        if ((int)($row['counselor_id'] ?? 0) > 0) {
            \app\Services\CounselorNotifyService::push((int)$row['counselor_id'],
                $newStatus === 'cancelled' ? 'booking_cancel' : ($newStatus === 'confirmed' ? 'booking_confirm' : 'booking_reschedule'),
                ['title' => $label . '：' . (string)$row['code'],
                 'body' => $when . ' · 会员 ' . (string)$row['name'],
                 'url' => '/admin/counselor-home/bookings', 'booking_id' => (int)$row['id'],
                 'booking' => ['id' => (int)$row['id'], 'code' => (string)$row['code'], 'phone' => (string)$row['phone']]]);
        }
    }

    /** v2.14.0：手动发送短信提醒（绕过自动引擎，避免推送失败时无兜底） */
    public function sendSmsNow(Request $req, array $p): void
    {
        Csrf::guard();
        $bid = (int)$p['id'];
        $b = Database::fetch('SELECT * FROM bookings WHERE id=?', [$bid]);
        if (!$b) { Session::flash('error', '预约不存在'); \app\Core\ResponseRedirect('/admin/bookings'); }
        $phones = [];
        if (preg_match('/^1[3-9]\d{9}$/', (string)$b['phone'])) $phones[] = (string)$b['phone'];
        $csl = Database::fetch('SELECT phone FROM counselors WHERE id=?', [(int)($b['counselor_id'] ?? 0)]);
        if ($csl && preg_match('/^1[3-9]\d{9}$/', (string)$csl['phone'])) $phones[] = (string)$csl['phone'];
        $sent = 0;
        if (class_exists(\plugins\smsnotify\Plugin::class) && (bool)setting('sms.enabled', '0')) {
            $site = (string)setting('site.name', '');
            foreach ($phones as $ph) {
                $ok = \plugins\smsnotify\Plugin::sendMemberSms($ph, ['site' => str_take($site, 12, ''),
                    'event' => str_take(($b['status'] === 'confirmed' ? '咨询已确认' : '预约提醒'), 20, ''), 'time' => date('m-d H:i')]);
                if ($ok) $sent++;
            }
        }
        $this->audit('Booking.sendSms', 'bookings', $bid, ['sent' => $sent]);
        Session::flash($sent > 0 ? 'success' : 'error', "短信已发送 {$sent}/" . count($phones) . " 条");
        \app\Core\ResponseRedirect('/admin/bookings');
    }

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
