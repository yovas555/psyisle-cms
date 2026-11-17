<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Core\Str;

class Event extends Base
{
    protected string $table = 'events';
    protected string $title = '活动管理';
    protected string $orderBy = 'event_date DESC';
    protected string $baseSeg = 'events';
    protected string $permNode = 'events';
    protected array $searchFields = ['title'];
    protected array $columns = [
        'title' => ['标题', 'text'],
        'event_date' => ['开始时间', 'datetime'],
        'location' => ['地点', 'text'],
        'fee' => ['费用', 'text'],
        'status' => ['状态', 'publish_status'],
        'reg' => ['报名', 'number'],
    ];
    protected array $fields = [
        'title' => ['标题', 'text', ['required' => true]],
        'slug' => ['URL 别名', 'text', ['hint' => '留空自动生成']],
        'cover' => ['封面图', 'image', []],
        'summary' => ['摘要', 'textarea', ['rows' => 3]],
        'content' => ['详细介绍', 'richtext', []],
        'event_date' => ['开始时间', 'datetime', []],
        'duration_hours' => ['时长（小时）', 'decimal', ['hint' => '如 1.5']],
        'location' => ['地点', 'text', ['hint' => '线上活动填「线上（腾讯会议）」等']],
        'capacity' => ['名额上限', 'number', ['hint' => '留空=不限名额']],
        'fee' => ['费用描述', 'text', ['hint' => '如：免费 / 99元/人']],
        'status' => ['状态', 'publish_select', []],
        'sort' => ['排序', 'number', []],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
    ];

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['title'] ?? '') ?: 'e-' . date('YmdHis');
        }
        if (($data['status'] ?? '') === 'published') {
            // 保存时刷新满员状态
            if ($id) {
                $cap = (int)($data['capacity'] ?? 0);
                if ($cap > 0) {
                    $used = (int)Database::value("SELECT COALESCE(SUM(people_count),0) FROM event_registrations WHERE event_id=? AND status<>'cancelled'", [$id]);
                    if ($used >= $cap) { $data['status'] = 'full'; }
                }
            }
        }
    }

    public function edit(Request $req, array $p): void
    {
        $row = Database::fetch("SELECT * FROM `{$this->table}` WHERE id=?", [(int)$p['id']]);
        if (!$row) { Session::flash('error', '记录不存在'); \app\Core\ResponseRedirect('/admin/events'); }
        $regs = Database::fetchAll('SELECT * FROM event_registrations WHERE event_id=? ORDER BY id DESC LIMIT 200', [$row['id']]);
        $this->view('crud/form', [
            'fields' => $this->fields,
            'row' => $row,
            'baseSeg' => 'events',
            'registrations' => $regs,
        ]);
    }

    public function registrations(Request $req, array $p): void
    {
        $e = Database::fetch('SELECT * FROM events WHERE id=?', [(int)$p['id']]);
        if (!$e) { Session::flash('error', '活动不存在'); \app\Core\ResponseRedirect('/admin/events'); }
        $regs = Database::fetchAll('SELECT * FROM event_registrations WHERE event_id=? ORDER BY id DESC LIMIT 500', [$e['id']]);
        $this->view('event/registrations', ['e' => $e, 'regs' => $regs, 'title' => '报名名单：' . $e['title']]);
    }

    public function exportRegs(Request $req, array $p): void
    {
        $rows = Database::fetchAll(
            'SELECT r.*, e.title etitle FROM event_registrations r LEFT JOIN events e ON e.id=r.event_id WHERE r.event_id=? ORDER BY r.id DESC LIMIT 2000',
            [(int)$p['id']]
        );
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . (int)$p['id'] . '-regs.csv"');
        echo "\xEF\xBB\xBF";
        $f = fopen('php://output', 'w');
        fputcsv($f, ['ID', '姓名', '手机', '人数', '备注', '状态', '报名时间']);
        foreach ($rows as $r) { fputcsv($f, [$r['id'], $r['name'], $r['phone'], $r['people_count'], $r['note'], $r['status'], $r['created_at']]); }
        fclose($f);
        $this->audit('event.export_regs', 'events', (int)$p['id']);
        exit;
    }

    public function regStatus(Request $req, array $p): void
    {
        Csrf::guard();
        $rid = (int)$p['id'];
        $status = (string)$req->post('status', '');
        if (in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
            Database::update('event_registrations', ['status' => $status], 'id=?', [$rid]);
            $this->audit('event.reg_status', 'event_registrations', $rid, ['status' => $status]);
        }
        \app\Core\ResponseRedirect('/admin/events');
    }
}
