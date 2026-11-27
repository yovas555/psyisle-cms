<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;
use app\Core\QuietHours;

/** 支付成功通知 Outbox：按预约+通道幂等，失败退避重试，绑定缺失单独留痕。 */
final class BookingPaidNotificationService
{
    private const CHANNELS = ['admin_mail', 'counselor_inapp', 'counselor_sms', 'counselor_wechat'];
    private const MAX_ATTEMPTS = 5;

    public static function enqueueForBooking(int $bookingId): void
    {
        if ($bookingId <= 0) return;
        $booking = Database::fetch('SELECT id, counselor_id, pay_status FROM bookings WHERE id=?', [$bookingId]);
        if (!$booking || (string)$booking['pay_status'] !== 'paid') return;
        $channels = ['admin_mail'];
        if ((int)$booking['counselor_id'] > 0) {
            $channels = array_merge($channels, ['counselor_inapp', 'counselor_sms', 'counselor_wechat']);
        }
        $now = date('Y-m-d H:i:s');
        foreach ($channels as $channel) {
            Database::run(
                'INSERT IGNORE INTO booking_paid_notifications (booking_id, channel, status, attempts, created_at, updated_at) VALUES (?,?,?,?,?,?)',
                [$bookingId, $channel, 'pending', 0, $now, $now]
            );
        }
    }

    public static function flush(int $limit = 20): array
    {
        Database::run("UPDATE booking_paid_notifications SET status='pending', updated_at=NOW() WHERE status='sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $limit = max(1, min(100, $limit));
        $rows = Database::fetchAll(
            "SELECT * FROM booking_paid_notifications
             WHERE status='pending' AND attempts < ? AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id ASC LIMIT {$limit}",
            [self::MAX_ATTEMPTS]
        );
        $out = ['found' => count($rows), 'sent' => 0, 'deferred' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $claim = Database::run("UPDATE booking_paid_notifications SET status='sending', updated_at=NOW() WHERE id=? AND status='pending'", [(int)$row['id']]);
            if ($claim->rowCount() !== 1) continue;
            try {
                [$status, $error, $retryAt] = self::dispatch((int)$row['booking_id'], (string)$row['channel']);
            } catch (\Throwable $t) {
                $status = 'failed';
                $error = $t->getMessage();
                $retryAt = null;
                Logger::error('booking-paid-notify', $t->getMessage(), ['booking' => (int)$row['booking_id'], 'channel' => $row['channel']]);
            }
            if ($status === 'sent') {
                Database::run("UPDATE booking_paid_notifications SET status='sent', last_error=NULL, next_attempt_at=NULL, sent_at=NOW(), updated_at=NOW() WHERE id=?", [(int)$row['id']]);
                $out['sent']++;
            } elseif ($status === 'deferred') {
                Database::run("UPDATE booking_paid_notifications SET status='pending', last_error=NULL, next_attempt_at=?, updated_at=NOW() WHERE id=?", [$retryAt, (int)$row['id']]);
                $out['deferred']++;
            } elseif ($status === 'blocked' || $status === 'skipped') {
                Database::run("UPDATE booking_paid_notifications SET status=?, last_error=?, next_attempt_at=NULL, updated_at=NOW() WHERE id=?", [$status, str_take($error, 490, ''), (int)$row['id']]);
                $out[$status]++;
            } else {
                $attempts = (int)$row['attempts'] + 1;
                $terminal = $attempts >= self::MAX_ATTEMPTS;
                $next = $terminal ? null : date('Y-m-d H:i:s', time() + min(3600, 60 * (5 ** ($attempts - 1))));
                Database::run(
                    "UPDATE booking_paid_notifications SET status=?, attempts=?, last_error=?, next_attempt_at=?, updated_at=NOW() WHERE id=?",
                    [$terminal ? 'failed' : 'pending', $attempts, str_take($error, 490, ''), $next, (int)$row['id']]
                );
                $out['failed']++;
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string,2:?string} */
    private static function dispatch(int $bookingId, string $channel): array
    {
        $b = Database::fetch(
            'SELECT b.*, c.name AS counselor_name, c.phone AS counselor_phone, s.name AS service_name
             FROM bookings b
             LEFT JOIN counselors c ON c.id=b.counselor_id
             LEFT JOIN services s ON s.id=b.service_id
             WHERE b.id=?',
            [$bookingId]
        );
        if (!$b) return ['blocked', '预约不存在', null];
        if ((string)$b['pay_status'] !== 'paid') return ['blocked', '预约尚未支付', null];
        $amount = number_format((int)$b['amount_cents'] / 100, 2);
        $when = trim((string)$b['prefer_date'] . ' ' . (string)$b['prefer_slot']);
        $method = (string)$b['method'];
        $counselorId = (int)$b['counselor_id'];
        $data = [
            'title' => '咨询费已到账（预约生效）：' . (string)$b['name'],
            'body' => $when . ' · ' . $method . ' · ' . $amount . ' 元',
            'url' => '/admin/counselor-home/bookings',
            'booking_id' => $bookingId,
            'paid' => true,
            'counselor_name' => (string)($b['counselor_name'] ?? ''),
            'service_name' => (string)($b['service_name'] ?? '暂不确定'),
            'start_time' => $when,
            'place' => $method,
            'client' => (string)$b['name'],
            'booking' => ['id' => $bookingId, 'code' => (string)$b['code'], 'phone' => (string)$b['phone']],
        ];
        if ($channel === 'admin_mail') {
            if (!class_exists(\plugins\mailnotify\Plugin::class) || !(bool)setting('mail.enabled', '0')) return ['skipped', '管理员邮件通知未启用', null];
            if (!\plugins\mailnotify\Plugin::recipients()) return ['blocked', '未配置管理员收件邮箱', null];
            $body = "查询码：{$b['code']}\n访客：{$b['name']}（" . \app\Services\MemberService::maskPhone((string)$b['phone']) . "）"
                . "\n咨询师：" . ($b['counselor_name'] ?: '未指定') . "\n时间：{$when}\n方式：{$method}\n实付：{$amount} 元\n状态：已支付，预约已生效";
            $ctx = \plugins\mailnotify\Plugin::onPush([
                'type' => 'booking',
                'title' => '咨询预约付款成功：' . (string)$b['code'],
                'body' => $body,
                'ref' => ['type' => 'booking', 'id' => $bookingId, 'url' => url('/admin/bookings/' . $bookingId . '/edit')],
                'urgent' => true,
            ]);
            $accepted = (int)($ctx['mail_notify_accepted'] ?? 0);
            return $accepted > 0 ? ['sent', '', null] : ['failed', '管理员邮件未进入发送队列', null];
        }
        if ($counselorId <= 0) return ['blocked', '预约未指定咨询师', null];
        if ($channel === 'counselor_sms') {
            [$inQuiet, $deliverAt] = QuietHours::resolve('booking');
            if ($inQuiet) return ['deferred', '', date('Y-m-d H:i:s', $deliverAt)];
        }
        [$status, $error] = CounselorNotifyService::sendChannel($counselorId, substr($channel, strlen('counselor_')), 'booking_confirm', $data);
        return [$status, $error, null];
    }

    public static function stats(): array
    {
        $rows = Database::fetchAll('SELECT status, COUNT(*) AS total FROM booking_paid_notifications GROUP BY status');
        $out = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $row) $out[(string)$row['status']] = (int)$row['total'];
        return $out;
    }
}
