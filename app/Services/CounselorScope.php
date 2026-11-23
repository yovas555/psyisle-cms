<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

/**
 * 咨询师数据域判据（P0 安全门禁 G1）
 *
 * 背景：工作台原先写记录时只校验"会员存在"，不校验归属，咨询师可向任意 member_id 写入。
 * 现状判据：该会员与本咨询师之间存在预约关系（bookings 以 phone 关联会员，非 member_id）。
 * 演进：P1 引入 cases 后，本判据由 CasePolicy 接管，扩展为"存在活跃或历史个案"；
 *       届时本类保留为兜底（历史数据没有个案行时可回退到预约关系）。
 */
class CounselorScope
{
    /** 咨询师是否可访问该会员（写入记录、查看个案等统一走这里） */
    public static function canAccessMember(int $counselorId, int $memberId): bool
    {
        if ($counselorId <= 0 || $memberId <= 0) return false;
        $phone = (string)Database::value('SELECT phone FROM members WHERE id=?', [$memberId]);
        if ($phone === '') return false;
        return (bool)Database::value(
            'SELECT 1 FROM bookings WHERE counselor_id=? AND phone=? LIMIT 1',
            [$counselorId, $phone]
        );
    }

    /** 该会员是否属于本咨询师（用于列表过滤，返回会员 id 集合） */
    public static function memberIdsOf(int $counselorId): array
    {
        if ($counselorId <= 0) return [];
        $rows = Database::fetchAll(
            'SELECT DISTINCT m.id FROM members m
             WHERE m.phone IN (SELECT phone FROM bookings WHERE counselor_id=?)',
            [$counselorId]
        );
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }
}
