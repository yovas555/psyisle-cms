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

        $bookingId = null;
        $bookingRaw = $in['booking_id'] ?? null;
        if ($bookingRaw !== null && $bookingRaw !== '' && $bookingRaw !== 0 && $bookingRaw !== '0') {
            if (!is_scalar($bookingRaw)) throw new \InvalidArgumentException('关联预约编号不合法');
            if (trim((string)$bookingRaw) !== '') {
                $bookingId = filter_var($bookingRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($bookingId === false) throw new \InvalidArgumentException('关联预约编号不合法');
                $bookingId = (int)$bookingId;
            }
        }

        try {
            $id = Database::transaction(function () use ($counselorId, $memberId, $title, $in, $bookingId) {
                Database::run('SELECT id FROM members WHERE id=? FOR UPDATE', [$memberId]);
                if ($bookingId !== null) {
                    $booking = Database::fetch(
                        'SELECT b.* FROM bookings b JOIN members m ON m.phone=b.phone
                         WHERE b.id=? AND b.counselor_id=? AND m.id=? FOR UPDATE',
                        [$bookingId, $counselorId, $memberId]
                    );
                    if (!$booking) throw new \InvalidArgumentException('关联预约不存在或不属于该来访者与咨询师');
                }
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
        } catch (\InvalidArgumentException $e) {
            throw $e;
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
        return Database::transaction(function () use ($caseId, $counselorId, $reason): array {
            $case = Database::fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId]);
            if (!$case) return self::fail('个案不存在');
            if (!CasePolicy::owns($case, $counselorId)) return self::fail('无权操作该个案');
            if (!CasePolicy::canTransition((string)$case['status'], 'closed')) {
                return self::fail('当前状态不可结案（' . CasePolicy::statusLabel((string)$case['status']) . '）');
            }
            $changed = Database::run(
                "UPDATE cases SET status='closed', closed_at=NOW(), close_reason=?, updated_at=NOW() WHERE id=? AND counselor_id=? AND status='active'",
                [str_take(trim($reason), 200, ''), $caseId, $counselorId]
            );
            if ($changed->rowCount() !== 1) return self::fail('个案状态已变化，请刷新后重试');
            return ['ok' => true, 'msg' => '个案已结案'];
        });
    }

    /** 归档（结案后的收尾动作，只影响列表展示，不删数据） */
    public static function archive(int $caseId, int $counselorId): array
    {
        return Database::transaction(function () use ($caseId, $counselorId): array {
            $case = Database::fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId]);
            if (!$case) return self::fail('个案不存在');
            if (!CasePolicy::owns($case, $counselorId)) return self::fail('无权操作该个案');
            if (!CasePolicy::canTransition((string)$case['status'], 'archived')) {
                return self::fail('仅已结案的个案可归档');
            }
            $changed = Database::run(
                "UPDATE cases SET status='archived', updated_at=NOW() WHERE id=? AND counselor_id=? AND status='closed'",
                [$caseId, $counselorId]
            );
            if ($changed->rowCount() !== 1) return self::fail('个案状态已变化，请刷新后重试');
            return ['ok' => true, 'msg' => '个案已归档'];
        });
    }

    /** 登记会次 */
    public static function addSession(int $caseId, int $counselorId, array $in): array
    {
        $heldAt = $in['held_at'] ?? '';
        if (!is_string($heldAt) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $heldAt)) return self::fail('请选择有效的会次日期');
        $parsedDate = \DateTime::createFromFormat('!Y-m-d', $heldAt);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $heldAt) return self::fail('会次日期不存在');
        $bookingRaw = $in['booking_id'] ?? null;
        $bookingId = null;
        if ($bookingRaw !== null && $bookingRaw !== '' && $bookingRaw !== 0 && $bookingRaw !== '0') {
            if (!is_scalar($bookingRaw)) return self::fail('关联预约编号不合法');
            if (trim((string)$bookingRaw) !== '') {
                $bookingId = filter_var($bookingRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($bookingId === false) return self::fail('关联预约编号不合法');
                $bookingId = (int)$bookingId;
            }
        }
        $dur = (int)($in['duration_min'] ?? 0);
        $dur = ($dur >= 5 && $dur <= 600) ? $dur : null;

        try {
            return Database::transaction(function () use ($caseId, $counselorId, $heldAt, $dur, $in, $bookingId): array {
                $case = Database::fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId]);
                if (!$case) return self::fail('个案不存在');
                if (!CasePolicy::owns($case, $counselorId)) return self::fail('无权操作该个案');
                if (!CasePolicy::canWrite($case, $counselorId)) return self::fail('个案已结案，不能新增会次');
                if ($bookingId !== null) {
                    $booking = Database::fetch(
                        'SELECT b.* FROM bookings b JOIN members m ON m.id=? AND m.phone=b.phone WHERE b.id=? FOR UPDATE',
                        [(int)$case['member_id'], $bookingId]
                    );
                    if (!$booking || (int)$booking['counselor_id'] !== $counselorId) return self::fail('关联预约与个案会员或咨询师不一致');
                    if ((string)$booking['status'] !== 'completed') return self::fail('仅已完成预约可登记会次');
                    $linked = Database::fetch('SELECT id, case_id FROM case_sessions WHERE booking_id=? FOR UPDATE', [$bookingId]);
                    if ($linked) return self::fail('该预约已关联其他会次，不能重复登记');
                }
                $seq = (int)Database::value('SELECT COALESCE(MAX(seq),0)+1 FROM case_sessions WHERE case_id=?', [$caseId]);
                $id = Database::insert('case_sessions', [
                    'case_id' => $caseId,
                    'seq' => $seq,
                    'counselor_id' => $counselorId,
                    'booking_id' => $bookingId,
                    'held_at' => $heldAt,
                    'duration_min' => $dur,
                    'method' => str_take(trim((string)($in['method'] ?? '')), 20, ''),
                    'attendance' => CasePolicy::normalizeAttendance((string)($in['attendance'] ?? 'held')),
                    'topic' => str_take(trim((string)($in['topic'] ?? '')), 200, ''),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                if ($id <= 0) return self::fail('会次创建失败');
                $changed = Database::run(
                    'UPDATE cases SET session_count=session_count+1,
                        last_session_at = CASE WHEN last_session_at IS NULL OR last_session_at < ? THEN ? ELSE last_session_at END,
                        updated_at=NOW() WHERE id=? AND status=?',
                    [$heldAt, $heldAt, $caseId, 'active']
                );
                if ($changed->rowCount() !== 1) throw new \RuntimeException('个案状态已变化，请刷新后重试');
                return ['ok' => true, 'id' => $id, 'msg' => '已登记第 ' . $seq . ' 次会次', 'seq' => $seq];
            });
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return self::fail('该预约已关联会次或会次序号冲突，请刷新后重试');
            Logger::error('case', 'session failed: ' . $e->getMessage());
            return self::fail('会次登记失败，请稍后重试');
        } catch (\Throwable $t) {
            Logger::error('case', 'session failed: ' . $t->getMessage());
            return self::fail($t instanceof \RuntimeException ? $t->getMessage() : '会次登记失败，请稍后重试');
        }
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
