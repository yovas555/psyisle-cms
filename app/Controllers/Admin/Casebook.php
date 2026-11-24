<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\AiDraftService;
use app\Services\CaseCareService;
use app\Services\CasePolicy;
use app\Services\CaseRecordService;
use app\Services\CaseService;
use app\Services\FollowupPlanService;

/**
 * 个案管理（P1-A）
 * ------------------------------------------------------------------
 * 挂在咨询师工作台下：/admin/counselor-home/cases*
 * 继承 CounselorHome 复用 me()（账号↔咨询师绑定校验）、audit()、view()。
 * 自范围：全部读写按 counselor_id 过滤，越权一律 0 行而不是报错。
 *
 * 权限节点沿用 counselor_workspace：咨询师身份即授权，
 * 后台管理员（permissions 含 *）也可进入查看。
 */
class Casebook extends CounselorHome
{
    protected string $title = '个案管理';

    // ==================== 列表与新建 ====================

    public function index(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $status = (string)$req->get('status', '');
        $kw = trim((string)$req->get('q', ''));

        $rows = CaseService::listFor($cid, $status, $kw);
        // G5 口径：个案列表属于敏感查阅，一次一记（条目数 + 筛选条件）
        $this->audit('case.view_list', 'cases', null, ['count' => count($rows), 'status' => $status, 'kw' => $kw]);

        $this->view('counselor-home/cases', [
            'title' => '个案管理',
            'me' => $me,
            'rows' => $rows,
            'stats' => CaseService::stats($cid),
            'members' => CaseService::candidateMembers($cid),
            'status' => $status,
            'kw' => $kw,
            'riskLabels' => CasePolicy::RISK,
            'statusLabels' => CasePolicy::STATUS,
        ]);
    }

