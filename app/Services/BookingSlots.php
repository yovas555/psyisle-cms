<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

/**
 * 统一可约时段计算（v2.17.0）
 * ------------------------------------------------------------------
 * 单一事实来源：周模板（counselor_slots，按 slot_minutes 切片）+ 单日例外
 * （schedule_overrides：closed 停诊 / open 限定时段）+ 请假（on_leave）
 * + 提前截止（sched.lead_hours）+ 已占槽（bookings pending/confirmed 精确
 * 匹配「HH:MM-HH:MM」）+ 缓冲（sched.buffer_minutes）+ 每日上限（sched.max_per_day）。
 *
 * 咨询师详情页日历、/booking/slots AJAX、确认页三处全部走这里，
 * 替代原先三处各自为政的模板推算（不套用例外/请假/占用）。
 */
final class BookingSlots
{
    /** 槽位标准化文本：「HH:MM-HH:MM」（入库 prefer_slot / 占用判断都用它） */
    public static function label(string $start, string $end): string
    {
        return substr($start, 0, 5) . '-' . substr($end, 0, 5);
    }

    /** 咨询师是否请假（请假=1 前台暂停接单） */
    public static function isOnLeave(int $counselorId): bool
    {
        return (int)(Database::value('SELECT on_leave FROM counselors WHERE id=?', [$counselorId]) ?? 0) === 1;
    }

