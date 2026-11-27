<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class PostSessionService
{
    public static function onCompleted(int $bookingId): array
    {
        return self::transaction(function () use ($bookingId): array {
            $booking = Database::fetch('SELECT * FROM bookings WHERE id=? FOR UPDATE', [$bookingId]);
            if (!$booking) throw new \InvalidArgumentException('预约不存在');
            if ((string)$booking['status'] !== 'completed') throw new \InvalidArgumentException('仅已完成预约可建立咨询后反馈');

            $existing = Database::fetch('SELECT * FROM booking_feedbacks WHERE booking_id=?', [$bookingId]);
            if ($existing) return $existing;

            $member = Database::fetch("SELECT * FROM members WHERE phone=? AND status='active' LIMIT 1", [(string)$booking['phone']]);
            $counselor = Database::fetch('SELECT * FROM counselors WHERE id=? AND status=1', [(int)$booking['counselor_id']]);
            $memberId = $member ? (int)$member['id'] : null;
            $counselorId = (int)$booking['counselor_id'];
            $case = null;
            $skipReason = null;
            if (!$member || !$counselor) {
                $status = 'unavailable';
                $bridgeStatus = 'unavailable';
                $skipReason = !$member && !$counselor ? 'member_or_counselor_unavailable' : (!$member ? 'member_unavailable' : 'counselor_unavailable');
            } else {
                $status = 'pending';
                $bridgeStatus = 'waiting_case';
                $case = self::activeCase($memberId, $counselorId);
            }

            $now = date('Y-m-d H:i:s');
            $id = Database::insert('booking_feedbacks', [
                'booking_id' => $bookingId,
                'member_id' => $memberId,
                'counselor_id' => $counselorId,
                'case_id' => $case ? (int)$case['id'] : null,
                'status' => $status,
                'rating' => null,
                'comment' => null,
                'wants_followup' => 0,
                'bridge_status' => $bridgeStatus,
                'skip_reason' => $skipReason,
                'submitted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($id <= 0) throw new \RuntimeException('咨询后反馈创建失败');
            $feedback = Database::fetch('SELECT * FROM booking_feedbacks WHERE id=?', [$id]);
            if (!$feedback) throw new \RuntimeException('咨询后反馈读取失败');

            if ($status === 'pending') {
                self::notifyMember($memberId, '咨询后反馈待提交', '本次咨询已完成，欢迎提交咨询后反馈。', '/member/bookings', 'booking_feedback', (string)$id);
                self::notifyCounselor($counselorId, '咨询已完成，待会员反馈', '会员提交反馈后，如需继续跟进可安排随访。', '/admin/counselor-home/bookings', $bookingId);
            }
            return $feedback;
        });
    }

    public static function submit(int $bookingId, int $memberId, array $input): array
    {
        return self::transaction(function () use ($bookingId, $memberId, $input): array {
            $booking = Database::fetch('SELECT * FROM bookings WHERE id=? FOR UPDATE', [$bookingId]);
            if (!$booking) throw new \InvalidArgumentException('预约不存在');
            if ((string)$booking['status'] !== 'completed') throw new \InvalidArgumentException('预约尚未完成，不能提交反馈');
            $member = Database::fetch("SELECT * FROM members WHERE id=? AND phone=? AND status='active' FOR UPDATE", [$memberId, (string)$booking['phone']]);
            if (!$member) throw new \InvalidArgumentException('会员无效或无权提交该预约反馈');
            $feedback = Database::fetch('SELECT * FROM booking_feedbacks WHERE booking_id=? FOR UPDATE', [$bookingId]);
            if (!$feedback) throw new \InvalidArgumentException('该预约尚无反馈记录');
            if ((int)$feedback['member_id'] !== $memberId || (int)$feedback['counselor_id'] !== (int)$booking['counselor_id']) {
                throw new \InvalidArgumentException('反馈归属与预约不一致');
            }
            if ((string)$feedback['status'] === 'submitted') return $feedback;
            if ((string)$feedback['status'] !== 'pending') throw new \InvalidArgumentException('当前反馈状态不可提交');

            $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
            if ($rating === false) throw new \InvalidArgumentException('评分必须为 1-5 的整数');
            $commentRaw = $input['comment'] ?? '';
            if (!is_string($commentRaw)) throw new \InvalidArgumentException('反馈内容格式不正确');
            $comment = trim($commentRaw);
            if (mb_strlen($comment, 'UTF-8') > 2000) throw new \InvalidArgumentException('反馈内容不能超过 2000 字');
            $wantsRaw = $input['wants_followup'] ?? 0;
            if (!in_array($wantsRaw, [0, 1, '0', '1'], true)) throw new \InvalidArgumentException('是否需要随访只能为 0 或 1');
            $wantsFollowup = (int)$wantsRaw;
            $caseId = null;
            $bridgeStatus = $wantsFollowup ? 'waiting_case' : 'not_applicable';
            $now = date('Y-m-d H:i:s');

            $changed = Database::run(
                "UPDATE booking_feedbacks SET status='submitted', rating=?, comment=?, wants_followup=?, bridge_status=?, submitted_at=?, updated_at=? WHERE id=? AND status='pending'",
                [$rating, $comment, $wantsFollowup, $bridgeStatus, $now, $now, (int)$feedback['id']]
            );
            if ($changed->rowCount() !== 1) throw new \RuntimeException('反馈状态已变化，请刷新后重试');

            if ($wantsFollowup === 1) {
                $case = self::activeCase($memberId, (int)$booking['counselor_id']);
                if ($case) {
                    $caseId = (int)$case['id'];
                    $followup = self::createBridgeFollowup($bookingId, (int)$booking['counselor_id'], $caseId);
                    if (empty($followup['ok'])) throw new \RuntimeException((string)($followup['msg'] ?? '随访创建失败'));
                    $bridgeStatus = 'created';
                    $changed = Database::run(
                        'UPDATE booking_feedbacks SET case_id=?, bridge_status=?, updated_at=? WHERE id=? AND status=?',
                        [$caseId, $bridgeStatus, $now, (int)$feedback['id'], 'submitted']
                    );
                    if ($changed->rowCount() !== 1) throw new \RuntimeException('反馈桥接状态更新失败');
                }
            }

            $feedback = Database::fetch('SELECT * FROM booking_feedbacks WHERE id=?', [(int)$feedback['id']]);
            if (!$feedback) throw new \RuntimeException('咨询后反馈读取失败');
            self::notifyMember($memberId, '咨询后反馈已提交', '感谢您的反馈，机构已收到本次咨询评价。', '/member/bookings', 'booking_feedback', (string)$feedback['id']);
            self::notifyCounselor((int)$booking['counselor_id'], '收到咨询后反馈', '评分：' . (int)$feedback['rating'] . ' 分；' . ((int)$feedback['wants_followup'] === 1 ? '会员希望继续随访。' : '会员未请求随访。'), '/admin/counselor-home/bookings', $bookingId);
            return $feedback;
        });
    }

    public static function retryBridge(int $bookingId, int $counselorId, int $caseId = 0): array
    {
        return self::transaction(function () use ($bookingId, $counselorId, $caseId): array {
            if ($caseId < 0) throw new \InvalidArgumentException('个案编号不合法');
            $booking = Database::fetch('SELECT * FROM bookings WHERE id=? FOR UPDATE', [$bookingId]);
            if (!$booking) throw new \InvalidArgumentException('预约不存在');
            if ((string)$booking['status'] !== 'completed') throw new \InvalidArgumentException('预约尚未完成');
            if ((int)$booking['counselor_id'] !== $counselorId) throw new \InvalidArgumentException('无权操作该预约反馈');
            $feedback = Database::fetch('SELECT * FROM booking_feedbacks WHERE booking_id=? FOR UPDATE', [$bookingId]);
            if (!$feedback) throw new \InvalidArgumentException('该预约尚无反馈记录');
            if ((int)$feedback['counselor_id'] !== $counselorId) throw new \InvalidArgumentException('反馈归属不一致');
            if ((string)$feedback['status'] !== 'submitted' || (int)$feedback['wants_followup'] !== 1) {
                throw new \InvalidArgumentException('仅已提交且需要随访的反馈可重试桥接');
            }
            $member = Database::fetch("SELECT * FROM members WHERE id=? AND phone=? AND status='active' FOR UPDATE", [(int)$feedback['member_id'], (string)$booking['phone']]);
            if (!$member) throw new \InvalidArgumentException('会员无效或反馈手机号归属不一致');

            $sourceKey = 'booking_feedback:' . $bookingId;
            $existing = Database::fetch('SELECT * FROM case_followups WHERE source_key=?', [$sourceKey]);
            if ($caseId === 0) {
                if ($existing) return self::feedbackById((int)$feedback['id']);
                if ($feedback['case_id'] === null && (string)$feedback['bridge_status'] === 'waiting_case') {
                    return self::feedbackById((int)$feedback['id']);
                }
                $changed = Database::run(
                    'UPDATE booking_feedbacks SET case_id=NULL, bridge_status=?, updated_at=? WHERE id=? AND status=?',
                    ['waiting_case', date('Y-m-d H:i:s'), (int)$feedback['id'], 'submitted']
                );
                if ($changed->rowCount() !== 1) throw new \RuntimeException('反馈桥接状态更新失败');
                return self::feedbackById((int)$feedback['id']);
            }

            $case = Database::fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId]);
            if (!$case || (string)$case['status'] !== 'active' || (int)$case['member_id'] !== (int)$feedback['member_id'] || (int)$case['counselor_id'] !== $counselorId) {
                throw new \InvalidArgumentException('个案不存在、已结束或与预约会员和咨询师不一致');
            }
            $followup = self::createBridgeFollowup($bookingId, $counselorId, $caseId);
            if (empty($followup['ok'])) throw new \RuntimeException((string)($followup['msg'] ?? '随访创建失败'));
            if ((int)$feedback['case_id'] === $caseId && (string)$feedback['bridge_status'] === 'created') {
                return self::feedbackById((int)$feedback['id']);
            }
            $changed = Database::run(
                "UPDATE booking_feedbacks SET case_id=?, bridge_status='created', updated_at=? WHERE id=? AND status=?",
                [$caseId, date('Y-m-d H:i:s'), (int)$feedback['id'], 'submitted']
            );
            if ($changed->rowCount() !== 1) throw new \RuntimeException('反馈桥接状态更新失败');
            if (empty($existing) && empty($followup['existing'])) {
                self::notifyMember((int)$feedback['member_id'], '咨询后反馈随访已安排', '机构已为您安排咨询后反馈随访。', '/member/bookings', 'booking_feedback', (string)$feedback['id']);
                self::notifyCounselor($counselorId, '咨询后反馈随访已安排', '请在个案照护中查看本次反馈随访。', '/admin/counselor-home/cases/' . $caseId . '/care', $bookingId);
            }
            return self::feedbackById((int)$feedback['id']);
        });
    }

    public static function forMemberBooking(int $bookingId, int $memberId): ?array
    {
        return Database::fetch(
            "SELECT f.* FROM booking_feedbacks f
             JOIN bookings b ON b.id=f.booking_id
             JOIN members m ON m.id=? AND m.phone=b.phone AND m.status='active'
             WHERE f.booking_id=? AND f.member_id=?",
            [$memberId, $bookingId, $memberId]
        );
    }

    public static function forCounselorBooking(int $bookingId, int $counselorId): ?array
    {
        return Database::fetch(
            'SELECT f.* FROM booking_feedbacks f
             JOIN bookings b ON b.id=f.booking_id AND b.counselor_id=?
             JOIN counselors c ON c.id=b.counselor_id AND c.status=1
             WHERE f.booking_id=? AND f.counselor_id=?',
            [$counselorId, $bookingId, $counselorId]
        );
    }

    private static function activeCase(int $memberId, int $counselorId): ?array
    {
        return Database::fetch(
            "SELECT * FROM cases WHERE member_id=? AND counselor_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$memberId, $counselorId]
        );
    }

    private static function createBridgeFollowup(int $bookingId, int $counselorId, int $caseId): array
    {
        return CaseCareService::addFollowup($caseId, $counselorId, [
            'booking_id' => $bookingId,
            'session_id' => 0,
            'source' => 'booking_feedback',
            'source_key' => 'booking_feedback:' . $bookingId,
            'due_at' => date('Y-m-d', strtotime('+1 day')),
            'channel' => 'phone',
            'purpose' => '咨询后反馈随访',
        ]);
    }

    private static function feedbackById(int $feedbackId): array
    {
        $feedback = Database::fetch('SELECT * FROM booking_feedbacks WHERE id=?', [$feedbackId]);
        if (!$feedback) throw new \RuntimeException('咨询后反馈读取失败');
        return $feedback;
    }

    private static function notifyMember(int $memberId, string $title, string $body, string $url, string $refType, string $refId): void
    {
        $id = Database::insert('member_notifications', [
            'member_id' => $memberId,
            'kind' => 'booking',
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 500),
            'url' => $url,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($id <= 0) throw new \RuntimeException('会员站内信写入失败');
    }

    private static function notifyCounselor(int $counselorId, string $title, string $body, string $url, int $bookingId): void
    {
        $id = Database::insert('counselor_notifications', [
            'counselor_id' => $counselorId,
            'kind' => 'booking',
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 500),
            'url' => $url,
            'booking_id' => $bookingId,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($id <= 0) throw new \RuntimeException('咨询师站内信写入失败');
    }

    private static function transaction(callable $fn): array
    {
        return Database::pdo()->inTransaction() ? $fn() : Database::transaction($fn);
    }
}
