<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * 个案服务（P1-A）
 * ------------------------------------------------------------------
 * 职责：开案 / 结案 / 归档 / 会次登记 / 列表与统计。
 * 边界：不碰咨询记录正文（P1-B 的 case_records），不碰 AI（P1-C），不碰危机（P1-D）。
 *
 * 硬约束：
 *  1) 开案必须与该来访者存在预约关系（沿用 P0-G1 的 CounselorScope 判据），
 *     否则等于绕过工作台往任意会员挂个案。
 *  2) 同一来访者同时只能有一个 active 案（D1）。MySQL 5.7 无部分唯一索引，
 *     用 member 行 FOR UPDATE + 事务保证并发下也不破。
 *  3) 结案后不可写；复发另开新案，不重开旧案。
 */
class CaseService
{
    /** 开案 */
    public static function open(int $counselorId, array $in): array
    {
        $memberId = (int)($in['member_id'] ?? 0);
        $title = str_take(trim((string)($in['title'] ?? '')), 120, '');
        if ($counselorId <= 0) return self::fail('咨询师身份无效');
        if ($memberId <= 0) return self::fail('请选择来访者');
        if ($title === '') return self::fail('请填写个案名称（去标识，如「大一适应-初诊」）');
        if (!Database::value('SELECT id FROM members WHERE id=?', [$memberId])) return self::fail('来访者不存在');
        if (!CounselorScope::canAccessMember($counselorId, $memberId)) {
            Logger::error('case', 'denied open counselor=' . $counselorId . ' member=' . $memberId);
            return self::fail('仅可为与您有过预约的来访者开案');
        }

        $bookingId = (int)($in['booking_id'] ?? 0) ?: null;
        if ($bookingId !== null && !Database::value(
            'SELECT id FROM bookings WHERE id=? AND counselor_id=? AND phone=(SELECT phone FROM members WHERE id=?)',
            [$bookingId, $counselorId, $memberId]
        )) {
            $bookingId = null; // 关联预约对不上就丢弃，不阻断开案
        }

        try {
            $id = Database::transaction(function () use ($counselorId, $memberId, $title, $in, $bookingId) {
                // 锁住会员行：并发开案时只有一个能通过下面的 active 检查
                Database::run('SELECT id FROM members WHERE id=? FOR UPDATE', [$memberId]);
                $active = Database::fetch(
                    "SELECT id, case_no FROM cases WHERE member_id=? AND status='active' LIMIT 1",
                    [$memberId]
                );
                if ($active) {
                    throw new \RuntimeException('该来访者已有进行中的个案（' . $active['case_no'] . '），请先结案再开新案');
                }
                return Database::insert('cases', [
                    'case_no' => self::nextNo(),
                    'member_id' => $memberId,
                    'counselor_id' => $counselorId,
                    'booking_id' => $bookingId,
                    'title' => $title,
                    'presenting' => str_take(trim((string)($in['presenting'] ?? '')), 500, ''),
                    'goals' => str_take(trim((string)($in['goals'] ?? '')), 1000, ''),
                    'risk_level' => CasePolicy::normalizeRisk((string)($in['risk_level'] ?? 'low')),
                    'status' => 'active',
                    'opened_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['opened_at'] ?? ''))
                        ? (string)$in['opened_at'] : date('Y-m-d'),
                    'session_count' => 0,
                    'created_by' => (int)($in['created_by'] ?? 0) ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (\RuntimeException $e) {
            return self::fail($e->getMessage());
        } catch (\Throwable $t) {
            Logger::error('case', 'open failed: ' . $t->getMessage());
            return self::fail('开案失败，请稍后重试');
        }
        return ['ok' => true, 'id' => $id, 'msg' => '个案已建立'];
    }

    /** 结案 */
    public static function close(int $caseId, int $counselorId, string $reason): array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return self::fail('个案不存在');
        if (!CasePolicy::owns($case, $counselorId)) return self::fail('无权操作该个案');
        if (!CasePolicy::canTransition((string)$case['status'], 'closed')) {
            return self::fail('当前状态不可结案（' . CasePolicy::statusLabel((string)$case['status']) . '）');
        }
        Database::transaction(function () use ($caseId, $reason) {
            Database::run('SELECT id FROM cases WHERE id=? FOR UPDATE', [$caseId]);
            Database::run(
                "UPDATE cases SET status='closed', closed_at=NOW(), close_reason=?, updated_at=NOW() WHERE id=? AND status='active'",
                [str_take(trim($reason), 200, ''), $caseId]
            );
        });
        return ['ok' => true, 'msg' => '个案已结案'];
    }

    /** 归档（结案后的收尾动作，只影响列表展示，不删数据） */
    public static function archive(int $caseId, int $counselorId): array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return self::fail('个案不存在');
        if (!CasePolicy::owns($case, $counselorId)) return self::fail('无权操作该个案');
        if (!CasePolicy::canTransition((string)$case['status'], 'archived')) {
            return self::fail('仅已结案的个案可归档');
        }
        Database::run("UPDATE cases SET status='archived', updated_at=NOW() WHERE id=?", [$caseId]);
        return ['ok' => true, 'msg' => '个案已归档'];
    }

    /** 登记会次 */
    public static function addSession(int $caseId, int $counselorId, array $in): array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return self::fail('个案不存在');
        if (!CasePolicy::owns($case, $counselorId)) {
            Logger::error('case', 'denied session counselor=' . $counselorId . ' case=' . $caseId);
            return self::fail('无权操作该个案');
        }
        if (!CasePolicy::canWrite($case, $counselorId)) return self::fail('个案已结案，不能新增会次');

        $heldAt = (string)($in['held_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $heldAt)) $heldAt = date('Y-m-d');
        $dur = (int)($in['duration_min'] ?? 0);
        $dur = ($dur >= 5 && $dur <= 600) ? $dur : null;

        try {
            $seq = Database::transaction(function () use ($caseId, $counselorId, $heldAt, $dur, $in) {
                Database::run('SELECT id FROM cases WHERE id=? FOR UPDATE', [$caseId]);
                $seq = (int)Database::value('SELECT COALESCE(MAX(seq),0)+1 FROM case_sessions WHERE case_id=?', [$caseId]);
                Database::insert('case_sessions', [
                    'case_id' => $caseId,
                    'seq' => $seq,
                    'counselor_id' => $counselorId,
                    'booking_id' => (int)($in['booking_id'] ?? 0) ?: null,
                    'held_at' => $heldAt,
                    'duration_min' => $dur,
                    'method' => str_take(trim((string)($in['method'] ?? '')), 20, ''),
                    'attendance' => CasePolicy::normalizeAttendance((string)($in['attendance'] ?? 'held')),
                    'topic' => str_take(trim((string)($in['topic'] ?? '')), 200, ''),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Database::run(
                    'UPDATE cases SET session_count=session_count+1,
                        last_session_at = CASE WHEN last_session_at IS NULL OR last_session_at < ? THEN ? ELSE last_session_at END,
                        updated_at=NOW() WHERE id=?',
                    [$heldAt, $heldAt, $caseId]
                );
                return $seq;
            });
        } catch (\Throwable $t) {
            Logger::error('case', 'session failed: ' . $t->getMessage());
            return self::fail('会次登记失败，请稍后重试');
        }
        return ['ok' => true, 'msg' => '已登记第 ' . $seq . ' 次会次', 'seq' => $seq];
    }

    /** 单个个案（含来访者信息），带归属检查 */
    public static function find(int $caseId, int $counselorId): ?array
    {
        $row = Database::fetch(
            'SELECT c.*, m.phone AS m_phone, m.name AS m_name, m.email AS m_email
             FROM cases c LEFT JOIN members m ON m.id=c.member_id WHERE c.id=?', [$caseId]);
        if (!$row) return null;
        if (!CasePolicy::canRead($row, $counselorId)) return null;
        return $row;
    }

    /** 个案列表（自范围） */
    public static function listFor(int $counselorId, string $status = '', string $kw = '', int $limit = 200): array
    {
        $where = 'c.counselor_id=?';
        $params = [$counselorId];
        if (isset(CasePolicy::STATUS[$status])) { $where .= ' AND c.status=?'; $params[] = $status; }
        if ($kw !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
            $where .= ' AND (c.title LIKE ? OR c.case_no LIKE ? OR m.phone LIKE ? OR m.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return Database::fetchAll(
            "SELECT c.*, m.phone AS m_phone, m.name AS m_name
             FROM cases c LEFT JOIN members m ON m.id=c.member_id
             WHERE $where
             ORDER BY FIELD(c.status,'active','closed','archived'), c.updated_at DESC, c.id DESC
             LIMIT " . max(1, min(500, $limit)),
            $params
        );
    }

    /** 会次列表 */
    public static function sessionsOf(int $caseId): array
    {
        return Database::fetchAll(
            'SELECT s.*, b.code AS booking_code FROM case_sessions s
             LEFT JOIN bookings b ON b.id=s.booking_id
             WHERE s.case_id=? ORDER BY s.seq DESC, s.id DESC LIMIT 300', [$caseId]);
    }

    /** 工作台统计 */
    public static function stats(int $counselorId): array
    {
        return [
            'active' => (int)Database::value("SELECT COUNT(*) FROM cases WHERE counselor_id=? AND status='active'", [$counselorId]),
            'closed' => (int)Database::value("SELECT COUNT(*) FROM cases WHERE counselor_id=? AND status='closed'", [$counselorId]),
            'high_risk' => (int)Database::value("SELECT COUNT(*) FROM cases WHERE counselor_id=? AND status='active' AND risk_level='high'", [$counselorId]),
            'sessions_month' => (int)Database::value(
                "SELECT COUNT(*) FROM case_sessions WHERE counselor_id=? AND held_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
                [$counselorId]
            ),
        ];
    }

    /** 可开案的来访者（与笔记同口径：有过预约关系） */
    public static function candidateMembers(int $counselorId, int $limit = 200): array
    {
        return Database::fetchAll(
            "SELECT DISTINCT m.id, m.phone, m.name FROM members m
             WHERE m.phone IN (SELECT phone FROM bookings WHERE counselor_id=?)
             ORDER BY m.id DESC LIMIT " . max(1, min(500, $limit)),
            [$counselorId]
        );
    }

    // ==================== 内部 ====================

    /** 个案编号：C + 年月日 + '-' + 当日序号（并发靠唯一键兜底重试） */
    private static function nextNo(): string
    {
        $prefix = 'C' . date('Ymd') . '-';
        for ($i = 0; $i < 5; $i++) {
            $max = (string)(Database::value(
                'SELECT MAX(case_no) FROM cases WHERE case_no LIKE ?', [$prefix . '%']) ?? '');
            $n = $max !== '' ? ((int)substr($max, strlen($prefix)) + 1) : 1;
            $no = $prefix . str_pad((string)($n + $i), 3, '0', STR_PAD_LEFT);
            if (!Database::value('SELECT id FROM cases WHERE case_no=?', [$no])) return $no;
        }
        return $prefix . substr((string)microtime(true), -6);
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }
}
