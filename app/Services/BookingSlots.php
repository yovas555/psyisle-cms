<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class BookingSlots
{
    public static function label(string $start, string $end): string
    {
        return substr($start, 0, 5) . '-' . substr($end, 0, 5);
    }

    public static function isOnLeave(int $counselorId): bool
    {
        return (int)(Database::value('SELECT on_leave FROM counselors WHERE id=?', [$counselorId]) ?? 0) === 1;
    }

    /** @return array<string,array<string,mixed>> 日期 => 请假记录 */
    private static function leaveDates(int $counselorId): array
    {
        $rows = Database::fetchAll(
            'SELECT start_date, end_date, note FROM counselor_leaves WHERE counselor_id=? AND end_date>=? ORDER BY start_date, id',
            [$counselorId, date('Y-m-d')]
        );
        $out = [];
        foreach ($rows as $row) {
            $start = strtotime((string)$row['start_date']);
            $end = strtotime((string)$row['end_date']);
            if ($start === false || $end === false || $end < $start) continue;
            for ($ts = $start; $ts <= $end; $ts += 86400) {
                $out[date('Y-m-d', $ts)] = $row;
            }
        }
        return $out;
    }

    public static function days(int $counselorId, int $days = 14, int $startOffset = 1, bool $markAvailable = true, string $method = ''): array
    {
        $tpl = Database::fetchAll(
            'SELECT weekday, start_time, end_time, slot_minutes, service_mode, store_id FROM counselor_slots
             WHERE counselor_id=? AND status=1 ORDER BY weekday, start_time', [$counselorId]);
        $overrides = Database::fetchAll(
            'SELECT date, status, start_time, end_time, note FROM schedule_overrides
             WHERE counselor_id=? AND date >= ?', [$counselorId, date('Y-m-d')]);
        $taken = $markAvailable
            ? static function (string $ymd, string $slot) use ($counselorId): bool {
                return self::isTaken($counselorId, $ymd, $slot);
            }
            : null;

        return self::computeDays(
            $days,
            $startOffset,
            self::isOnLeave($counselorId),
            $tpl,
            $overrides,
            $taken,
            max(0, (int)setting('sched.lead_hours', '2')) * 3600,
            max(0, (int)setting('sched.buffer_minutes', '0')),
            (int)setting('sched.max_per_day', 0),
            self::leaveDates($counselorId),
            $method
        );
    }

    /** @return array<int,?string> 咨询师 ID => 最早真实可约时间（Y-m-d H:i） */
    public static function firstAvailableBatch(array $counselorIds, int $days = 14): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $counselorIds))));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $templates = Database::fetchAll(
            "SELECT counselor_id, weekday, start_time, end_time, slot_minutes, service_mode, store_id FROM counselor_slots
             WHERE counselor_id IN ($marks) AND status=1 ORDER BY counselor_id, weekday, start_time", $ids);
        $overrides = Database::fetchAll(
            "SELECT counselor_id, date, status, start_time, end_time, note FROM schedule_overrides
             WHERE counselor_id IN ($marks) AND date >= ? ORDER BY counselor_id, date", array_merge($ids, [date('Y-m-d')]));
        $bookings = Database::fetchAll(
            "SELECT counselor_id, prefer_date, prefer_slot FROM bookings
             WHERE counselor_id IN ($marks) AND prefer_date >= ? AND status IN ('pending','confirmed','rescheduled')",
            array_merge($ids, [date('Y-m-d', strtotime('+1 day'))])
        );
        $leaveRows = Database::fetchAll("SELECT id, on_leave FROM counselors WHERE id IN ($marks)", $ids);
        $leaveByCounselor = [];
        foreach ($ids as $id) $leaveByCounselor[$id] = self::leaveDates($id);

        $tplById = $ovById = $leaveById = [];
        foreach ($templates as $r) $tplById[(int)$r['counselor_id']][] = $r;
        foreach ($overrides as $r) $ovById[(int)$r['counselor_id']][] = $r;
        foreach ($leaveRows as $r) $leaveById[(int)$r['id']] = (int)$r['on_leave'] === 1;
        $taken = [];
        foreach ($bookings as $r) {
            $taken[(int)$r['counselor_id'] . '|' . (string)$r['prefer_date'] . '|' . (string)$r['prefer_slot']] = true;
        }

        $leadSec = max(0, (int)setting('sched.lead_hours', '2')) * 3600;
        $buffer = max(0, (int)setting('sched.buffer_minutes', '0'));
        $maxPerDay = (int)setting('sched.max_per_day', 0);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = null;
            $isTaken = static function (string $ymd, string $slot) use ($id, $taken): bool {
                return isset($taken[$id . '|' . $ymd . '|' . $slot]);
            };
            $calendar = self::computeDays(
                $days,
                1,
                $leaveById[$id] ?? false,
                $tplById[$id] ?? [],
                $ovById[$id] ?? [],
                $isTaken,
                $leadSec,
                $buffer,
                $maxPerDay,
                $leaveByCounselor[$id] ?? []
            );
            foreach ($calendar as $day) {
                foreach ($day['slots'] as $slot) {
                    if ($slot['available']) {
                        $out[$id] = $day['ymd'] . ' ' . $slot['start'];
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    public static function slotsFor(int $counselorId, string $date, bool $markAvailable = true, string $method = ''): array
    {
        $empty = ['leave' => self::isOnLeave($counselorId), 'closed_note' => null, 'slots' => []];
        $ts = strtotime($date);
        if ($ts === false) return $empty;
        $offset = (int)round(($ts - strtotime(date('Y-m-d'))) / 86400);
        if ($offset < 0 || $offset > 60) return $empty;
        foreach (self::days($counselorId, 1, $offset, $markAvailable, $method) as $d) {
            if ($d['ymd'] === $date) return ['leave' => $d['leave'], 'closed_note' => $d['closed_note'], 'slots' => $d['slots']];
        }
        return ['leave' => self::isOnLeave($counselorId), 'closed_note' => null, 'slots' => []];
    }

    public static function isTaken(int $counselorId, string $date, string $label): bool
    {
        return (int)Database::value(
            "SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND prefer_date=? AND prefer_slot=? AND status IN ('pending','confirmed','rescheduled')",
            [$counselorId, $date, $label]
        ) > 0;
    }

    public static function checkAvailable(int $counselorId, string $date, string $start, string $method = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start)) {
            return [false, '时间参数不正确'];
        }
        $day = self::slotsFor($counselorId, $date, true, $method);
        if ($day['leave']) return [false, '该咨询师当前请假暂停接单，请选择其他时间或提交预约申请'];
        if ($day['closed_note'] !== null) return [false, $day['closed_note']];
        foreach ($day['slots'] as $s) {
            if ($s['start'] === $start) {
                if (!$s['available']) return [false, '该时段刚被约满，请选择其他时间'];
                return [true, self::label($s['start'], $s['end'])];
            }
        }
        return [false, '该时段不在可约范围内，请从日历中重新选择'];
    }

    /** @return array{0:bool,1:string,2:?int} */
    public static function reserve(int $counselorId, string $date, string $start, callable $insert, int $serviceId = 0, string $method = ''): array
    {
        return Database::transaction(static function () use ($counselorId, $date, $start, $insert, $serviceId, $method): array {
            $counselor = Database::fetch(
                'SELECT id, service_modes FROM counselors WHERE id=? AND status=1 FOR UPDATE',
                [$counselorId]
            );
            if (!$counselor) return [false, '咨询师不存在或已停用，请重新选择时段', null];
            if ($method !== '' && !CounselorProfile::allows($counselor, $method)) {
                return [false, '该咨询师不提供' . $method . '咨询，请选择其支持的服务方式', null];
            }
            if ($serviceId > 0 && !BookingPricing::offered($counselorId, $serviceId)) {
                return [false, '该咨询师不提供所选服务，请重新选择', null];
            }
            [$ok, $label] = self::checkAvailable($counselorId, $date, $start, $method);
            if (!$ok) return [false, $label, null];
            $id = $insert($label);
            if ((int)$id <= 0) return [false, '预约写入失败，请稍后重试', null];
            return [true, $label, (int)$id];
        });
    }

    public static function slotPriceCents(int $counselorId, string $date, string $slotLabel, int $serviceId = 0): ?int
    {
        return BookingPricing::priceCents($counselorId, $date, $slotLabel, $serviceId);
    }

    public static function modeAllows(string $slotMode, string $method): bool
    {
        if ($slotMode === '' || $slotMode === 'all') return true;
        if ($slotMode === 'offline') return $method === '面对面';
        if ($slotMode === 'online') return in_array($method, ['视频', '电话'], true);
        return false;
    }

    private static function computeDays(
        int $days,
        int $startOffset,
        bool $leave,
        array $tpl,
        array $overrides,
        ?callable $taken,
        int $leadSec,
        int $buffer,
        int $maxPerDay,
        array $leaveDates = [],
        string $method = ''
    ): array {
        $ovByDate = [];
        foreach ($overrides as $o) $ovByDate[(string)$o['date']] = $o;
        $out = [];
        for ($i = $startOffset; $i < $startOffset + $days; $i++) {
            $ts = strtotime("+{$i} days");
            if ($ts === false) continue;
            $ymd = date('Y-m-d', $ts);
            $wd = (int)date('N', $ts);
            $ov = $ovByDate[$ymd] ?? null;
            $dateLeave = $leaveDates[$ymd] ?? null;
            $dayLeave = $leave || $dateLeave !== null;
            $closedNote = null;
            if ($dateLeave !== null) {
                $closedNote = trim((string)($dateLeave['note'] ?? '')) !== '' ? (string)$dateLeave['note'] : '该日请假暂停接单';
            } elseif ($ov !== null && in_array((string)$ov['status'], ['closed', 'off'], true)) {
                $closedNote = trim((string)($ov['note'] ?? '')) !== '' ? (string)$ov['note'] : '该日停诊';
            }
            $ranges = [];
            if ($ov !== null && in_array((string)$ov['status'], ['open', 'on'], true) && $ov['start_time'] && $ov['end_time']) {
                $matched = false;
                foreach ($tpl as $t) {
                    if ((int)$t['weekday'] !== $wd) continue;
                    $matched = true;
                    $ranges[] = [
                        (string)$ov['start_time'], (string)$ov['end_time'], (int)$t['slot_minutes'],
                        (string)($t['service_mode'] ?? 'all'), (int)($t['store_id'] ?? 0),
                    ];
                }
                if (!$matched) {
                    $ranges[] = [(string)$ov['start_time'], (string)$ov['end_time'], 50, 'all', 0];
                }
            } elseif ($ov === null || !in_array((string)$ov['status'], ['closed', 'off'], true)) {
                foreach ($tpl as $t) {
                    if ((int)$t['weekday'] !== $wd) continue;
                    $ranges[] = [
                        (string)$t['start_time'], (string)$t['end_time'], (int)$t['slot_minutes'],
                        (string)($t['service_mode'] ?? 'all'), (int)($t['store_id'] ?? 0),
                    ];
                }
            }
            $dayTaken = 0;
            $takenLabels = [];
            $slots = [];
            if ($closedNote === null && !$dayLeave && $ranges) {
                foreach ($ranges as [$rs, $re, $slotMin, $slotMode, $storeId]) {
                    if ($slotMin <= 0) $slotMin = 50;
                    foreach (self::slice($rs, $re, $slotMin, $buffer) as [$s, $e]) {
                        $label = self::label($s, $e);
                        $startTs = strtotime($ymd . ' ' . $s);
                        if ($startTs === false || $startTs - time() < $leadSec) continue;
                        $isTaken = $taken !== null && $taken($ymd, $label);
                        if ($isTaken && !isset($takenLabels[$label])) {
                            $takenLabels[$label] = true;
                            $dayTaken++;
                        }
                        if ($method !== '' && !self::modeAllows($slotMode, $method)) continue;
                        $slots[] = [
                            'start' => $s, 'end' => $e, 'label' => $label, 'available' => !$isTaken,
                            'service_mode' => $slotMode, 'store_id' => $storeId,
                        ];
                    }
                }
            }
            if ($maxPerDay > 0 && $dayTaken >= $maxPerDay) {
                foreach ($slots as &$s2) { $s2['available'] = false; }
                unset($s2);
            }
            if ($slots || $closedNote !== null || $dayLeave) {
                $out[] = [
                    'ymd' => $ymd, 'md' => date('m-d', $ts),
                    'week' => '周' . mb_substr('一二三四五六日', $wd - 1, 1),
                    'leave' => $dayLeave, 'closed_note' => $closedNote, 'slots' => $slots,
                ];
            }
        }
        return $out;
    }

    private static function slice(string $start, string $end, int $slotMinutes, int $buffer): array
    {
        $s0 = strtotime('2000-01-01 ' . substr($start, 0, 5));
        $e0 = strtotime('2000-01-01 ' . substr($end, 0, 5));
        if ($s0 === false || $e0 === false || $e0 <= $s0) return [];
        $dur = max(10, $slotMinutes) * 60;
        $step = (max(10, $slotMinutes) + max(0, $buffer)) * 60;
        $out = [];
        for ($t = $s0; $t + $dur <= $e0; $t += $step) {
            $out[] = [date('H:i', $t), date('H:i', $t + $dur)];
        }
        return $out;
    }
}
