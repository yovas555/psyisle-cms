<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * 个案照护（P1-D）：危机事件 / 作业 / 随访
 * ------------------------------------------------------------------
 * 设计要点：
 *  * 危机事件是**状态机**不是日志：open → closed，关闭必须写结论；不回退、不删除。
 *  * 作业与随访是轻量任务，状态只前进一步（assigned→done/cancelled、pending→done/skipped）。
 *  * 所有方法都按 counselor_id 自范围校验；返回 ['ok'=>bool,'msg'=>string]。
 */
class CaseCareService
{
    public const CRISIS_LEVEL = ['low' => '低', 'medium' => '中', 'high' => '高', 'urgent' => '紧急'];
    public const HW_STATUS = ['assigned' => '待完成', 'done' => '已完成', 'cancelled' => '已取消'];
    public const FU_STATUS = ['pending' => '待随访', 'done' => '已随访', 'skipped' => '已跳过'];
    public const FU_CHANNEL = ['phone' => '电话', 'wechat' => '微信', 'sms' => '短信', 'visit' => '面谈'];

    // ==================== 危机事件 ====================

    public static function addCrisis(int $caseId, int $counselorId, array $in, int $editorId = 0): array
    {
        $case = self::ownedCase($caseId, $counselorId);
        if (!$case) return self::fail('个案不存在或无权操作');
        $summary = str_take(trim((string)($in['summary'] ?? '')), 500, '');
        if ($summary === '') return self::fail('请填写事件与表现');
        $level = isset(self::CRISIS_LEVEL[(string)($in['level'] ?? '')]) ? (string)$in['level'] : 'medium';

        $id = Database::insert('crisis_events', [
            'case_id' => $caseId,
            'counselor_id' => $counselorId,
            'member_id' => (int)$case['member_id'],
            'level' => $level,
            'occurred_at' => date('Y-m-d H:i:s'),
            'summary' => $summary,
            'action_taken' => str_take(trim((string)($in['action_taken'] ?? '')), 1000, ''),
            'status' => 'open',
            'created_by' => $editorId ?: null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id, 'msg' => '危机事件已登记，闭环前会一直在工作台提醒'];
    }

    public static function closeCrisis(int $crisisId, int $counselorId, string $note, int $editorId = 0): array
    {
        $ev = Database::fetch('SELECT * FROM crisis_events WHERE id=?', [$crisisId]);
        if (!$ev) return self::fail('事件不存在');
        if ((int)$ev['counselor_id'] !== $counselorId) return self::fail('无权操作该事件');
        if ((string)$ev['status'] !== 'open') return self::fail('该事件已闭环');
        $note = trim($note);
        if ($note === '') return self::fail('闭环必须写处置结论（如：已联系监护人并转介）');
        Database::run(
            "UPDATE crisis_events SET status='closed', closed_at=NOW(), closed_by=?, closure_note=?, updated_at=NOW() WHERE id=? AND status='open'",
            [$editorId ?: null, str_take($note, 500, ''), $crisisId]
        );
        return ['ok' => true, 'msg' => '危机事件已闭环'];
    }

    public static function crisesOf(int $caseId, int $counselorId): array
    {
        // 未闭环优先（不能用 status ASC：字典序下 closed 会排到 open 前面）
        return Database::fetchAll(
            "SELECT * FROM crisis_events WHERE case_id=? AND counselor_id=?
             ORDER BY (status='open') DESC, FIELD(level,'urgent','high','medium','low'), id DESC LIMIT 100",
            [$caseId, $counselorId]
        );
    }

    // ==================== 作业 ====================

    public static function addHomework(int $caseId, int $counselorId, array $in): array
    {
        $case = self::ownedCase($caseId, $counselorId, ['active']);
        if (!$case) return self::fail('个案不存在、无权操作或已结案（结案后请联系用「随访」）');
        $title = str_take(trim((string)($in['title'] ?? '')), 120, '');
        if ($title === '') return self::fail('请填写作业标题');
        $due = (string)($in['due_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null;
        $sid = (int)($in['session_id'] ?? 0) ?: null;
        if ($sid !== null && !Database::value('SELECT id FROM case_sessions WHERE id=? AND case_id=?', [$sid, $caseId])) $sid = null;

        $id = Database::insert('case_homeworks', [
            'case_id' => $caseId, 'session_id' => $sid, 'counselor_id' => $counselorId,
            'title' => $title,
            'content' => str_take(trim((string)($in['content'] ?? '')), 1000, ''),
            'assigned_at' => date('Y-m-d'),
            'due_at' => $due,
            'status' => 'assigned',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id, 'msg' => '作业已布置'];
    }

    public static function finishHomework(int $hwId, int $counselorId, string $feedback, bool $cancel = false): array
    {
        $hw = Database::fetch('SELECT * FROM case_homeworks WHERE id=?', [$hwId]);
        if (!$hw) return self::fail('作业不存在');
        if ((int)$hw['counselor_id'] !== $counselorId) return self::fail('无权操作该作业');
        if ((string)$hw['status'] !== 'assigned') return self::fail('该作业已结束');
        $status = $cancel ? 'cancelled' : 'done';
        Database::run(
            'UPDATE case_homeworks SET status=?, completed_at=NOW(), feedback=?, updated_at=NOW() WHERE id=?',
            [$status, str_take(trim($feedback), 500, ''), $hwId]
        );
        return ['ok' => true, 'msg' => $cancel ? '作业已取消' : '作业已标记完成'];
    }

    public static function homeworksOf(int $caseId, int $counselorId): array
    {
        // 待完成优先
        return Database::fetchAll(
            "SELECT h.*, s.seq AS session_seq FROM case_homeworks h
             LEFT JOIN case_sessions s ON s.id=h.session_id
             WHERE h.case_id=? AND h.counselor_id=?
             ORDER BY (h.status='assigned') DESC, h.id DESC LIMIT 100",
            [$caseId, $counselorId]
        );
    }

    // ==================== 随访 ====================

    public static function addFollowup(int $caseId, int $counselorId, array $in): array
    {
        $due = (string)($in['due_at'] ?? '');
        if (!self::validDate($due)) return self::fail('请选择有效的随访日期');
        $bookingId = self::optionalId($in['booking_id'] ?? 0);
        $sessionId = self::optionalId($in['session_id'] ?? 0);
        if ($bookingId < 0 || $sessionId < 0) return self::fail('来源预约或会次编号不合法');
        $source = (string)($in['source'] ?? 'manual');
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $source)) return self::fail('随访来源不合法');
        $sourceKeyRaw = $in['source_key'] ?? '';
        if (!is_string($sourceKeyRaw)) return self::fail('随访来源幂等键不合法');
        $sourceKey = trim($sourceKeyRaw);
        if (mb_strlen($sourceKey, 'UTF-8') > 190) return self::fail('随访来源幂等键不合法');
        if ($source === 'booking_feedback' && $bookingId === 0) return self::fail('反馈随访必须关联预约');
        $ch = isset(self::FU_CHANNEL[(string)($in['channel'] ?? '')]) ? (string)$in['channel'] : 'phone';

        try {
            return self::transaction(function () use ($caseId, $counselorId, $due, $bookingId, $sessionId, $source, $sourceKey, $ch, $in): array {
                $case = Database::fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId]);
                if (!$case || !CasePolicy::owns($case, $counselorId) || !in_array((string)$case['status'], ['active', 'closed'], true)) {
                    return self::fail('个案不存在、无权操作或状态不可安排随访');
                }
                $member = Database::fetch("SELECT * FROM members WHERE id=? AND status='active' FOR UPDATE", [(int)$case['member_id']]);
                if (!$member) return self::fail('来访者会员无效');

                $booking = null;
                if ($bookingId > 0) {
                    $booking = Database::fetch('SELECT * FROM bookings WHERE id=? FOR UPDATE', [$bookingId]);
                    if (!$booking || (int)$booking['counselor_id'] !== $counselorId || (string)$booking['phone'] !== (string)$member['phone']) {
                        return self::fail('来源预约与个案会员或咨询师不一致');
                    }
                    if ($source === 'booking_feedback' && (string)$booking['status'] !== 'completed') {
                        return self::fail('反馈随访只能关联已完成的预约');
                    }
                }
                $session = null;
                if ($sessionId > 0) {
                    $session = Database::fetch('SELECT * FROM case_sessions WHERE id=? FOR UPDATE', [$sessionId]);
                    if (!$session || (int)$session['case_id'] !== $caseId || (int)$session['counselor_id'] !== $counselorId) {
                        return self::fail('来源会次与个案或咨询师不一致');
                    }
                    if ($booking && (int)$session['booking_id'] !== $bookingId) return self::fail('来源预约与会次不一致');
                    if ($source === 'booking_feedback' && $booking === null && (int)$session['booking_id'] !== $bookingId) {
                        return self::fail('反馈随访必须关联预约');
                    }
                }
                if ($sourceKey !== '') {
                    $existing = Database::fetch('SELECT * FROM case_followups WHERE source_key=?', [$sourceKey]);
                    if ($existing) {
                        if ((int)$existing['case_id'] !== $caseId || (int)$existing['counselor_id'] !== $counselorId || (string)$existing['source'] !== $source) {
                            return self::fail('来源幂等键已用于其他随访');
                        }
                        return ['ok' => true, 'id' => (int)$existing['id'], 'msg' => '随访已存在', 'existing' => true];
                    }
                }
                $id = Database::insert('case_followups', [
                    'case_id' => $caseId,
                    'booking_id' => $bookingId ?: null,
                    'session_id' => $sessionId ?: null,
                    'source' => $source,
                    'source_key' => $sourceKey !== '' ? $sourceKey : null,
                    'counselor_id' => $counselorId,
                    'due_at' => $due,
                    'channel' => $ch,
                    'purpose' => str_take(trim((string)($in['purpose'] ?? '')), 200, ''),
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                if ($id <= 0) return self::fail('随访创建失败');
                return ['ok' => true, 'id' => $id, 'msg' => '随访已安排'];
            });
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && $sourceKey !== '') {
                $existing = Database::fetch('SELECT * FROM case_followups WHERE source_key=?', [$sourceKey]);
                if ($existing && (int)$existing['case_id'] === $caseId && (int)$existing['counselor_id'] === $counselorId && (string)$existing['source'] === $source) {
                    return ['ok' => true, 'id' => (int)$existing['id'], 'msg' => '随访已存在', 'existing' => true];
                }
            }
            throw $e;
        }
    }

    public static function finishFollowup(int $fuId, int $counselorId, int $caseId, string $result, bool $skip = false): array
    {
        if ($caseId <= 0) return self::fail('个案不存在或无权操作');
        $status = $skip ? 'skipped' : 'done';
        if (!$skip && trim($result) === '') return self::fail('请简要填写随访结果');
        return Database::transaction(function () use ($fuId, $counselorId, $caseId, $result, $skip, $status): array {
            $fu = Database::fetch('SELECT * FROM case_followups WHERE id=? AND case_id=? AND counselor_id=? FOR UPDATE', [$fuId, $caseId, $counselorId]);
            if (!$fu) return self::fail('随访不存在或不属于该个案');
            if ((string)$fu['status'] !== 'pending') return self::fail('该随访已处理');
            $changed = Database::run(
                'UPDATE case_followups SET status=?, done_at=NOW(), result=?, updated_at=NOW() WHERE id=? AND case_id=? AND counselor_id=? AND status=?',
                [$status, str_take(trim($result), 500, ''), $fuId, $caseId, $counselorId, 'pending']
            );
            if ($changed->rowCount() !== 1) return self::fail('随访状态已变化，请刷新后重试');
            return ['ok' => true, 'msg' => $skip ? '随访已跳过' : '随访已完成'];
        });
    }

    public static function followupsOf(int $caseId, int $counselorId): array
    {
        // 待随访优先，再按计划日期
        return Database::fetchAll(
            "SELECT * FROM case_followups WHERE case_id=? AND counselor_id=?
             ORDER BY (status='pending') DESC, due_at ASC, id DESC LIMIT 100",
            [$caseId, $counselorId]
        );
    }

    // ==================== 汇总（工作台首页用） ====================

    public static function overview(int $counselorId): array
    {
        $open = Database::fetchAll(
            "SELECT e.*, c.case_no, c.title AS case_title, c.member_id
             FROM crisis_events e JOIN cases c ON c.id=e.case_id
             WHERE e.counselor_id=? AND e.status='open'
             ORDER BY FIELD(e.level,'urgent','high','medium','low'), e.id DESC LIMIT 20", [$counselorId]);
        $dueFu = Database::fetchAll(
            "SELECT f.*, c.case_no, c.title AS case_title FROM case_followups f JOIN cases c ON c.id=f.case_id
             WHERE f.counselor_id=? AND f.status='pending' AND f.due_at <= CURDATE()
             ORDER BY f.due_at ASC LIMIT 20", [$counselorId]);
        $overdueHw = (int)Database::value(
            "SELECT COUNT(*) FROM case_homeworks WHERE counselor_id=? AND status='assigned' AND due_at IS NOT NULL AND due_at < CURDATE()",
            [$counselorId]);
        return ['open_crises' => $open, 'due_followups' => $dueFu, 'overdue_homeworks' => $overdueHw];
    }

    // ==================== 内部 ====================

    private static function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function optionalId($value): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return 0;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? -1 : (int)$id;
    }

    private static function transaction(callable $fn): array
    {
        return Database::pdo()->inTransaction() ? $fn() : Database::transaction($fn);
    }

    private static function ownedCase(int $caseId, int $counselorId, array $allowStatus = ['active', 'closed']): ?array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return null;
        if (!CasePolicy::owns($case, $counselorId)) {
            Logger::error('case', 'denied care access counselor=' . $counselorId . ' case=' . $caseId);
            return null;
        }
        if (!in_array((string)$case['status'], $allowStatus, true)) {
            Logger::error('case', 'care blocked by status=' . (string)$case['status'] . ' case=' . $caseId);
            return null;
        }
        return $case;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }
}
