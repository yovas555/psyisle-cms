<?php
declare(strict_types=1);

namespace app\Services;

/**
 * 个案权限与状态机（P1-A）
 * ------------------------------------------------------------------
 * 单一事实源：凡是「谁能看 / 谁能写 / 什么状态能变什么状态」的判断都放这里，
 * 控制器与服务层只调用，不各自写 if，避免两处口径跑偏。
 *
 * 自范围原则（沿用咨询师工作台 v2.10.0）：咨询师只见自己名下个案。
 * 结案后不可再写；复发不重开旧案，另开新案（D1 口径）。
 */
class CasePolicy
{
    public const RISK = ['low' => '低', 'medium' => '中', 'high' => '高'];
    public const STATUS = ['active' => '进行中', 'closed' => '已结案', 'archived' => '已归档'];
    public const ATTENDANCE = ['held' => '已进行', 'no_show' => '未到', 'cancelled' => '已取消'];

    /** 允许的状态迁移：active→closed，closed→archived。不提供重开（复发开新案）。 */
    public static function canTransition(string $from, string $to): bool
    {
        $map = ['active' => ['closed'], 'closed' => ['archived'], 'archived' => []];
        return in_array($to, $map[$from] ?? [], true);
    }

    public static function normalizeRisk(string $v): string
    {
        return isset(self::RISK[$v]) ? $v : 'low';
    }

    public static function normalizeAttendance(string $v): string
    {
        return isset(self::ATTENDANCE[$v]) ? $v : 'held';
    }

    public static function riskLabel(string $v): string
    {
        return self::RISK[$v] ?? $v;
    }

    public static function statusLabel(string $v): string
    {
        return self::STATUS[$v] ?? $v;
    }

    /** 归属：个案主责咨询师本人 */
    public static function owns(array $case, int $counselorId): bool
    {
        return $counselorId > 0 && (int)($case['counselor_id'] ?? 0) === $counselorId;
    }

    /** 可读：本人名下（机构负责人跨咨询师查阅在 P1-D 驾驶舱里单独开口子，不在此处放宽） */
    public static function canRead(array $case, int $counselorId): bool
    {
        return self::owns($case, $counselorId);
    }

    /** 可写：本人名下且个案仍在进行中 */
    public static function canWrite(array $case, int $counselorId): bool
    {
        return self::owns($case, $counselorId) && (string)($case['status'] ?? '') === 'active';
    }

    // ==================== 咨询记录（P1-B） ====================

    public const RECORD_STATUS = ['draft' => '草稿', 'locked' => '已锁定'];
    public const RECORD_TYPE = [
        'summary' => '咨询小结',
        'soap' => '结构化记录（SOAP）',
        'assessment' => '评估记录',
        'plan' => '咨询计划',
    ];

    public static function recordTypeLabel(string $v): string
    {
        return self::RECORD_TYPE[$v] ?? $v;
    }

    public static function recordStatusLabel(string $v): string
    {
        return self::RECORD_STATUS[$v] ?? $v;
    }

    /** 可读记录：本人名下 */
    public static function canReadRecord(array $record, int $counselorId): bool
    {
        return $counselorId > 0 && (int)($record['counselor_id'] ?? 0) === $counselorId;
    }

    /** 可改记录：本人名下且仍是草稿（锁定后只能另开修订） */
    public static function canEditRecord(array $record, int $counselorId): bool
    {
        return self::canReadRecord($record, $counselorId) && (string)($record['status'] ?? '') === 'draft';
    }
}
