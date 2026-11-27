<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class CounselorWorkspace
{
    public static function overview(int $counselorId): array
    {
        $today = (int)Database::value(
            "SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND prefer_date=CURDATE() AND status IN ('confirmed','rescheduled')",
            [$counselorId]
        );
        $pending = (int)Database::value('SELECT COUNT(*) FROM bookings WHERE counselor_id=? AND status=?', [$counselorId, 'pending']);
        $unread = CounselorNotifyService::unreadCount($counselorId);
        $caseStats = CaseService::stats($counselorId);
        $care = CaseCareService::overview($counselorId);
        $upcoming = Database::fetchAll(
            "SELECT b.*, s.name svc FROM bookings b LEFT JOIN services s ON s.id=b.service_id
             WHERE b.counselor_id=? AND b.status IN ('confirmed','rescheduled') AND b.prefer_date >= CURDATE()
             ORDER BY b.prefer_date, b.prefer_slot, b.id LIMIT 20", [$counselorId]
        );
        $clients = Database::fetchAll(
            "SELECT m.id, m.phone, m.name,
                    COALESCE((SELECT SUM(o.remain) FROM package_orders o WHERE o.member_id=m.id AND o.status='active' AND (o.expire_at IS NULL OR o.expire_at>=CURDATE())), 0) AS remain
             FROM members m
             WHERE m.phone IN (SELECT phone FROM bookings WHERE counselor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
             ORDER BY remain DESC LIMIT 30", [$counselorId]
        );
        $month = date('Y-m');
        return [
            'today' => $today,
            'pending' => $pending,
            'unread' => $unread,
            'todoCount' => count(self::todos($counselorId)),
            'notesCount' => (int)Database::value('SELECT COUNT(*) FROM counselor_notes WHERE counselor_id=?', [$counselorId]),
            'caseStats' => $caseStats,
            'care' => $care,
            'upcoming' => $upcoming,
            'clients' => $clients,
            'settlement' => self::settlement($counselorId, $month),
            'feedback' => self::feedbackSummary($counselorId, $month),
        ];
    }

    public static function todos(int $counselorId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $params = array_fill(0, 6, $counselorId);
        $rows = Database::fetchAll(
            "SELECT 'crisis' item_type, e.id item_id, e.case_id, NULL booking_id,
                    CONCAT(e.occurred_at, ' 00:00:00') due_at, 0 priority, e.level,
                    c.case_no, e.summary title,
                    CONCAT('/admin/counselor-home/cases/', e.case_id, '/care') url
             FROM crisis_events e JOIN cases c ON c.id=e.case_id
             WHERE e.counselor_id=? AND e.status='open'
             UNION ALL
             SELECT 'followup', f.id, f.case_id, f.booking_id,
                    CONCAT(f.due_at, ' 00:00:00'), CASE WHEN f.due_at < CURDATE() THEN 1 ELSE 2 END, f.due_at,
                    c.case_no, COALESCE(NULLIF(f.purpose, ''), '咨询后随访') title,
                    CONCAT('/admin/counselor-home/cases/', f.case_id, '/care')
             FROM case_followups f JOIN cases c ON c.id=f.case_id
             WHERE f.counselor_id=? AND f.status='pending' AND f.due_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             UNION ALL
             SELECT 'homework', h.id, h.case_id, s.booking_id,
                    CONCAT(h.due_at, ' 23:59:59'), CASE WHEN h.due_at < CURDATE() THEN 1 ELSE 2 END, h.due_at,
                    c.case_no, h.title, CONCAT('/admin/counselor-home/cases/', h.case_id, '/care')
             FROM case_homeworks h JOIN cases c ON c.id=h.case_id
             LEFT JOIN case_sessions s ON s.id=h.session_id
             WHERE h.counselor_id=? AND h.status='assigned' AND h.due_at IS NOT NULL AND h.due_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             UNION ALL
             SELECT 'feedback', f.id, f.case_id, f.booking_id,
                    f.updated_at, 1, f.wants_followup, COALESCE(c.case_no, ''),
                    '疗程反馈等待关联个案', '/admin/counselor-home/feedbacks'
             FROM booking_feedbacks f LEFT JOIN cases c ON c.id=f.case_id
             WHERE f.counselor_id=? AND f.status='submitted' AND f.bridge_status='waiting_case'
             UNION ALL
             SELECT 'booking', b.id, NULL, b.id,
                    CONCAT(b.prefer_date, ' ', SUBSTRING(b.prefer_slot, 1, 5)), 3, b.status,
                    '', CASE WHEN b.status='pending' THEN '待确认预约' ELSE CONCAT('今日咨询 ', SUBSTRING(b.prefer_slot, 1, 5)) END,
                    '/admin/counselor-home/bookings'
             FROM bookings b
             WHERE b.counselor_id=? AND ((b.status='pending') OR (b.prefer_date=CURDATE() AND b.status IN ('confirmed','rescheduled')))
             UNION ALL
             SELECT 'notification', n.id, NULL, n.booking_id,
                    n.created_at, 4, n.kind, '', n.title, '/admin/counselor-home/todos'
             FROM counselor_notifications n
             WHERE n.counselor_id=? AND n.is_read=0
             ORDER BY priority, due_at, item_id DESC LIMIT $limit", $params
        );
        return $rows;
    }

    public static function calendar(int $counselorId, string $date): array
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) $date = date('Y-m-d');
        $slots = BookingSlots::slotsFor($counselorId, $date, true);
        $bookings = Database::fetchAll(
            "SELECT b.*, s.name svc FROM bookings b LEFT JOIN services s ON s.id=b.service_id
             WHERE b.counselor_id=? AND b.prefer_date=? AND b.status IN ('pending','confirmed','rescheduled')
             ORDER BY b.prefer_slot, b.id", [$counselorId, $date]
        );
        $bySlot = [];
        foreach ($bookings as $booking) {
            $bySlot[substr((string)$booking['prefer_slot'], 0, 5)][] = $booking;
        }
        return ['date' => $date, 'slots' => $slots, 'bookings' => $bookings, 'bySlot' => $bySlot];
    }

    public static function settlement(int $counselorId, string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
        $start = $month . '-01';
        $end = date('Y-m-d', strtotime($start . ' +1 month'));
        $booking = Database::fetch(
            "SELECT COUNT(*) sessions,
                    COALESCE(SUM(CASE WHEN pay_status='paid' THEN amount_cents ELSE 0 END),0) received_cents,
                    SUM(CASE WHEN pay_status='free' THEN 1 ELSE 0 END) offline_sessions
             FROM bookings
             WHERE counselor_id=? AND status='completed' AND prefer_date>=? AND prefer_date<?",
            [$counselorId, $start, $end]
        );
        $package = Database::fetch(
            "SELECT COALESCE(-SUM(l.sessions),0) redeemed_sessions
             FROM package_ledger l JOIN bookings b ON b.id=l.booking_id
             WHERE b.counselor_id=? AND b.status='completed' AND b.prefer_date>=? AND b.prefer_date<?
                   AND l.kind='use' AND l.sessions<0",
            [$counselorId, $start, $end]
        );
        $sessions = Database::fetchAll(
            "SELECT s.*, c.case_no FROM case_sessions s LEFT JOIN cases c ON c.id=s.case_id
             WHERE s.counselor_id=? AND s.held_at>=? AND s.held_at<? ORDER BY s.held_at DESC, s.id DESC LIMIT 200",
            [$counselorId, $start, $end]
        );
        return [
            'month' => $month,
            'sessions' => (int)($booking['sessions'] ?? 0),
            'received_cents' => (int)($booking['received_cents'] ?? 0),
            'offline_sessions' => (int)($booking['offline_sessions'] ?? 0),
            'redeemed_sessions' => (int)($package['redeemed_sessions'] ?? 0),
            'session_rows' => $sessions,
        ];
    }

    public static function feedbackSummary(int $counselorId, string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
        $row = Database::fetch(
            "SELECT COUNT(*) submitted, AVG(rating) average_rating,
                    SUM(wants_followup=1) followup_requests,
                    SUM(bridge_status='created') bridged,
                    SUM(bridge_status='waiting_case') waiting_case
             FROM booking_feedbacks
             WHERE counselor_id=? AND status='submitted' AND submitted_at>=? AND submitted_at<DATE_ADD(?, INTERVAL 1 MONTH)",
            [$counselorId, $month . '-01', $month . '-01']
        );
        return [
            'month' => $month,
            'submitted' => (int)($row['submitted'] ?? 0),
            'average_rating' => $row && $row['average_rating'] !== null ? round((float)$row['average_rating'], 1) : null,
            'followup_requests' => (int)($row['followup_requests'] ?? 0),
            'bridged' => (int)($row['bridged'] ?? 0),
            'waiting_case' => (int)($row['waiting_case'] ?? 0),
        ];
    }

    public static function notifications(int $counselorId, int $limit = 60): array
    {
        return Database::fetchAll(
            'SELECT * FROM counselor_notifications WHERE counselor_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$counselorId]
        );
    }

    public static function readNotification(int $counselorId, int $id): bool
    {
        return Database::run('UPDATE counselor_notifications SET is_read=1 WHERE id=? AND counselor_id=?', [$id, $counselorId])->rowCount() === 1;
    }

    public static function readAllNotifications(int $counselorId): int
    {
        return Database::run('UPDATE counselor_notifications SET is_read=1 WHERE counselor_id=? AND is_read=0', [$counselorId])->rowCount();
    }
}