    public function save(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $cid = (int)$me['id'];
        $r = CaseService::open($cid, [
            'member_id' => $req->post('member_id', 0),
            'title' => $req->post('title', ''),
            'presenting' => $req->post('presenting', ''),
            'goals' => $req->post('goals', ''),
            'risk_level' => $req->post('risk_level', 'low'),
            'opened_at' => $req->post('opened_at', ''),
            'booking_id' => $req->post('booking_id', 0),
            'created_by' => (int)(Session::get('admin_user')['id'] ?? 0),
        ]);
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        $this->audit('case.open', 'cases', (int)$r['id'], [
            'member_id' => (int)$req->post('member_id', 0),
            'risk' => CasePolicy::normalizeRisk((string)$req->post('risk_level', 'low')),
        ]);
        Session::flash('success', (string)$r['msg']);
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . (int)$r['id']);
    }

    // ==================== 个案详情 ====================

    public function detail(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $case = CaseService::find((int)($p['id'] ?? 0), $cid);
        if (!$case) {
            Session::flash('error', '个案不存在或无权查看');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        $sessions = CaseService::sessionsOf((int)$case['id']);
        // 该来访者与本咨询师的预约，供会次关联对账
        $bookings = Database::fetchAll(
            'SELECT id, code, prefer_date, prefer_slot, status FROM bookings
             WHERE counselor_id=? AND phone=? ORDER BY prefer_date DESC, id DESC LIMIT 50',
            [$cid, (string)($case['m_phone'] ?? '')]
        );
        $this->audit('case.view', 'cases', (int)$case['id'], ['sessions' => count($sessions)]);

        $this->view('counselor-home/case', [
            'title' => '个案 ' . (string)$case['case_no'],
            'me' => $me,
            'case' => $case,
            'sessions' => $sessions,
            'bookings' => $bookings,
            'recordCount' => count(CaseRecordService::listFor((int)$case['id'], $cid)),
            'canWrite' => CasePolicy::canWrite($case, $cid),
            'riskLabels' => CasePolicy::RISK,
            'statusLabels' => CasePolicy::STATUS,
            'attendanceLabels' => CasePolicy::ATTENDANCE,
        ]);
    }

    public function addSession(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $cid = (int)$me['id'];
        $caseId = (int)($p['id'] ?? 0);
        $r = CaseService::addSession($caseId, $cid, [
            'held_at' => $req->post('held_at', ''),
            'duration_min' => $req->post('duration_min', 0),
            'method' => $req->post('method', ''),
            'attendance' => $req->post('attendance', 'held'),
            'topic' => $req->post('topic', ''),
            'booking_id' => $req->post('booking_id', 0),
        ]);
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
        } else {
            $this->audit('case.session', 'case_sessions', $caseId, [
                'seq' => (int)($r['seq'] ?? 0),
                'attendance' => CasePolicy::normalizeAttendance((string)$req->post('attendance', 'held')),
            ]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId);
    }

    public function close(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $r = CaseService::close($caseId, (int)$me['id'], (string)$req->post('close_reason', ''));
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
        } else {
            $this->audit('case.close', 'cases', $caseId, ['reason' => str_take(trim((string)$req->post('close_reason', '')), 200, '')]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId);
    }

    public function archive(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $r = CaseService::archive($caseId, (int)$me['id']);
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
        } else {
            $this->audit('case.archive', 'cases', $caseId);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId);
    }

    // ==================== 咨询记录（P1-B） ====================

    public function records(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $case = CaseService::find((int)($p['id'] ?? 0), $cid);
        if (!$case) {
            Session::flash('error', '个案不存在或无权查看');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        $rows = CaseRecordService::listFor((int)$case['id'], $cid);
        $edit = null;
        $editId = (int)$req->get('edit', 0);
        if ($editId > 0) {
            $edit = CaseRecordService::find($editId, $cid);
            if ($edit && (int)$edit['case_id'] !== (int)$case['id']) $edit = null;
            if ($edit && !CasePolicy::canEditRecord($edit, $cid)) {
                Session::flash('error', '该记录已锁定，不能编辑；如需修改请另开修订记录');
                $edit = null;
            }
        }
        $this->audit('case.record_list', 'case_records', null, ['case_id' => (int)$case['id'], 'count' => count($rows)]);
        $this->view('counselor-home/records', [
            'title' => '咨询记录 · ' . (string)$case['case_no'],
            'me' => $me,
            'case' => $case,
            'rows' => $rows,
            'edit' => $edit,
            'canWrite' => CasePolicy::canWrite($case, $cid),
            'sessions' => CaseService::sessionsOf((int)$case['id']),
            'recordTypes' => CasePolicy::RECORD_TYPE,
            'recordStatus' => CasePolicy::RECORD_STATUS,
        ]);
    }

    public function recordSave(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $cid = (int)$me['id'];
        $caseId = (int)($p['id'] ?? 0);
        $rid = (int)$req->post('record_id', 0);
        $r = CaseRecordService::save($caseId, $cid, [
            'title' => $req->post('title', ''),
            'content' => $req->post('content', ''),
            'record_type' => $req->post('record_type', 'summary'),
            'session_id' => $req->post('session_id', 0),
            'visible' => $req->post('visible', 0),
            'amends_id' => $req->post('amends_id', 0),
        ], $rid, (int)(Session::get('admin_user')['id'] ?? 0));
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
            \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records' . ($rid > 0 ? '?edit=' . $rid : ''));
        }
        $this->audit($rid > 0 ? 'case.record_update' : 'case.record_create', 'case_records', (int)$r['id'], [
            'case_id' => $caseId, 'version' => (int)($r['version'] ?? 1),
        ]);
        Session::flash('success', (string)$r['msg']);
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records/' . (int)$r['id']);
    }

    public function recordView(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $caseId = (int)($p['id'] ?? 0);
        $case = CaseService::find($caseId, $cid);
        if (!$case) {
            Session::flash('error', '个案不存在或无权查看');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        $rec = CaseRecordService::find((int)($p['rid'] ?? 0), $cid);
        if (!$rec || (int)$rec['case_id'] !== $caseId) {
            Session::flash('error', '记录不存在或无权查看');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records');
        }
        $versions = CaseRecordService::versionsOf((int)$rec['id']);
        $this->audit('case.record_view', 'case_records', (int)$rec['id'], ['case_id' => $caseId]);
        $this->view('counselor-home/record', [
            'title' => '记录 · ' . (string)$rec['title'],
            'me' => $me,
            'case' => $case,
            'rec' => $rec,
            'versions' => $versions,
            'canEdit' => CasePolicy::canEditRecord($rec, $cid) && CasePolicy::canWrite($case, $cid),
            'recordTypeLabel' => CasePolicy::recordTypeLabel((string)$rec['record_type']),
            'recordStatusLabel' => CasePolicy::recordStatusLabel((string)$rec['status']),
        ]);
    }

    public function recordLock(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $rid = (int)($p['rid'] ?? 0);
        $r = CaseRecordService::lock($rid, (int)$me['id'], (int)(Session::get('admin_user')['id'] ?? 0));
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
        } else {
            $this->audit('case.record_lock', 'case_records', $rid, ['case_id' => $caseId]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records/' . $rid);
    }

    public function recordAmend(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $rid = (int)($p['rid'] ?? 0);
        $r = CaseRecordService::startAmendment($rid, (int)$me['id']);
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
            \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records/' . $rid);
        }
        $this->audit('case.record_amend', 'case_records', (int)$r['id'], ['amends_id' => $rid, 'case_id' => $caseId]);
        Session::flash('success', '已创建修订草稿，修改后请锁定');
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records?edit=' . (int)$r['id']);
    }

    // ==================== AI 草稿（P1-C） ====================

    public function draft(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $case = CaseService::find((int)($p['id'] ?? 0), $cid);
        if (!$case) {
            Session::flash('error', '个案不存在或无权查看');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        $taskId = (int)$req->get('task', 0);
        $task = $taskId > 0 ? AiDraftService::find($taskId, $cid) : null;
        $this->audit('case.ai_draft_view', 'cases', (int)$case['id'], ['task' => $taskId]);
        $this->view('counselor-home/draft', [
            'title' => 'AI 草稿 · ' . (string)$case['case_no'],
            'me' => $me,
            'case' => $case,
            'task' => $task,
            'tasks' => AiDraftService::listFor((int)$case['id'], $cid),
            'available' => AiDraftService::available(),
            'blockReason' => AiDraftService::blockReason(),
            'scope' => AiDraftService::outboundScope(),
            'sessions' => CaseService::sessionsOf((int)$case['id']),
            'canWrite' => CasePolicy::canWrite($case, $cid),
        ]);
    }

    public function draftRun(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $cid = (int)$me['id'];
        $caseId = (int)($p['id'] ?? 0);
        $r = AiDraftService::run(
            $caseId, $cid,
            (string)$req->post('input_text', ''),
            (int)$req->post('session_id', 0),
            (int)(Session::get('admin_user')['id'] ?? 0)
        );
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
            \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/draft');
        }
        // 外发即留痕：谁、对哪个个案、脱敏了几处
        $this->audit('case.ai_draft_run', 'ai_tasks', (int)$r['id'], [
            'case_id' => $caseId, 'redacted' => (int)($r['redacted'] ?? 0),
        ]);
        Session::flash('success', (string)$r['msg']);
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/draft?task=' . (int)$r['id']);
    }

    public function draftAdopt(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $cid = (int)$me['id'];
        $caseId = (int)($p['id'] ?? 0);
        $tid = (int)($p['tid'] ?? 0);
        $r = AiDraftService::adopt($tid, $cid, (int)(Session::get('admin_user')['id'] ?? 0));
        if (empty($r['ok'])) {
            Session::flash('error', (string)$r['msg']);
            \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/draft?task=' . $tid);
        }
        $this->audit('case.ai_draft_adopt', 'case_records', (int)$r['record_id'], ['ai_task' => $tid, 'case_id' => $caseId]);
        Session::flash('success', (string)$r['msg']);
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/records?edit=' . (int)$r['record_id']);
    }
    // ==================== 危机 / 作业 / 随访（P1-D） ====================

    public function care(Request $req, array $p): void
    {
        $me = $this->me();
        $cid = (int)$me['id'];
        $case = CaseService::find((int)($p['id'] ?? 0), $cid);
        if (!$case) {
            Session::flash('error', '个案不存在或无权查看');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        $caseId = (int)$case['id'];
        $this->audit('case.care_view', 'cases', $caseId);
        $this->view('counselor-home/care', [
            'title' => '照护 · ' . (string)$case['case_no'],
            'me' => $me,
            'case' => $case,
            'crises' => CaseCareService::crisesOf($caseId, $cid),
            'homeworks' => CaseCareService::homeworksOf($caseId, $cid),
            'followups' => CaseCareService::followupsOf($caseId, $cid),
            'plans' => FollowupPlanService::plansOf($caseId, $cid),
            'planStatus' => FollowupPlanService::STATUS,
            'sessions' => CaseService::sessionsOf($caseId),
            'levels' => CaseCareService::CRISIS_LEVEL,
            'hwStatus' => CaseCareService::HW_STATUS,
            'fuStatus' => CaseCareService::FU_STATUS,
            'fuChannel' => CaseCareService::FU_CHANNEL,
            'statusLabels' => CasePolicy::STATUS,
            'canWrite' => CasePolicy::canWrite($case, $cid),
        ]);
    }

    public function crisisAdd(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $r = CaseCareService::addCrisis($caseId, (int)$me['id'], [
            'level' => $req->post('level', 'medium'),
            'summary' => $req->post('summary', ''),
            'action_taken' => $req->post('action_taken', ''),
        ], (int)(Session::get('admin_user')['id'] ?? 0));
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.crisis_open', 'crisis_events', (int)$r['id'], ['case_id' => $caseId, 'level' => (string)$req->post('level', 'medium')]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    public function crisisClose(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $eid = (int)($p['eid'] ?? 0);
        $r = CaseCareService::closeCrisis($eid, (int)$me['id'], (string)$req->post('closure_note', ''), (int)(Session::get('admin_user')['id'] ?? 0));
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.crisis_close', 'crisis_events', $eid, ['case_id' => $caseId]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    public function homeworkAdd(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        // 作业只在进行中的个案里布置；结案/归档后仍需联系用「随访」
        $case = CaseService::find($caseId, (int)$me['id']);
        if (!$case) {
            Session::flash('error', '个案不存在或无权操作');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases');
        }
        if ((string)$case['status'] !== 'active') {
            Session::flash('error', '个案已结案，不再布置新作业；如需继续联系请用「随访」');
            \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
        }
        $r = CaseCareService::addHomework($caseId, (int)$me['id'], [
            'title' => $req->post('title', ''),
            'content' => $req->post('content', ''),
            'due_at' => $req->post('due_at', ''),
            'session_id' => $req->post('session_id', 0),
        ]);
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.homework_add', 'case_homeworks', (int)$r['id'], ['case_id' => $caseId]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    public function homeworkDone(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $hid = (int)($p['hid'] ?? 0);
        $r = CaseCareService::finishHomework($hid, (int)$me['id'], (string)$req->post('feedback', ''), (string)$req->post('act', '') === 'cancel');
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.homework_finish', 'case_homeworks', $hid, ['case_id' => $caseId, 'cancel' => (string)$req->post('act', '') === 'cancel']);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    public function followupAdd(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $r = CaseCareService::addFollowup($caseId, (int)$me['id'], [
            'due_at' => $req->post('due_at', ''),
            'channel' => $req->post('channel', 'phone'),
            'purpose' => $req->post('purpose', ''),
        ]);
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.followup_add', 'case_followups', (int)$r['id'], ['case_id' => $caseId]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    public function followupDone(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $fid = (int)($p['fid'] ?? 0);
        $r = CaseCareService::finishFollowup($fid, (int)$me['id'], (string)$req->post('result', ''), (string)$req->post('act', '') === 'skip');
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.followup_finish', 'case_followups', $fid, ['case_id' => $caseId, 'skip' => (string)$req->post('act', '') === 'skip']);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    // ==================== 随访计划（P2-A） ====================

    public function planAdd(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $r = FollowupPlanService::create($caseId, (int)$me['id'], [
            'title' => $req->post('title', ''),
            'interval_days' => $req->post('interval_days', 7),
            'times' => $req->post('times', 3),
            'channel' => $req->post('channel', 'phone'),
            'purpose' => $req->post('purpose', ''),
        ]);
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.plan_add', 'followup_plans', (int)$r['id'], ['case_id' => $caseId]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }

    public function planStop(Request $req, array $p): void
    {
        Csrf::guard();
        $me = $this->me();
        $caseId = (int)($p['id'] ?? 0);
        $pid = (int)($p['pid'] ?? 0);
        $r = FollowupPlanService::stop($pid, (int)$me['id']);
        if (empty($r['ok'])) Session::flash('error', (string)$r['msg']);
        else {
            $this->audit('case.plan_stop', 'followup_plans', $pid, ['case_id' => $caseId]);
            Session::flash('success', (string)$r['msg']);
        }
        \app\Core\ResponseRedirect('/admin/counselor-home/cases/' . $caseId . '/care');
    }
}
