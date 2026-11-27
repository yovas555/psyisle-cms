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
            'SELECT weekday, start_time, end_time, slot_minutes, service_mode, store_id, service_id FROM counselor_slots
             WHERE counselor_id=? AND status=1 ORDER BY weekday, start_time', [$counselorId]);
        $overrides = Database::fetchAll(
            'SELECT date, status, start_time, end_time, note FROM schedule_overrides
             WHERE counselor_id=? AND date >= ?', [$counselorId, date('Y-m-d')]);
        $taken = $markAvailable
            ? static function (string $ymd, string $start, string $end, string $label) use ($counselorId): bool {
                return self::isTaken($counselorId, $ymd, $start, $label, $end);
            }
            : null;

        $calendar = self::computeDays(
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
        return self::attachServiceOffers($calendar, self::servicesFor([$counselorId])[$counselorId] ?? [], $counselorId);
    }

    /** @return array<int,?string> 咨询师 ID => 最早真实可约时间（Y-m-d H:i） */
    public static function firstAvailableBatch(array $counselorIds, int $days = 14): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $counselorIds))));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $templates = Database::fetchAll(
            "SELECT counselor_id, weekday, start_time, end_time, slot_minutes, service_mode, store_id, service_id FROM counselor_slots
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
            if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', (string)$r['prefer_slot'], $m)) {
                $taken[(int)$r['counselor_id'] . '|' . (string)$r['prefer_date']][] = [$m[1], $m[2]];
            } else {
                $taken[(int)$r['counselor_id'] . '|' . (string)$r['prefer_date']][] = [(string)$r['prefer_slot'], (string)$r['prefer_slot']];
            }
        }

        $leadSec = max(0, (int)setting('sched.lead_hours', '2')) * 3600;
        $buffer = max(0, (int)setting('sched.buffer_minutes', '0'));
        $maxPerDay = (int)setting('sched.max_per_day', 0);
        $servicesByCounselor = self::servicesFor($ids);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = null;
            $isTaken = static function (string $ymd, string $start, string $end, string $label) use ($id, $taken): bool {
                foreach ($taken[$id . '|' . $ymd] ?? [] as [$takenStart, $takenEnd]) {
                    if ($start < $takenEnd && $end > $takenStart) return true;
                }
                return false;
            };
            $calendar = self::attachServiceOffers(self::computeDays(
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
            ), $servicesByCounselor[$id] ?? [], $id);
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

    public static function isTaken(int $counselorId, string $date, string $start, string $label, string $end = ''): bool
    {
        if ($end === '') $end = substr($label, 6, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            return (int)Database::value(
                "SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND prefer_date=? AND prefer_slot=? AND status IN ('pending','confirmed','rescheduled')",
                [$counselorId, $date, $label]
            ) > 0;
        }
        return (int)Database::value(
            "SELECT COUNT(*) FROM bookings
             WHERE counselor_id=? AND prefer_date=? AND status IN ('pending','confirmed','rescheduled')
               AND prefer_slot REGEXP '^[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2}$'
               AND SUBSTRING_INDEX(prefer_slot,'-',1) < ?
               AND SUBSTRING_INDEX(prefer_slot,'-',-1) > ?",
            [$counselorId, $date, $end, $start]
        ) > 0;
    }

    public static function checkAvailable(int $counselorId, string $date, string $start, string $method = '', int $serviceId = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start)) {
            return [false, '时间参数不正确'];
        }
        $day = self::slotsFor($counselorId, $date, true, $method);
        if ($day['leave']) return [false, '该咨询师当前请假暂停接单，请选择其他时间或提交预约申请'];
        if ($day['closed_note'] !== null) return [false, $day['closed_note']];
        $timeMatched = false;
        $serviceMatched = false;
        foreach ($day['slots'] as $s) {
            if ($s['start'] !== $start) continue;
            $timeMatched = true;
            if ((int)($s['service_id'] ?? 0) !== $serviceId) continue;
            $serviceMatched = true;
            if (!$s['available']) continue;
            return [true, self::label($s['start'], $s['end']), $s];
        }
        if ($serviceMatched) return [false, '该时段刚被约满，请选择其他时间'];
        return [false, $timeMatched ? '该时段不支持所选咨询项目' : '该时段不在可约范围内，请从日历中重新选择'];
    }

    /** @return array{0:bool,1:string,2:?int,3?:array<string,mixed>} */
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
            [$ok, $label, $slot] = self::checkAvailable($counselorId, $date, $start, $method, $serviceId);
            if (!$ok) return [false, $label, null];
            if ($serviceId <= 0) {
                $allowsGeneric = false;
                foreach (self::slotsFor($counselorId, $date, true, $method)['slots'] as $candidate) {
                    if ($candidate['start'] === $start && (int)($candidate['service_id'] ?? 0) === 0 && !empty($candidate['available'])) { $allowsGeneric = true; break; }
                }
                if (!$allowsGeneric) return [false, '该时段需要选择具体咨询项目', null];
            }
            $id = $insert($label, $slot);
            if ((int)$id <= 0) return [false, '预约写入失败，请稍后重试', null];
            return [true, $label, (int)$id, $slot];
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
                        (string)($t['service_mode'] ?? 'all'), (int)($t['store_id'] ?? 0), (int)($t['service_id'] ?? 0),
                    ];
                }
                if (!$matched) {
                    $ranges[] = [(string)$ov['start_time'], (string)$ov['end_time'], 50, 'all', 0, 0];
                }
            } elseif ($ov === null || !in_array((string)$ov['status'], ['closed', 'off'], true)) {
                foreach ($tpl as $t) {
                    if ((int)$t['weekday'] !== $wd) continue;
                    $ranges[] = [
                        (string)$t['start_time'], (string)$t['end_time'], (int)$t['slot_minutes'],
                        (string)($t['service_mode'] ?? 'all'), (int)($t['store_id'] ?? 0), (int)($t['service_id'] ?? 0),
                    ];
                }
            }
            $dayTaken = 0;
            $takenLabels = [];
            $slots = [];
            if ($closedNote === null && !$dayLeave && $ranges) {
                foreach ($ranges as [$rs, $re, $slotMin, $slotMode, $storeId, $rangeServiceId]) {
                    if ($slotMin <= 0) $slotMin = 50;
                    foreach (self::slice($rs, $re, $slotMin, $buffer) as [$s, $e]) {
                        $label = self::label($s, $e);
                        $startTs = strtotime($ymd . ' ' . $s);
                        if ($startTs === false || $startTs - time() < $leadSec) continue;
                        $isTaken = $taken !== null && $taken($ymd, $s, $e, $label);
                        if ($isTaken && !isset($takenLabels[$label])) {
                            $takenLabels[$label] = true;
                            $dayTaken++;
                        }
                        if ($method !== '' && !self::modeAllows($slotMode, $method)) continue;
                        $slots[] = [
                            'start' => $s, 'end' => $e, 'label' => $label, 'available' => !$isTaken,
                            'service_mode' => $slotMode, 'store_id' => $storeId, 'service_id' => $rangeServiceId,
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

    /** @return array<int,array<int,array<string,mixed>>> */
    private static function servicesFor(array $counselorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $counselorIds))));
        $out = [];
        foreach ($ids as $id) $out[$id] = [];
        if (!$ids) return $out;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT cs.counselor_id, s.id, s.name
             FROM counselor_services cs
             JOIN services s ON s.id=cs.service_id AND s.status=1
             JOIN counselors c ON c.id=cs.counselor_id AND c.status=1
             WHERE cs.counselor_id IN ($marks)
             ORDER BY cs.counselor_id, s.sort, s.id",
            $ids
        );
        foreach ($rows as $row) {
            $out[(int)$row['counselor_id']][] = [
                'id' => (int)$row['id'], 'name' => (string)$row['name'],
            ];
        }
        return $out;
    }

    private static function attachServiceOffers(array $calendar, array $services, int $counselorId): array
    {
        $serviceMap = [];
        foreach ($services as $service) $serviceMap[(int)$service['id']] = $service;
        foreach ($calendar as &$day) {
            $offers = [];
            $seen = [];
            foreach ($day['slots'] as $slot) {
                $serviceId = (int)($slot['service_id'] ?? 0);
                if ($serviceId > 0) {
                    if (!isset($serviceMap[$serviceId])) continue;
                    $serviceIds = [$serviceId];
                } elseif ($services) {
                    $serviceIds = array_keys($serviceMap);
                } else {
                    $serviceIds = [0];
                }
                foreach ($serviceIds as $offerServiceId) {
                    $key = $slot['label'] . '|' . $offerServiceId;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $price = BookingPricing::priceCents(
                        $counselorId,
                        $day['ymd'],
                        $slot['label'],
                        $offerServiceId
                    );
                    $offers[] = array_replace($slot, [
                        'service_id' => $offerServiceId,
                        'service_name' => $offerServiceId > 0 ? $serviceMap[$offerServiceId]['name'] : '项目待定',
                        'price_cents' => $price,
                        'price_text' => BookingPricing::formatCents($price),
                    ]);
                }
            }
            $day['slots'] = $offers;
        }
        unset($day);
        return $calendar;
    }

    public static function parseSlotText(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 6 || count($parts) > 7) return [[], '排班格式应为：星期|开始|结束|单次分钟|方式|门店|项目（项目可省略）'];
            $parts[] = $parts[6] ?? '0';
            $rows[] = [
                'weekday' => $parts[0], 'start_time' => $parts[1], 'end_time' => $parts[2],
                'slot_minutes' => $parts[3], 'service_mode' => $parts[4], 'store_id' => $parts[5],
                'service_id' => $parts[6],
            ];
        }
        return [$rows, ''];
    }

    public static function validateSlotRows(array $rows, int $counselorId, ?array $allowedServiceIds = null): array
    {
        if (count($rows) > 200) return [[], '排班行数不能超过 200 行'];
        if ($allowedServiceIds === null) {
            $offered = [];
            foreach (self::servicesFor([$counselorId])[$counselorId] ?? [] as $service) $offered[(int)$service['id']] = true;
        } else {
            $offered = array_fill_keys(array_map('intval', $allowedServiceIds), true);
        }
        $validStores = [];
        foreach (Database::fetchAll('SELECT id FROM stores WHERE status=1') as $store) $validStores[(int)$store['id']] = true;
        $clean = [];
        $seen = [];
        $identities = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) return [[], '第 ' . ($index + 1) . ' 行排班格式不正确'];
            if (trim((string)($row['start_time'] ?? '')) === '' && trim((string)($row['end_time'] ?? '')) === '') continue;
            $weekday = (int)($row['weekday'] ?? 0);
            $start = substr((string)($row['start_time'] ?? ''), 0, 5);
            $end = substr((string)($row['end_time'] ?? ''), 0, 5);
            $minutes = (int)($row['slot_minutes'] ?? 0);
            $mode = (string)($row['service_mode'] ?? 'all');
            $storeId = (int)($row['store_id'] ?? 0);
            $serviceId = (int)($row['service_id'] ?? 0);
            $startTs = strtotime('2000-01-01 ' . $start);
            $endTs = strtotime('2000-01-01 ' . $end);
            if ($weekday < 1 || $weekday > 7 || $startTs === false || $endTs === false || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $endTs <= $startTs) {
                return [[], '第 ' . ($index + 1) . ' 行星期或起止时间不正确'];
            }
            if ($minutes < 10 || $minutes > 240) return [[], '第 ' . ($index + 1) . ' 行单次时长须为 10–240 分钟'];
            if (!in_array($mode, ['all', 'offline', 'online'], true)) return [[], '第 ' . ($index + 1) . ' 行咨询方式不正确'];
            if ($storeId < 0 || $serviceId < 0 || ($serviceId > 0 && !isset($offered[$serviceId]))) {
                return [[], '第 ' . ($index + 1) . ' 行所选项目未关联该咨询师或已停用'];
            }
            if ($mode === 'online' && $storeId !== 0) return [[], '第 ' . ($index + 1) . ' 行线上咨询不能选择门店'];
            if ($storeId > 0 && !isset($validStores[$storeId])) return [[], '第 ' . ($index + 1) . ' 行门店不存在或已停用'];
            $key = implode('|', [$weekday, $start, $end, $minutes, $mode, $storeId, $serviceId]);
            if (isset($seen[$key])) return [[], '第 ' . ($index + 1) . ' 行与已有排班重复'];
            $seen[$key] = true;
            $offerServiceIds = $serviceId > 0 ? [$serviceId] : ($offered ? array_keys($offered) : [0]);
            $effectiveModes = $mode === 'all' ? ['offline', 'online'] : [$mode];
            foreach ($offerServiceIds as $offerServiceId) {
                foreach ($effectiveModes as $effectiveMode) {
                    $identity = implode('|', [$weekday, $start, $offerServiceId, $effectiveMode]);
                    if (isset($identities[$identity])) {
                        return [[], '第 ' . ($index + 1) . ' 行与已有排班在同一项目、开始时间和咨询方式上重叠'];
                    }
                    $identities[$identity] = true;
                }
            }
            $clean[] = [
                'counselor_id' => $counselorId, 'weekday' => $weekday,
                'start_time' => $start, 'end_time' => $end, 'slot_minutes' => $minutes,
                'service_mode' => $mode, 'store_id' => $storeId, 'service_id' => $serviceId, 'status' => 1,
            ];
        }
        return [$clean, ''];
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