    /**
     * 未来 N 天日历：[['ymd'=>'2026-11-19','md'=>'11-19','week'=>'周三','leave'=>bool,
     *   'closed_note'=>?string,'slots'=>[['start'=>'14:00','end'=>'14:50','label'=>'14:00-14:50','available'=>bool]], …]]
     * $startOffset 从明天起算（0=含今天）。
     */
    public static function days(int $counselorId, int $days = 14, int $startOffset = 1, bool $markAvailable = true): array
    {
        $leave = self::isOnLeave($counselorId);
        $tpl = Database::fetchAll(
            'SELECT weekday, start_time, end_time, slot_minutes FROM counselor_slots
             WHERE counselor_id=? AND status=1 ORDER BY weekday, start_time', [$counselorId]);
        $overrides = Database::fetchAll(
            'SELECT date, status, start_time, end_time, note FROM schedule_overrides
             WHERE counselor_id=? AND date >= ?', [$counselorId, date('Y-m-d')]);
        $ovByDate = [];
        foreach ($overrides as $o) { $ovByDate[(string)$o['date']] = $o; }
        $leadSec = max(0, (int)setting('sched.lead_hours', '2')) * 3600;
        $buffer = max(0, (int)setting('sched.buffer_minutes', '0'));
        $maxPerDay = (int)setting('sched.max_per_day', '0');

        $out = [];
        for ($i = $startOffset; $i < $startOffset + $days; $i++) {
            $ts = strtotime("+{$i} days");
            if ($ts === false) continue;
            $ymd = date('Y-m-d', $ts);
            $wd = (int)date('N', $ts);
            $ov = $ovByDate[$ymd] ?? null;
            $closedNote = null;
            $ranges = [];   // [start, end] 待切片
            $slotMin = 0;
            if ($ov !== null && (string)$ov['status'] === 'closed') {
                $closedNote = trim((string)($ov['note'] ?? '')) !== '' ? (string)$ov['note'] : '该日停诊';
            } elseif ($ov !== null && (string)$ov['status'] === 'open' && $ov['start_time'] && $ov['end_time']) {
                $ranges[] = [(string)$ov['start_time'], (string)$ov['end_time']];
                $slotMin = 0; // 限定开诊用当日模板槽长（取首个匹配 weekday 的模板；无模板默认 50）
                foreach ($tpl as $t) { if ((int)$t['weekday'] === $wd) { $slotMin = (int)$t['slot_minutes']; break; } }
            } else {
                foreach ($tpl as $t) {
                    if ((int)$t['weekday'] !== $wd) continue;
                    $ranges[] = [(string)$t['start_time'], (string)$t['end_time']];
                    if ($slotMin === 0) $slotMin = (int)$t['slot_minutes'];
                }
            }
            $dayTaken = 0;
            $slots = [];
            if ($closedNote === null && !$leave && $ranges) {
                if ($slotMin <= 0) $slotMin = 50;
                foreach ($ranges as [$rs, $re]) {
                    foreach (self::slice($rs, $re, $slotMin, $buffer) as [$s, $e]) {
                        $label = self::label($s, $e);
                        $startTs = strtotime($ymd . ' ' . $s);
                        if ($startTs === false || $startTs - time() < $leadSec) continue; // 提前截止
                        $available = true;
                        if ($markAvailable) {
                            $taken = self::isTaken($counselorId, $ymd, $label);
                            if ($taken) { $available = false; $dayTaken++; }
                        }
                        $slots[] = ['start' => $s, 'end' => $e, 'label' => $label, 'available' => $available];
                    }
                }
            }
            if ($maxPerDay > 0 && $dayTaken >= $maxPerDay) {
                foreach ($slots as &$s2) { $s2['available'] = false; }
                unset($s2);
            }
            if ($slots || $closedNote !== null || $leave) {
                $out[] = [
                    'ymd' => $ymd, 'md' => date('m-d', $ts),
                    'week' => '周' . mb_substr('一二三四五六日', $wd - 1, 1),
                    'leave' => $leave, 'closed_note' => $closedNote, 'slots' => $slots,
                ];
            }
        }
        return $out;
    }

    /** 单日槽位（AJAX / 确认页）：['leave'=>bool,'closed_note'=>?string,'slots'=>[…]] */
    public static function slotsFor(int $counselorId, string $date, bool $markAvailable = true): array
    {
        $empty = ['leave' => self::isOnLeave($counselorId), 'closed_note' => null, 'slots' => []];
        $ts = strtotime($date);
        if ($ts === false) return $empty;
        // 目标日期相对今天的天数偏移（days() 的 startOffset 从今天起算）
        $offset = (int)round(($ts - strtotime(date('Y-m-d'))) / 86400);
        if ($offset < 0 || $offset > 60) return $empty; // 过去日期/过远日期：无槽
        foreach (self::days($counselorId, 1, $offset, $markAvailable) as $d) {
            if ($d['ymd'] === $date) return ['leave' => $d['leave'], 'closed_note' => $d['closed_note'], 'slots' => $d['slots']];
        }
        return ['leave' => self::isOnLeave($counselorId), 'closed_note' => null, 'slots' => []];
    }

    /** 槽位是否已被占用（pending/confirmed 精确匹配标准化文本） */
    public static function isTaken(int $counselorId, string $date, string $label): bool
    {
        return (int)Database::value(
            "SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND prefer_date=? AND prefer_slot=? AND status IN ('pending','confirmed')",
            [$counselorId, $date, $label]
        ) > 0;
    }

    /** 确认页/提交侧校验：槽位此刻是否仍可约（可用性重查） */
    public static function checkAvailable(int $counselorId, string $date, string $start): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start)) {
            return [false, '时间参数不正确'];
        }
        $day = self::slotsFor($counselorId, $date);
        if ($day['leave']) return [false, '该咨询师当前请假暂停接单，请选择其他时间或提交预约申请'];
        if ($day['closed_note'] !== null) return [false, $day['closed_note']];
        foreach ($day['slots'] as $s) {
            if ($s['start'] === $start) {
                if (!$s['available']) return [false, '该时段刚被约满，请选择其他时段'];
                return [true, self::label($s['start'], $s['end'])];
            }
        }
        return [false, '该时段不在可约范围内，请从日历中重新选择'];
    }

    /**
     * 时段价格（分）：fee_override 分时定价命中（weekday+槽起点 HH:MM）优先，
     * 否则回落 counselors.fee_from（元→分）；无定价返回 null。
     */
    public static function slotPriceCents(int $counselorId, string $date, string $slotLabel): ?int
    {
        $c = Database::fetch('SELECT fee_from, fee_override FROM counselors WHERE id=? AND status=1', [$counselorId]);
        if (!$c) return null;
        $ov = json_decode((string)($c['fee_override'] ?? ''), true);
        $weekday = (int)date('N', strtotime($date));
        $start5 = substr((string)$slotLabel, 0, 5);
        if (is_array($ov)) {
            foreach ($ov as $r) {
                if ((int)($r['weekday'] ?? 0) === $weekday && (string)($r['slot'] ?? '') === $start5) {
                    $cents = (int)($r['price_cents'] ?? 0);
                    if ($cents > 0) return $cents;
                }
            }
        }
        $feeFrom = (float)($c['fee_from'] ?? 0);
        return $feeFrom > 0 ? (int)round($feeFrom * 100) : null;
    }

    /**
     * 时段切片：[start,end]（time 字符串）按 slotMinutes 分钟步进切出若干段；
     * buffer 分钟作为段间隔（下一段起点 = 上一段终点 + buffer）。
     */
    private static function slice(string $start, string $end, int $slotMinutes, int $buffer): array
    {
        $s0 = strtotime('2000-01-01 ' . substr($start, 0, 5));
        $e0 = strtotime('2000-01-01 ' . substr($end, 0, 5));
        if ($s0 === false || $e0 === false || $e0 <= $s0) return [];
        $dur = max(10, $slotMinutes) * 60;                 // 单段时长（秒）
        $step = (max(10, $slotMinutes) + max(0, $buffer)) * 60; // 步长（秒）
        $out = [];
        for ($t = $s0; $t + $dur <= $e0; $t += $step) {
            $out[] = [date('H:i', $t), date('H:i', $t + $dur)];
        }
        return $out;
    }
}
