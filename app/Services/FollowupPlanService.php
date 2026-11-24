<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * 随访计划（P2-A）：把 P1-D 的「单次随访」升级为「计划」。
 * ------------------------------------------------------------------
 *  * 计划 = 模板（间隔/次数/渠道），生成器每日把到期计划实例化为 case_followups 待办。
 *  * 生成器幂等：按 next_due_at 递推 + generated_count 封顶，漏跑一天也不会重复或超发。
 *  * 权限：一律按 counselor_id 自范围校验（复用 CasePolicy::owns）。
 */
class FollowupPlanService
{
    public const STATUS = ['active' => '进行中', 'done' => '已完成', 'stopped' => '已停止'];

    /** 建计划：结案后可建，active/closed 案均可 */
    public static function create(int $caseId, int $counselorId, array $in): array
    {
        $case = self::ownedCase($caseId, $counselorId, ['active', 'closed']);
        if (!$case) return self::fail('个案不存在或无权操作');
        $interval = (int)($in['interval_days'] ?? 7);
        $interval = max(1, min(365, $interval));
        $times = (int)($in['times'] ?? 3);
        $times = max(1, min(52, $times));
        $ch = isset(CaseCareService::FU_CHANNEL[(string)($in['channel'] ?? '')]) ? (string)$in['channel'] : 'phone';
        $title = str_take(trim((string)($in['title'] ?? '')), 120, '结案后随访');
        if ($title === '') $title = '结案后随访';

        $id = Database::insert('followup_plans', [
            'case_id' => $caseId,
            'counselor_id' => $counselorId,
            'title' => $title,
            'interval_days' => $interval,
            'times' => $times,
            'channel' => $ch,
            'purpose' => str_take(trim((string)($in['purpose'] ?? '')), 200, ''),
            'status' => 'active',
            'next_due_at' => date('Y-m-d', strtotime('+' . $interval . ' days')), // 首访偏移 = 间隔
            'generated_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id, 'msg' => '随访计划已建立（每 ' . $interval . ' 天一次，共 ' . $times . ' 次）'];
    }

    /** 停止计划（不改历史已生成的随访） */
    public static function stop(int $planId, int $counselorId): array
    {
        $p = Database::fetch('SELECT * FROM followup_plans WHERE id=?', [$planId]);
        if (!$p) return self::fail('计划不存在');
        if ((int)$p['counselor_id'] !== $counselorId) return self::fail('无权操作该计划');
        if ((string)$p['status'] !== 'active') return self::fail('该计划已结束');
        Database::run("UPDATE followup_plans SET status='stopped', updated_at=NOW() WHERE id=? AND status='active'", [$planId]);
        return ['ok' => true, 'msg' => '随访计划已停止'];
    }

    public static function plansOf(int $caseId, int $counselorId): array
    {
        return Database::fetchAll(
            "SELECT * FROM followup_plans WHERE case_id=? AND counselor_id=?
             ORDER BY (status='active') DESC, id DESC LIMIT 50",
            [$caseId, $counselorId]
        );
    }

    /**
     * 生成器（cron 每日调用）：把到期计划实例化为 case_followups 待办。
     * 幂等：next_due_at 递推、generated_count 封顶；满额自动转 done。
     * @return array{ok:bool, generated:int, completed:int}
     */
    public static function generateDue(): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM followup_plans WHERE status='active' AND next_due_at IS NOT NULL
               AND next_due_at <= CURDATE() AND generated_count < times
             ORDER BY next_due_at ASC LIMIT 500"
        );
        $generated = 0;
        $completed = 0;
        foreach ($rows as $p) {
            $planId = (int)$p['id'];
            $due = (string)$p['next_due_at'];
            try {
                Database::transaction(function () use ($p, $planId, $due, &$generated, &$completed) {
                    // 加锁重读，防并发 cron 重复生成
                    $cur = Database::fetch('SELECT * FROM followup_plans WHERE id=? FOR UPDATE', [$planId]);
                    if (!$cur || (string)$cur['status'] !== 'active') return;
                    if ((string)$cur['next_due_at'] > date('Y-m-d')) return;             // 已被推进
                    if ((int)$cur['generated_count'] >= (int)$cur['times']) return;      // 已满
                    $case = Database::fetch('SELECT id, status FROM cases WHERE id=?', [(int)$cur['case_id']]);
                    if (!$case || (string)$case['status'] === 'archived') return;        // 个案已归档则不再生成

                    Database::insert('case_followups', [
                        'case_id' => (int)$cur['case_id'],
                        'counselor_id' => (int)$cur['counselor_id'],
                        'due_at' => $due,
                        'channel' => (string)$cur['channel'],
                        'purpose' => str_take((string)($cur['title'] . ($cur['purpose'] ? '：' . $cur['purpose'] : '')), 200, ''),
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                    $nextCount = (int)$cur['generated_count'] + 1;
                    $nextDue = date('Y-m-d', strtotime($due . ' +' . (int)$cur['interval_days'] . ' days'));
                    $nextStatus = $nextCount >= (int)$cur['times'] ? 'done' : 'active';
                    Database::run(
                        'UPDATE followup_plans SET generated_count=?, next_due_at=?, status=?, updated_at=NOW() WHERE id=?',
                        [$nextCount, $nextStatus === 'done' ? null : $nextDue, $nextStatus, $planId]
                    );
                    $generated++;
                    if ($nextStatus === 'done') $completed++;
                });
            } catch (\Throwable $t) {
                Logger::error('followup-plan', 'generate plan=' . $planId . ': ' . $t->getMessage());
            }
        }
        return ['ok' => true, 'generated' => $generated, 'completed' => $completed];
    }

    // ==================== 内部 ====================

    private static function ownedCase(int $caseId, int $counselorId, array $allowStatus): ?array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return null;
        if (!CasePolicy::owns($case, $counselorId)) {
            Logger::error('followup-plan', 'denied access counselor=' . $counselorId . ' case=' . $caseId);
            return null;
        }
        if (!in_array((string)$case['status'], $allowStatus, true)) return null;
        return $case;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }
}
