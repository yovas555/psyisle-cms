<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/** 咨询师通知：站内、服务号、短信；单通道结果供支付通知 Outbox 重试。 */
class CounselorNotifyService
{
    public const EVENTS = [
        'booking_new' => '新预约',
        'booking_confirm' => '预约已确认',
        'booking_cancel' => '预约已取消',
        'booking_reschedule' => '会员申请改期',
        'booking_completed' => '咨询已完成',
        'session_remind' => '今日咨询提醒',
        'session_starting' => '咨询即将开始',
        'note_remind' => '小结待补充',
        'system' => '系统通知',
        'note_published' => '小结已发布',
    ];

    /** 总入口：站内 + 微信 + 短信 */
    public static function push(int $counselorId, string $event, array $data = []): void
    {
        foreach (['inapp', 'wechat', 'sms'] as $channel) {
            [$status, $error] = self::sendChannel($counselorId, $channel, $event, $data);
            if ($status === 'failed') {
                Logger::error('counselor-notify', $channel . ' failed: ' . $error, ['event' => $event, 'counselor' => $counselorId]);
            }
        }
    }

    /** @return array{0:string,1:string} status=sent/skipped/blocked/failed */
    public static function sendChannel(int $counselorId, string $channel, string $event, array $data = []): array
    {
        try {
            if (!Database::fetch('SELECT id FROM counselors WHERE id=?', [$counselorId])) {
                return ['blocked', '咨询师不存在'];
            }
            $title = (string)($data['title'] ?? (self::EVENTS[$event] ?? $event));
            switch ($channel) {
                case 'inapp': return self::inApp($counselorId, $event, $title, $data);
                case 'wechat': return self::wechat($counselorId, $event, $title, $data);
                case 'sms': return self::sms($counselorId, $event, $title, $data);
                default: return ['blocked', '未知通知通道'];
            }
        } catch (\Throwable $t) {
            Logger::error('counselor-notify', $channel . ': ' . $t->getMessage());
            return ['failed', str_take($t->getMessage(), 180, '')];
        }
    }

    private static function inApp(int $counselorId, string $event, string $title, array $data): array
    {
        Database::insert('counselor_notifications', [
            'counselor_id' => $counselorId,
            'kind' => strpos($event, 'booking') === 0 ? 'booking' : 'system',
            'title' => str_take($title, 119, ''),
            'body' => str_take((string)($data['body'] ?? ''), 499, ''),
            'url' => (string)($data['url'] ?? '/admin/counselor-home'),
            'booking_id' => (int)($data['booking_id'] ?? 0) ?: null,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['sent', ''];
    }

    private static function wechat(int $counselorId, string $event, string $title, array $data): array
    {
        if (!(bool)setting('wechat.enabled', '0')) return ['skipped', '服务号通知未启用'];
        $wx = Database::fetch('SELECT * FROM counselor_wx WHERE counselor_id=?', [$counselorId]);
        $openid = trim((string)($wx['openid'] ?? ''));
        if ($openid === '') return ['blocked', '咨询师未绑定服务号'];
        if (class_exists(\plugins\wxhubclient\Plugin::class) && \plugins\wxhubclient\Plugin::hubReady()) {
            [$ok, $identity, $err] = \plugins\wxhubclient\Plugin::identityLookup($openid, (string)($wx['unionid'] ?? ''));
            if (!$ok) return ['failed', '服务号关注状态查询失败：' . $err];
            if (empty($identity['found']) || empty($identity['is_followed'])) {
                return ['blocked', '咨询师尚未关注服务号'];
            }
        }
        $tplId = trim((string)setting('wechat.tpl_booking', ''));
        if ($tplId === '') return ['skipped', '未配置预约模板消息'];
        $body = str_take((string)($data['body'] ?? ''), 40, '');
        $ref = [
            'type' => 'booking', 'id' => (int)($data['booking_id'] ?? 0),
            'url' => url('/admin/counselor-home/bookings'),
            'counselor_name' => (string)($data['counselor_name'] ?? ''),
            'service_name' => (string)($data['service_name'] ?? ''),
            'start_time' => (string)($data['start_time'] ?? ''),
            'place' => (string)($data['place'] ?? ''),
            'client' => (string)($data['client'] ?? ''),
        ];
        if (in_array($event, ['booking_confirm', 'booking_new'], true)) {
            $sent = \plugins\wechatnotify\Plugin::pushMemberTemplate(
                $openid, $tplId, $event,
                (string)setting('site.name', '') . ' · ' . (self::EVENTS[$event] ?? '通知'),
                $body !== '' ? $body : str_take($title, 40, ''),
                $ref, (string)$ref['url']
            );
        } else {
            $sent = \plugins\wechatnotify\Plugin::pushTemplateTo(
                $openid, $tplId, (string)setting('site.name', '') . ' · ' . (self::EVENTS[$event] ?? '通知'),
                $body !== '' ? $body : str_take($title, 40, ''), $event, $ref
            );
        }
        $thisLog = ['counselor_id' => $counselorId, 'event' => $event, 'channel' => 'wechat'];
        Database::insert('counselor_notify_log', $thisLog + [
            'status' => $sent ? 'ok' : 'fail', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $sent ? ['sent', ''] : ['failed', '服务号模板消息发送失败'];
    }

    private static function sms(int $counselorId, string $event, string $title, array $data): array
    {
        $paid = !empty($data['paid']);
        if (!(bool)setting('cns.sms_push', '0') && !$paid) return ['skipped', '咨询师短信推送未开启'];
        if (!class_exists(\plugins\smsnotify\Plugin::class) || !(bool)setting('sms.enabled', '0')) {
            return ['skipped', '短信通道未启用'];
        }
        $csl = Database::fetch('SELECT phone FROM counselors WHERE id=?', [$counselorId]);
        $phone = preg_match('/^1[3-9]\d{9}$/', trim((string)($csl['phone'] ?? ''))) ? trim((string)$csl['phone']) : '';
        if ($phone === '') return ['blocked', '咨询师未绑定有效手机号'];
        $tpl = trim((string)setting('sms.template_code', ''));
        if ($tpl === '') return ['skipped', '未配置短信模板'];
        $result = \plugins\smsnotify\Plugin::sendAliyun($phone, json_encode([
            'store' => str_take((string)setting('site.name', ''), 12, ''),
            'event' => str_take((self::EVENTS[$event] ?? '通知') . '：' . $title, 20, ''),
            'time' => date('m-d H:i'),
        ], JSON_UNESCAPED_UNICODE), $tpl);
        $ok = $result === true;
        Database::insert('counselor_notify_log', [
            'counselor_id' => $counselorId, 'event' => $event, 'channel' => 'sms',
            'status' => $ok ? 'ok' : 'fail', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $ok ? ['sent', ''] : ['failed', is_string($result) ? $result : '短信发送失败'];
    }

    public static function unreadCount(int $counselorId): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM counselor_notifications WHERE counselor_id=? AND is_read=0', [$counselorId]);
    }
}
