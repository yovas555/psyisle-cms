<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class BookingLifecycle
{
    public const STATUSES = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];

    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['rescheduled', 'completed', 'cancelled'],
        'rescheduled' => ['rescheduled', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function startTs(array $booking): ?int
    {
        $date = trim((string)($booking['prefer_date'] ?? ''));
        if ($date === '') return null;
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;
        $slot = trim((string)($booking['prefer_slot'] ?? ''));
        $time = preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $slot, $m) ? $m[1] . ':' . $m[2] : '00:00';
        $ts = strtotime($date . ' ' . $time);
        return $ts === false ? null : $ts;
    }

    public static function cancellationHours(array $booking): int
    {
        $snapshot = json_decode((string)($booking['rule_snapshot'] ?? ''), true);
        $hours = (int)($snapshot['rules']['cancel_hours'] ?? setting('booking.cancel_hours', '24'));
        return max(0, min(720, $hours));
    }

    public static function cancellationBlockReason(array $booking, ?int $now = null): ?string
    {
        if (!in_array((string)($booking['status'] ?? ''), ['pending', 'confirmed', 'rescheduled'], true)) {
            return '当前状态不可取消';
        }
        $start = self::startTs($booking);
        $now = $now ?? time();
        $hours = self::cancellationHours($booking);
        if ($start !== null && $hours > 0 && $start - $now < $hours * 3600) {
            return "距咨询开始不足 {$hours} 小时，请直接联系机构取消";
        }
        return null;
    }

    public static function snapshot(array $booking): array
    {
        return [
            'version' => 1,
            'captured_at' => date('Y-m-d H:i:s'),
            'rules' => [
                'cancel_hours' => max(0, min(720, (int)setting('booking.cancel_hours', '24'))),
                'lead_hours' => max(0, (int)setting('sched.lead_hours', '2')),
                'buffer_minutes' => max(0, (int)setting('sched.buffer_minutes', '0')),
                'max_per_day' => max(0, (int)setting('sched.max_per_day', '0')),
                'timezone' => date_default_timezone_get(),
            ],
            'slot' => [
                'counselor_id' => (int)($booking['counselor_id'] ?? 0) ?: null,
                'service_id' => (int)($booking['service_id'] ?? 0) ?: null,
                'date' => (string)($booking['prefer_date'] ?? '') ?: null,
                'label' => (string)($booking['prefer_slot'] ?? '') ?: null,
                'method' => (string)($booking['method'] ?? '') ?: null,
            ],
            'pricing' => [
                'amount_cents' => isset($booking['amount_cents']) ? (int)$booking['amount_cents'] : null,
                'pay_status' => (string)($booking['pay_status'] ?? 'unpaid'),
            ],
            'channel' => (string)($booking['source'] ?? 'web') ?: 'web',
        ];
    }

    public static function ensureSnapshot(int $bookingId): void
    {
        Database::run('UPDATE bookings SET rule_snapshot=? WHERE id=? AND rule_snapshot IS NULL', [
            json_encode(self::snapshot(Database::fetch('SELECT * FROM bookings WHERE id=?', [$bookingId]) ?: []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $bookingId,
        ]);
    }

    /** @return array{0:bool,1:string,2:?array,3:bool} */
    public static function transition(int $bookingId, string $to, int $actorId = 0, string $actorName = '系统', string $note = '', bool $enforceCancelHours = false, ?callable $afterUpdate = null): array
    {
        if (!in_array($to, self::STATUSES, true)) return [false, '状态不合法', null, false];
        $note = mb_substr(trim($note), 0, 500);
        $result = Database::transaction(function () use ($bookingId, $to, $actorId, $actorName, $note, $enforceCancelHours, $afterUpdate): array {
            $booking = Database::fetch('SELECT * FROM bookings WHERE id=? FOR UPDATE', [$bookingId]);
            if (!$booking) return [false, '预约不存在', null, false];
            $from = (string)$booking['status'];
            if ($from === $to) return [true, '状态未变化', $booking, false];
            if (!self::canTransition($from, $to)) return [false, "预约不能从 {$from} 变更为 {$to}", $booking, false];
            if ($to === 'cancelled' && $enforceCancelHours) {
                $reason = self::cancellationBlockReason($booking);
                if ($reason !== null) return [false, $reason, $booking, false];
            }
            if ($booking['rule_snapshot'] === null) {
                Database::update('bookings', ['rule_snapshot' => json_encode(self::snapshot($booking), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id=?', [$bookingId]);
            }
            $changed = Database::run(
                'UPDATE bookings SET status=?, admin_note=?, updated_at=? WHERE id=? AND status=?',
                [$to, $note !== '' ? $note : $booking['admin_note'], date('Y-m-d H:i:s'), $bookingId, $from]
            );
            if ($changed->rowCount() !== 1) return [false, '预约状态已变化，请刷新后重试', $booking, false];
            if ($afterUpdate !== null) $afterUpdate($booking);
            Database::insert('booking_status_events', [
                'booking_id' => $bookingId,
                'from_status' => $from,
                'to_status' => $to,
                'event_type' => 'status_change',
                'actor_id' => $actorId > 0 ? $actorId : null,
                'actor_name' => mb_substr($actorName !== '' ? $actorName : '系统', 0, 80),
                'note' => $note !== '' ? $note : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($to === 'completed') PostSessionService::onCompleted($bookingId);
            $booking['status'] = $to;
            if ($note !== '') $booking['admin_note'] = $note;
            return [true, '预约状态已更新', $booking, true];
        });
        return $result;
    }

    /** @return array{0:bool,1:string,2:?array,3:bool} */
    public static function confirmPaid(int $bookingId, ?callable $beforeUpdate = null): array
    {
        return Database::transaction(function () use ($bookingId, $beforeUpdate): array {
            $booking = Database::fetch('SELECT * FROM bookings WHERE id=? FOR UPDATE', [$bookingId]);
            if (!$booking) return [false, '预约不存在', null, false];
            if ((string)$booking['pay_status'] === 'paid') return [true, '已支付', $booking, false];
            if ((string)$booking['source'] !== 'slot' || (string)$booking['status'] !== 'pending' || (string)$booking['pay_status'] !== 'unpaid') {
                return [false, '预约当前状态不能自动确认', $booking, false];
            }
            if ($booking['rule_snapshot'] === null) {
                Database::update('bookings', ['rule_snapshot' => json_encode(self::snapshot($booking), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id=?', [$bookingId]);
            }
            if ($beforeUpdate !== null && !$beforeUpdate($booking)) {
                return [false, '支付订单状态已变化，请刷新后重试', $booking, false];
            }
            $changed = Database::run(
                "UPDATE bookings SET pay_status='paid', status='confirmed', updated_at=? WHERE id=? AND source='slot' AND status='pending' AND pay_status='unpaid'",
                [date('Y-m-d H:i:s'), $bookingId]
            );
            if ($changed->rowCount() !== 1) return [false, '预约状态已变化，请刷新后重试', $booking, false];
            Database::insert('booking_status_events', [
                'booking_id' => $bookingId, 'from_status' => 'pending', 'to_status' => 'confirmed',
                'event_type' => 'payment_confirmation', 'actor_id' => null, 'actor_name' => '支付系统',
                'note' => '在线支付到账后自动确认', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $booking['pay_status'] = 'paid';
            $booking['status'] = 'confirmed';
            return [true, '支付已入账并确认预约', $booking, true];
        });
    }
}
