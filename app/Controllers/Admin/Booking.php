<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\BookingLifecycle;
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
        self::notifyStatusChange($row, $newStatus);
    }

    /** v2.17.0：状态变更通知统一出口——会员+咨询师双侧三通道（afterSave 与 setStatus 共用；completed 也推送） */
    private static function notifyStatusChange(array $row, string $newStatus): void
    {
        if (!in_array($newStatus, ['confirmed', 'completed', 'cancelled', 'rescheduled'], true)) return;
        $statusMap = ['confirmed' => '预约已确认', 'completed' => '咨询已完成', 'cancelled' => '预约已取消', 'rescheduled' => '预约已改期'];
        $label = $statusMap[$newStatus] ?? $newStatus;
        $when = (string)($row['prefer_date'] ?? '') . ' ' . (string)($row['prefer_slot'] ?? '');
        $code = (string)($row['code'] ?? '');
        $mid = \app\Services\MemberNotifyService::memberIdByPhone((string)($row['phone'] ?? ''));
        if ($mid > 0) {
            \app\Services\MemberNotifyService::push($mid, 'booking_' . $newStatus, [
                'title' => $label . '：' . $code,
                'body' => $when . ' · ' . (string)($row['method'] ?? ''),
                'lines' => ['查询码' => $code, '时间' => $when ?: '待确认', '方式' => (string)($row['method'] ?? ''), '状态' => $label],
                'inapp_url' => '/member/bookings', 'ref_type' => 'booking', 'ref_id' => $code,
                'note' => trim((string)($row['admin_note'] ?? '')) !== '' ? '机构留言：' . str_take((string)$row['admin_note'], 80, '') : '',
                'booking' => ['id' => (int)$row['id'], 'code' => $code, 'phone' => (string)$row['phone']],
            ]);
        }
        if ((int)($row['counselor_id'] ?? 0) > 0) {
            \app\Services\CounselorNotifyService::push((int)$row['counselor_id'], 'booking_' . $newStatus, [
                'title' => $label . '：' . $code,
                'body' => $when . ' · 会员 ' . (string)($row['name'] ?? ''),
                'url' => '/admin/counselor-home/bookings', 'booking_id' => (int)$row['id'],
                'booking' => ['id' => (int)$row['id'], 'code' => $code, 'phone' => (string)($row['phone'] ?? '')],
            ]);
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
                $ok = \plugins\smsnotify\Plugin::sendMemberSms($ph, ['store' => str_take($site, 12, ''),
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
        $this->view('booking/detail', [
            'b' => $b, 'title' => '预约详情 #' . $b['id'],
            'statusEvents' => Database::fetchAll('SELECT * FROM booking_status_events WHERE booking_id=? ORDER BY id DESC LIMIT 100', [(int)$b['id']]),
        ]);
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
        $actor = Session::get('admin_user');
        [$ok, $message, $b, $changed] = BookingLifecycle::transition(
            $id, $status, (int)($actor['id'] ?? 0), (string)(($actor['display_name'] ?? '') ?: ($actor['username'] ?? '管理员')), $note
        );
        if (!$ok) {
            Session::flash('error', $message);
            \app\Core\ResponseRedirect('/admin/bookings/' . $id . '/edit');
        }
        $this->audit('booking.status', 'bookings', $id, ['status' => $status, 'note' => $note, 'changed' => $changed]);
        if ($changed) {
            \app\Core\Hook::fire('booking.status_changed', ['booking_id' => $id, 'status' => $status, 'phone' => $b['phone'] ?? '', 'name' => $b['name'] ?? '']);
            self::notifyStatusChange($b, $status);
        }
        Session::flash('success', $message);
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
            // v2.22.0：单元格可能是数组（如插件附加列），直接 (string) 会刷 Array to string conversion
            fputcsv($f, array_map(static function ($v) {
                if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
                if (is_bool($v)) return $v ? '1' : '0';
                return (string)$v;
            }, $line));
            fclose($f);
        }
        exit;
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $booking = Database::fetch('SELECT status FROM bookings WHERE id=?', [$id]);
        if (!$booking || !in_array((string)$booking['status'], ['completed', 'cancelled'], true)) {
            Session::flash('error', '仅已完成或已取消的预约可删除');
            \app\Core\ResponseRedirect('/admin/bookings/' . $id . '/edit');
        }
        try {
            Database::transaction(function () use ($id): void {
            $booking = Database::fetch('SELECT status FROM bookings WHERE id=? FOR UPDATE', [$id]);
            if (!$booking || !in_array((string)$booking['status'], ['completed', 'cancelled'], true)) {
                throw new \RuntimeException('预约状态已变化，不能删除');
            }
            $references = [
                '咨询后反馈' => 'SELECT id FROM booking_feedbacks WHERE booking_id=? LIMIT 1',
                '个案会次' => 'SELECT id FROM case_sessions WHERE booking_id=? LIMIT 1',
                '个案随访' => 'SELECT id FROM case_followups WHERE booking_id=? LIMIT 1',
                '套餐流水' => 'SELECT id FROM package_ledger WHERE booking_id=? LIMIT 1',
            ];
            foreach ($references as $label => $sql) {
                if (Database::value($sql, [$id]) !== null) {
                    throw new \RuntimeException('该预约已关联' . $label . '，不能删除；请先由业务负责人核对留痕');
                }
            }
            Database::run('DELETE FROM booking_status_events WHERE booking_id=?', [$id]);
            Database::delete('bookings', 'id=?', [$id]);
            });
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            \app\Core\ResponseRedirect('/admin/bookings/' . $id . '/edit');
        }
        $this->audit('booking.delete', 'bookings', $id);
        Session::flash('success', '已删除');
        \app\Core\ResponseRedirect('/admin/bookings');
    }
}
