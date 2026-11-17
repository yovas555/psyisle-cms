<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Purifier;
use app\Core\Request;
use app\Core\Session;

/**
 * 咨询师工作台（v2.10.0）
 *
 * 形态：后台账号（users）关联咨询师档案（counselors.user_id），登录后进入专属工作台。
 * 自范围原则：全部查询按自己的 counselor_id 过滤，看不到其他咨询师与财务/系统数据。
 * 角色权限：roles.permissions = ["counselor_workspace"]（布局渲染工作台专属侧栏）。
 */
class CounselorHome extends Base
{
    protected string $permNode = 'counselor_workspace';
    protected string $title = '咨询师工作台';

    /** 当前登录账号关联的咨询师行；未关联则提示并跳转 */
    private function me(): array
    {
        $u = Session::get('admin_user');
        $row = Database::fetch('SELECT * FROM counselors WHERE user_id=?', [(int)($u['id'] ?? 0)]);
        if (!$row || (int)$row['status'] !== 1) {
            Session::flash('error', '账号未关联咨询师档案，请联系管理员在「咨询师」中绑定');
            \app\Core\ResponseRedirect('/admin');
        }
        return $row;
    }

    // ==================== 总览 ====================

    public function index(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $upcoming = Database::fetchAll(
            "SELECT b.*, s.name svc FROM bookings b LEFT JOIN services s ON s.id=b.service_id
             WHERE b.counselor_id=? AND b.status IN ('confirmed','rescheduled') AND b.prefer_date >= CURDATE()
             ORDER BY b.prefer_date LIMIT 20", [$cid]);
        $today = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND prefer_date=CURDATE() AND status IN ('confirmed','rescheduled')", [$cid]);
        $pending = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND status='pending'", [$cid]);
        $notes = (int)Database::value('SELECT COUNT(*) FROM counselor_notes WHERE counselor_id=?', [$cid]);
        // 咨客套餐余额（最近 30 天内有预约的咨客）
        $clients = Database::fetchAll(
            "SELECT m.id, m.phone, m.name,
                    COALESCE((SELECT SUM(o.remain) FROM package_orders o WHERE o.member_id=m.id AND o.status='active' AND (o.expire_at IS NULL OR o.expire_at>=CURDATE())), 0) AS remain
             FROM members m
             WHERE m.phone IN (SELECT phone FROM bookings WHERE counselor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
             ORDER BY remain DESC LIMIT 30", [$cid]);
        $this->view('counselor-home/index', [
            'me' => $me, 'upcoming' => $upcoming, 'today' => $today,
            'pending' => $pending, 'notesCount' => $notes, 'clients' => $clients,
        ]);
    }

    // ==================== 我的资料与价格 ====================

    public function profile(Request $req, array $p): void
    {
        $me = $this->me();
        if ($req->isPost()) {
            Csrf::guard();
            $d = $req->posts();
            $feeFrom = max(0, (int)round(((float)($d['fee_from'] ?? 0)) * 100));
            $feeTo = max(0, (int)round(((float)($d['fee_to'] ?? 0)) * 100));
            Database::run(
                'UPDATE counselors SET title=?, orientation=?, specialties=?, introduction=?, education=?, experience=?, style=?, hours=?, languages=?, tags=?,
                 fee_from=?, fee_to=?, fee_unit=?, avatar=?, updated_at=NOW() WHERE id=? AND user_id=?', [
                str_take(trim((string)($d['title'] ?? '')), 80, ''),
                str_take(trim((string)($d['orientation'] ?? '')), 120, ''),
                str_take(trim((string)($d['specialties'] ?? '')), 190, ''),
                Purifier::clean((string)($d['introduction'] ?? '')),
                str_take(trim((string)($d['education'] ?? '')), 500, ''),
                str_take(trim((string)($d['experience'] ?? '')), 500, ''),
                str_take(trim((string)($d['style'] ?? '')), 500, ''),
                str_take(trim((string)($d['hours'] ?? '')), 120, ''),
                str_take(trim((string)($d['languages'] ?? '')), 100, ''),
                str_take(trim((string)($d['tags'] ?? '')), 190, ''),
                $feeFrom, $feeTo,
                str_take(trim((string)($d['fee_unit'] ?? '')), 20, ''),
                str_take(trim((string)($d['avatar'] ?? '')), 255, ''),
                (int)$me['id'], (int)(Session::get('admin_user')['id'] ?? 0),
            ]);
            $this->audit('counselor.profile', 'counselors', (int)$me['id']);
            \app\Core\Cache::forget('home_data');
            Session::flash('success', '个人资料已保存');
            \app\Core\ResponseRedirect('/admin/counselor-home/profile');
        }
        $this->view('counselor-home/profile', ['me' => $me]);
    }

    // ==================== 我的预约 ====================

    public function bookings(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $st = (string)$req->get('status', '');
        $where = 'b.counselor_id=?';
        $params = [$cid];
        if ($st !== '') { $where .= ' AND b.status=?'; $params[] = $st; }
        $rows = Database::fetchAll(
            "SELECT b.*, s.name svc, m.id mid FROM bookings b
             LEFT JOIN services s ON s.id=b.service_id
             LEFT JOIN members m ON m.phone=b.phone
             WHERE $where ORDER BY b.prefer_date DESC, b.id DESC LIMIT 100", $params);
        // 咨客套餐余额映射
        $remain = [];
        foreach ($rows as $r) {
            if (!empty($r['mid']) && !isset($remain[$r['mid']])) {
                $remain[$r['mid']] = (int)Database::value(
                    "SELECT COALESCE(SUM(o.remain),0) FROM package_orders o WHERE o.member_id=? AND o.status='active' AND (o.expire_at IS NULL OR o.expire_at>=CURDATE())", [(int)$r['mid']]);
            }
        }
        $this->view('counselor-home/bookings', ['rows' => $rows, 'st' => $st, 'remain' => $remain]);
    }

    // ==================== 我的排班 ====================

    public function schedule(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        if ($req->isPost()) {
            Csrf::guard();
            $act = (string)$req->post('act', '');
            if ($act === 'slots') {
                // 每行：星期|开始|结束
                $lines = preg_split('/\r\n|\r|\n/', (string)$req->post('slots_text', '')) ?: [];
                Database::run('DELETE FROM counselor_slots WHERE counselor_id=?', [$cid]);
                foreach ($lines as $line) {
                    $parts = array_map('trim', explode('|', trim((string)$line)));
                    if (count($parts) !== 3 || $parts[0] === '') continue;
                    $wd = (int)$parts[0];
                    if ($wd < 1 || $wd > 7) continue;
                    Database::insert('counselor_slots', [
                        'counselor_id' => $cid, 'weekday' => $wd,
                        'start_time' => str_take($parts[1], 8, ''), 'end_time' => str_take($parts[2], 8, ''),
                        'slot_minutes' => 60, 'status' => 1,
                    ]);
                }
                Session::flash('success', '可约时段已更新');
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
            if ($act === 'override') {
                $date = (string)$req->post('date', '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    Database::run('DELETE FROM schedule_overrides WHERE counselor_id=? AND date=?', [$cid, $date]);
                    Database::insert('schedule_overrides', [
                        'counselor_id' => $cid, 'date' => $date,
                        'status' => $req->post('status', 'off') === 'on' ? 'on' : 'off',
                        'start_time' => str_take(trim((string)$req->post('start_time', '')), 8, ''),
                        'end_time' => str_take(trim((string)$req->post('end_time', '')), 8, ''),
                        'note' => str_take(trim((string)$req->post('note', '')), 190, ''),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    Session::flash('success', '例外排班已保存（前台时段实时生效）');
                }
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
            if ($act === 'override-del') {
                Database::run('DELETE FROM schedule_overrides WHERE counselor_id=? AND id=?', [$cid, (int)$req->post('id', 0)]);
                Session::flash('success', '例外排班已删除');
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
            if ($act === 'fee') {
                $feeArr = [];
                foreach (Database::fetchAll('SELECT weekday, start_time, end_time FROM counselor_slots WHERE counselor_id=? AND status=1', [$cid]) as $r) {
                    $feeKey = 'fee_' . $r['weekday'] . '_' . substr((string)$r['start_time'], 0, 5);
                    $cents = max(0, (int)round((float)($req->post($feeKey, 0)) * 100));
                    if ($cents > 0) $feeArr[] = ['weekday' => $r['weekday'], 'slot' => substr((string)$r['start_time'], 0, 5), 'price_cents' => $cents];
                }
                Database::run('UPDATE counselors SET fee_override=? WHERE id=?', [json_encode($feeArr, JSON_UNESCAPED_UNICODE), $cid]);
                Session::flash('success', '分时定价已保存');
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
            if ($act === 'leave') {
                $on = !empty($req->post('on_leave')) ? 1 : 0;
                $note = str_take(trim((string)$req->post('leave_note', '')), 190, '');
                Database::run('UPDATE counselors SET on_leave=?, leave_note=? WHERE id=?', [$on, $note, $cid]);
                Session::flash($on ? 'success' : 'success', $on ? '已标记请假（前台暂停接单）' : '已恢复接单');
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
        }
        $slots = Database::fetchAll('SELECT * FROM counselor_slots WHERE counselor_id=? ORDER BY weekday, start_time', [$cid]);
        $lines = [];
        foreach ($slots as $s) {
            $lines[] = $s['weekday'] . '|' . substr((string)$s['start_time'], 0, 5) . '|' . substr((string)$s['end_time'], 0, 5);
        }
        $overrides = Database::fetchAll('SELECT * FROM schedule_overrides WHERE counselor_id=? ORDER BY date DESC LIMIT 30', [$cid]);
        $onLeave = (int)$me['on_leave'];
        $leaveNote = (string)$me['leave_note'];
        $feeArr = json_decode((string)$me['fee_override'], true) ?: [];
        $this->view('counselor-home/schedule', ['slotsText' => implode("\n", $lines), 'overrides' => $overrides,
            'me' => $me, 'onLeave' => $onLeave, 'leaveNote' => $leaveNote, 'feeArr' => $feeArr]);
    }

    // ==================== 咨询小结与作业 ====================

    public function notes(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        if ($req->isPost()) {
            Csrf::guard();
            $act = (string)$req->post('act', '');
            $nid = (int)$req->post('id', 0);
            if ($act === 'save') {
                $mid = (int)$req->post('member_id', 0);
                $kind = (string)$req->post('kind', 'summary') === 'homework' ? 'homework' : 'summary';
                $title = str_take(trim((string)$req->post('title', '')), 120, '');
                $content = Purifier::clean((string)$req->post('content', ''));
                if ($mid <= 0 || $title === '' || $content === '') {
                    Session::flash('error', '请选择咨客并填写标题与正文');
                    \app\Core\ResponseRedirect('/admin/counselor-home/notes');
                }
                $owned = Database::fetch('SELECT id FROM members WHERE id=?', [$mid]);
                if (!$owned) { Session::flash('error', '咨客不存在'); \app\Core\ResponseRedirect('/admin/counselor-home/notes'); }
                if ($nid) {
                    Database::run('UPDATE counselor_notes SET member_id=?, booking_id=?, kind=?, title=?, content=?, visible=?, updated_at=NOW() WHERE id=? AND counselor_id=?', [
                        $mid, (int)$req->post('booking_id', 0) ?: null, $kind, $title, $content,
                        !empty($req->post('visible')) ? 1 : 0, $nid, $cid,
                    ]);
                    Session::flash('success', '记录已更新');
                } else {
                    Database::insert('counselor_notes', [
                        'counselor_id' => $cid, 'member_id' => $mid,
                        'booking_id' => (int)$req->post('booking_id', 0) ?: null,
                        'kind' => $kind, 'title' => $title, 'content' => $content,
                        'visible' => !empty($req->post('visible')) ? 1 : 0,
                        'created_by' => (int)(Session::get('admin_user')['id'] ?? 0),
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    Session::flash('success', '记录已创建' . (!empty($req->post('visible')) ? '，咨客可在会员中心阅读' : '（暂不可见）'));
                }
                \app\Core\ResponseRedirect('/admin/counselor-home/notes');
            }
            if ($act === 'toggle') {
                Database::run('UPDATE counselor_notes SET visible=1-visible, updated_at=NOW() WHERE id=? AND counselor_id=?', [$nid, $cid]);
                Session::flash('success', '可见性已切换');
                \app\Core\ResponseRedirect('/admin/counselor-home/notes');
            }
        }
        $rows = Database::fetchAll(
            'SELECT n.*, m.phone AS m_phone, m.name AS m_name FROM counselor_notes n
             LEFT JOIN members m ON m.id=n.member_id WHERE n.counselor_id=? ORDER BY n.id DESC LIMIT 60', [$cid]);
        $members = Database::fetchAll(
            "SELECT DISTINCT m.id, m.phone, m.name FROM members m
             WHERE m.phone IN (SELECT phone FROM bookings WHERE counselor_id=?) ORDER BY m.id DESC LIMIT 100", [$cid]);
        $edit = $req->get('edit', '') !== '' ? Database::fetch('SELECT * FROM counselor_notes WHERE id=? AND counselor_id=?', [(int)$req->get('edit'), $cid]) : null;
        $this->view('counselor-home/notes', ['rows' => $rows, 'members' => $members, 'edit' => $edit]);
    }

    /** 供视图转义的富文本直接输出前再净化一次 */
    public static function clean(string $html): string
    {
        return Purifier::clean($html);
    }
}
