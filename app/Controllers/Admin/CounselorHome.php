<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Purifier;
use app\Core\Request;
use app\Core\Session;
use app\Core\Uploader;
use app\Services\CounselorScope;
use app\Services\CounselorProfileReview;
use app\Services\CounselorWorkspace;
use app\Services\PostSessionService;

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
    protected function me(): array
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

    /** v2.20.2 微信服务号绑定（接收预约提醒推送）：经中台 OAuth，回调写 counselor_wx.openid */
    public function bindWx(Request $req, array $p): void
    {
        $me = $this->me();
        $wx = Database::fetch('SELECT * FROM counselor_wx WHERE counselor_id=? AND openid IS NOT NULL AND openid<>""', [(int)$me['id']]);
        if ($wx) {
            if (!class_exists(\plugins\wxhubclient\Plugin::class) || !\plugins\wxhubclient\Plugin::hubReady()) {
                Session::flash('error', '微信中台未配置完成，暂时无法核实关注状态');
            } else {
                [$ok, $identity, $err] = \plugins\wxhubclient\Plugin::identityLookup((string)$wx['openid'], (string)($wx['unionid'] ?? ''));
                if (!$ok) Session::flash('error', '关注状态查询失败：' . $err);
                elseif (!empty($identity['found']) && !empty($identity['is_followed'])) Session::flash('success', '已关注服务号，付款预约提醒可正常推送');
                else Session::flash('error', '服务号已绑定但尚未关注，请先关注后再接收提醒');
            }
            \app\Core\ResponseRedirect('/admin/counselor-home');
        }
        if ((string)setting('wxhub.mode', 'direct') !== 'hub') {
            \app\Core\Session::flash('error', '微信服务号绑定需中台模式（系统设置→微信中台）');
            \app\Core\ResponseRedirect('/admin/counselor-home');
        }
        \app\Core\ResponseRedirect('/plugin/wxhub-client/login?intent=counselor_bind');
    }

    public function index(Request $req, array $p): void
    {
        $me = $this->me();
        $this->view('counselor-home/index', CounselorWorkspace::overview((int)$me['id']) + ['me' => $me]);
    }

    public function todos(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        if ($req->isPost()) {
            Csrf::guard();
            $action = (string)$req->post('action', '');
            if ($action === 'read-all') {
                CounselorWorkspace::readAllNotifications($cid);
                Session::flash('success', '站内通知已全部标为已读');
            } elseif ($action === 'read' && (int)$req->post('id', 0) > 0) {
                $notice = Database::fetch('SELECT id FROM counselor_notifications WHERE id=? AND counselor_id=?', [(int)$req->post('id', 0), $cid]);
                CounselorWorkspace::readNotification($cid, (int)$req->post('id', 0));
                Session::flash($notice ? 'success' : 'error', $notice ? '通知已标为已读' : '通知不存在或无权操作');
            }
            \app\Core\ResponseRedirect('/admin/counselor-home/todos');
        }
        $this->view('counselor-home/todos', [
            'title' => '待办',
            'rows' => CounselorWorkspace::todos($cid),
            'notifications' => CounselorWorkspace::notifications($cid),
        ]);
    }

    public function calendar(Request $req, array $p): void
    {
        $me = $this->me();
        $date = (string)$req->get('date', date('Y-m-d'));
        $this->view('counselor-home/calendar', CounselorWorkspace::calendar((int)$me['id'], $date));
    }

    public function settlement(Request $req, array $p): void
    {
        $me = $this->me();
        $month = (string)$req->get('month', date('Y-m'));
        $this->view('counselor-home/settlement', CounselorWorkspace::settlement((int)$me['id'], $month));
    }

    public function feedbacks(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        if ($req->isPost()) {
            Csrf::guard();
            $feedback = Database::fetch('SELECT * FROM booking_feedbacks WHERE id=? AND counselor_id=?', [(int)$req->post('id', 0), $cid]);
            if (!$feedback || (string)$feedback['bridge_status'] !== 'waiting_case') {
                Session::flash('error', '反馈不存在或已处理');
                \app\Core\ResponseRedirect('/admin/counselor-home/feedbacks');
            }
            try {
                $updated = PostSessionService::retryBridge((int)$feedback['booking_id'], $cid, (int)$req->post('case_id', 0));
                $created = (string)($updated['bridge_status'] ?? '') === 'created';
                Session::flash($created ? 'success' : 'error', $created ? '已关联个案并安排咨询后随访' : '尚未关联有效个案，请核对后重试');
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            } catch (\Throwable $e) {
                \app\Core\Logger::error('post-session', 'counselor bridge failed: ' . $e->getMessage(), ['booking' => (int)$feedback['booking_id']]);
                Session::flash('error', '随访桥接失败，请稍后重试');
            }
            \app\Core\ResponseRedirect('/admin/counselor-home/feedbacks');
        }
        $rows = Database::fetchAll(
            "SELECT f.*, b.prefer_date, b.prefer_slot, b.name, b.phone, c.case_no
             FROM booking_feedbacks f JOIN bookings b ON b.id=f.booking_id
             LEFT JOIN cases c ON c.id=f.case_id
             WHERE f.counselor_id=? ORDER BY f.id DESC LIMIT 100", [$cid]
        );
        $waitingMembers = [];
        foreach ($rows as $row) {
            if ((string)$row['bridge_status'] === 'waiting_case') {
                $waitingMembers[(int)$row['member_id']] = Database::fetchAll(
                    "SELECT id, case_no, title, status FROM cases WHERE member_id=? AND counselor_id=? AND status='active' ORDER BY id DESC",
                    [(int)$row['member_id'], $cid]
                );
            }
        }
        $this->view('counselor-home/feedbacks', [
            'title' => '疗程反馈',
            'rows' => $rows,
            'waitingMembers' => $waitingMembers,
            'summary' => CounselorWorkspace::feedbackSummary($cid, (string)$req->get('month', date('Y-m'))),
            'month' => (string)$req->get('month', date('Y-m')),
        ]);
    }

    // ==================== 我的资料与价格 ====================

    public function avatarUpload(Request $req, array $p): void
    {
        Csrf::guard();
        $file = $req->file('file');
        if (!$file) json_out(['ok' => false, 'msg' => '未选择文件']);
        $info = @getimagesize($file['tmp_name']);
        if (!$info || (int)$info[2] !== IMAGETYPE_JPEG || (int)$info[0] !== 960 || (int)$info[1] !== 960) {
            json_out(['ok' => false, 'msg' => '头像必须为裁剪后的 960×960 JPG 图片']);
        }
        [$ok, $path, $name, $err] = Uploader::image($file, 'counselor');
        if (!$ok) json_out(['ok' => false, 'msg' => $err]);
        $this->audit('counselor.avatar_upload', 'attachments', null, ['path' => $path]);
        json_out(['ok' => true, 'path' => $path, 'name' => $name]);
    }

    public function profile(Request $req, array $p): void
    {
        $me = $this->me();
        if ($req->isPost()) {
            Csrf::guard();
            $d = $req->posts();
            $feeFrom = max(0, (int)round(((float)($d['fee_from'] ?? 0)) * 100));
            $feeTo = max(0, (int)round(((float)($d['fee_to'] ?? 0)) * 100));
            $actorId = (int)(Session::get('admin_user')['id'] ?? 0);
            $reviewLocked = CounselorProfileReview::pendingFor((int)$me['id']) !== null;
            if (!$reviewLocked) {
                try {
                    CounselorProfileReview::saveDraft((int)$me['id'], $actorId, $d + [
                        'attachment_ids' => $d['draft_attachment_ids'] ?? [],
                    ]);
                } catch (\InvalidArgumentException $e) {
                    Session::flash('error', $e->getMessage());
                    \app\Core\ResponseRedirect('/admin/counselor-home/profile');
                }
            }
            Database::run(
                'UPDATE counselors SET title=?, orientation=?, specialties=?, introduction=?, education=?, experience=?, style=?, hours=?, languages=?, tags=?, phone=?,
                 fee_from=?, fee_to=?, fee_unit=?, avatar=?, updated_at=NOW() WHERE id=? AND user_id=?', [
                str_take(trim((string)($d['title'] ?? '')), 80, ''),
                str_take(trim((string)($d['orientation'] ?? '')), 120, ''),
                str_take(trim((string)($d['specialties'] ?? '')), 190, ''),
                Purifier::clean((string)($d['introduction'] ?? '')),
                str_take(trim((string)($d['education'] ?? '')), 500, ''),
                str_take(trim((string)($d['experience'] ?? '')), 500, ''),
                str_take(trim((string)($d['style'] ?? '')), 500, ''),
                max(0, (int)($d['hours'] ?? 0)),
                str_take(trim((string)($d['languages'] ?? '')), 100, ''),
                str_take(trim((string)($d['tags'] ?? '')), 190, ''),
                preg_match('/^1[3-9]\d{9}$/', trim((string)($d['phone'] ?? ''))) ? trim((string)$d['phone']) : null,
                $feeFrom, $feeTo,
                str_take(trim((string)($d['fee_unit'] ?? '')), 20, ''),
                str_take(trim((string)($d['avatar'] ?? '')), 255, ''),
                (int)$me['id'], $actorId,
            ]);
            $this->audit('counselor.profile', 'counselors', (int)$me['id']);
            \app\Core\Cache::forget('home_data');
            Session::flash('success', $reviewLocked ? '其他资料已保存；资质资料审核中，暂不可修改' : '资料已保存；资质内容仅存为草稿，提交审核后才会公开');
            \app\Core\ResponseRedirect('/admin/counselor-home/profile');
        }
        $pending = CounselorProfileReview::pendingFor((int)$me['id']);
        $latest = CounselorProfileReview::latestFor((int)$me['id']);
        $draft = CounselorProfileReview::decodeDraft($me['profile_draft'] ?? null, $me);
        $this->view('counselor-home/profile', [
            'me' => $me,
            'draft' => $draft,
            'pending' => $pending,
            'latest' => $latest,
            'attachments' => CounselorProfileReview::attachments((int)$me['id'], $pending ? (int)$pending['id'] : null),
            'trust' => \app\Services\CounselorTrust::publicProfile($me, \app\Services\CounselorTrust::latestFor((int)$me['id'])),
        ]);
    }

    public function profileSubmit(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        try {
            CounselorProfileReview::submit((int)$me['id'], (int)(Session::get('admin_user')['id'] ?? 0));
            $this->audit('counselor.profile_submitted', 'counselors', (int)$me['id']);
            Session::flash('success', '资质资料已提交，管理员审核通过后才会公开');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/profile');
    }

    public function evidenceUpload(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $file = $req->file('file');
        if (!$file) json_out(['ok' => false, 'msg' => '未选择证明文件']);
        try {
            $attachment = CounselorProfileReview::uploadEvidence((int)$me['id'], (int)(Session::get('admin_user')['id'] ?? 0), $file);
            $this->audit('counselor.profile_evidence_uploaded', 'counselor_profile_attachments', (int)$attachment['id']);
            json_out(['ok' => true, 'attachment' => [
                'id' => (int)$attachment['id'], 'name' => (string)$attachment['original_name'], 'size' => (int)$attachment['size'],
            ]]);
        } catch (\InvalidArgumentException $e) {
            json_out(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            json_out(['ok' => false, 'msg' => '证明文件保存失败，请稍后重试']);
        }
    }

    public function evidenceDownload(Request $req, array $p): void
    {
        $me = $this->me();
        $attachment = CounselorProfileReview::evidenceForViewer((int)($p['id'] ?? 0), (int)$me['id'], false);
        if (!$attachment) { http_response_code(404); echo 'not found'; exit; }
        CounselorProfileReview::streamEvidence($attachment);
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
            "SELECT b.*, s.name svc, m.id mid, f.id feedback_id, f.status feedback_status,
                    f.rating feedback_rating, f.comment feedback_comment, f.wants_followup feedback_followup,
                    f.bridge_status feedback_bridge, f.case_id feedback_case_id
             FROM bookings b
             LEFT JOIN services s ON s.id=b.service_id
             LEFT JOIN members m ON m.phone=b.phone
             LEFT JOIN booking_feedbacks f ON f.booking_id=b.id
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
                // 每行：星期|开始|结束[|单次分钟|方式|门店ID]
                $lines = preg_split('/\r\n|\r|\n/', (string)$req->post('slots_text', '')) ?: [];
                $rows = [];
                $error = '';
                $validStores = [];
                foreach (Database::fetchAll('SELECT id FROM stores WHERE status=1') as $storeRow) $validStores[(int)$storeRow['id']] = true;
                foreach ($lines as $line) {
                    if (trim((string)$line) === '') continue;
                    $parts = array_map('trim', explode('|', trim((string)$line)));
                    if (count($parts) < 3) { $error = '排班格式不正确，每行至少需要星期、开始和结束时间'; break; }
                    $wd = (int)$parts[0];
                    $slotMin = (int)($parts[3] ?? 50);
                    $slotMode = (string)($parts[4] ?? 'all');
                    $slotStoreId = max(0, (int)($parts[5] ?? 0));
                    if ($wd < 1 || $wd > 7
                        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$parts[1])
                        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$parts[2])
                        || strcmp((string)$parts[2], (string)$parts[1]) <= 0) {
                        $error = '排班时间不正确，结束时间必须晚于开始时间';
                        break;
                    }
                    if ($slotMin < 10 || $slotMin > 240) { $error = '单次咨询时长需在 10–240 分钟之间'; break; }
                    if (!in_array($slotMode, ['all', 'offline', 'online'], true)) { $error = '咨询方式只能是 all、offline 或 online'; break; }
                    if ($slotMode === 'online') $slotStoreId = 0;
                    if ($slotStoreId > 0 && !isset($validStores[$slotStoreId])) { $error = '排班门店不存在或已停用'; break; }
                    $rows[] = [
                        'counselor_id' => $cid, 'weekday' => $wd,
                        'start_time' => (string)$parts[1] . ':00', 'end_time' => (string)$parts[2] . ':00',
                        'slot_minutes' => $slotMin, 'service_mode' => $slotMode,
                        'store_id' => $slotStoreId, 'status' => 1,
                    ];
                }
                if ($error !== '') {
                    Session::flash('error', $error . '；原排班未修改');
                } else {
                    Database::transaction(static function () use ($cid, $rows): void {
                        Database::run('DELETE FROM counselor_slots WHERE counselor_id=?', [$cid]);
                        foreach ($rows as $row) Database::insert('counselor_slots', $row);
                    });
                    Session::flash('success', '可约时段已更新');
                }
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
            if ($act === 'override') {
                $date = (string)$req->post('date', '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    // v2.17.0 修复：状态值对齐 schedule-pro 的 closed/open（原 on/off 前台不识别，例外排班从不生效）
                    $ovStatus = (string)$req->post('status', 'closed') === 'open' ? 'open' : 'closed';
                    Database::run('DELETE FROM schedule_overrides WHERE counselor_id=? AND date=?', [$cid, $date]);
                    Database::insert('schedule_overrides', [
                        'counselor_id' => $cid, 'date' => $date,
                        'status' => $ovStatus,
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
            if ($act === 'leave-range') {
                $start = (string)$req->post('start_date', '');
                $end = (string)$req->post('end_date', '');
                $startTs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? strtotime($start) : false;
                $endTs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? strtotime($end) : false;
                if ($startTs === false || $endTs === false || date('Y-m-d', $startTs) !== $start
                    || date('Y-m-d', $endTs) !== $end || $endTs < $startTs) {
                    Session::flash('error', '请选择有效的请假起止日期');
                } else {
                    Database::insert('counselor_leaves', [
                        'counselor_id' => $cid,
                        'start_date' => date('Y-m-d', $startTs),
                        'end_date' => date('Y-m-d', $endTs),
                        'note' => str_take(trim((string)$req->post('note', '')), 190, ''),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    Session::flash('success', '请假日期已保存，前台在请假期间不可预约');
                }
                \app\Core\ResponseRedirect('/admin/counselor-home/schedule');
            }
            if ($act === 'leave-range-del') {
                Database::run('DELETE FROM counselor_leaves WHERE counselor_id=? AND id=?', [$cid, (int)$req->post('id', 0)]);
                Session::flash('success', '请假记录已删除');
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
            $mode = (string)($s['service_mode'] ?? 'all');
            $storeId = $mode === 'online' ? 0 : (int)($s['store_id'] ?? 0);
            $lines[] = $s['weekday'] . '|' . substr((string)$s['start_time'], 0, 5) . '|' . substr((string)$s['end_time'], 0, 5)
                . '|' . (int)$s['slot_minutes'] . '|' . $mode . '|' . $storeId;
        }
        $overrides = Database::fetchAll('SELECT * FROM schedule_overrides WHERE counselor_id=? ORDER BY date DESC LIMIT 30', [$cid]);
        $leaveRanges = Database::fetchAll('SELECT * FROM counselor_leaves WHERE counselor_id=? AND end_date>=? ORDER BY start_date DESC LIMIT 30', [$cid, date('Y-m-d')]);
        $storeNames = [];
        try {
            foreach (Database::fetchAll('SELECT id, name FROM stores') as $storeRow) $storeNames[(int)$storeRow['id']] = (string)$storeRow['name'];
        } catch (\Throwable $t) {
            $storeNames = [];
        }
        $onLeave = (int)$me['on_leave'];
        $leaveNote = (string)$me['leave_note'];
        $feeArr = json_decode((string)$me['fee_override'], true) ?: [];
        $this->view('counselor-home/schedule', ['slotsText' => implode("\n", $lines), 'overrides' => $overrides,
            'leaveRanges' => $leaveRanges, 'storeNames' => $storeNames,
            'me' => $me, 'onLeave' => $onLeave, 'leaveNote' => $leaveNote, 'feeArr' => $feeArr]);
    }

    // ==================== 咨询小结与作业 ====================

    public function notes(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        if ($req->isPost()) {
            // v2.22.0 / P1-B：历史咨询小结转为只读。新记录一律写进个案（case_records），
            // 以免出现「同一份内容两套载体、锁定与版本无从谈起」。
            Session::flash('error', '历史咨询小结已转为只读；新的咨询记录请在「个案管理」中填写（可锁定、可追溯版本）');
            \app\Core\ResponseRedirect('/admin/counselor-home/notes');
        }

        $rows = Database::fetchAll(
            'SELECT n.*, m.phone AS m_phone, m.name AS m_name FROM counselor_notes n
             LEFT JOIN members m ON m.id=n.member_id WHERE n.counselor_id=? ORDER BY n.id DESC LIMIT 60', [$cid]);
        $members = Database::fetchAll(
            "SELECT DISTINCT m.id, m.phone, m.name FROM members m
             WHERE m.phone IN (SELECT phone FROM bookings WHERE counselor_id=?) ORDER BY m.id DESC LIMIT 100", [$cid]);
        $edit = $req->get('edit', '') !== '' ? Database::fetch('SELECT * FROM counselor_notes WHERE id=? AND counselor_id=?', [(int)$req->get('edit'), $cid]) : null;
        // P0-G5：查阅留痕。列表一次一记（条数 + 涉及咨客数），单条编辑视图记具体记录 id。
        $this->audit('note.view', 'counselor_notes', $edit ? (int)$edit['id'] : null, [
            'list_count' => count($rows),
            'members' => count(array_unique(array_map(static fn($r) => (int)$r['member_id'], $rows))),
        ]);
        $this->view('counselor-home/notes', ['rows' => $rows, 'members' => $members, 'edit' => $edit]);
    }

    /** 供视图转义的富文本直接输出前再净化一次 */
    public static function clean(string $html): string
    {
        return Purifier::clean($html);
    }
}
