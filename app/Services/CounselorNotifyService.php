<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Hook;
use app\Core\Logger;
use app\Core\Session;

/**
 * 咨询师通知服务（v2.14.0）
 *
 * 三通道：站内信（counselor_notifications → 咨询师工作台）+ 服务号微信（counselor_wx 绑定的 OpenID）
 * + 短信（counselors.phone，受 settings cns.sms_push 开关控制——管理员手动开启咨询师短信推送）。
 *
 * 事件：booking_new / booking_confirm / booking_cancel / booking_reschedule / session_remind / note_remind。
 * 约束：通知只给本人咨询师；微信走服务号模板消息（与会员共用模板池，经 wechat-notify 的 pushTemplate）。
 */
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
    ];

    /** 总入口：站内 + 微信 + 短信 */
    public static function push(int $counselorId, string $event, array $data = []): void
    {
        try {
            $csl = Database::fetch('SELECT * FROM counselors WHERE id=?', [$counselorId]);
            if (!$csl) return;
            $title = (string)($data['title'] ?? (self::EVENTS[$event] ?? $event));
            self::inApp($counselorId, $event, $title, $data);
            self::wechat($counselorId, $event, $title, $data);
            self::sms($counselorId, $event, $title, $data);
        } catch (\Throwable $t) {
            Logger::error('counselor-notify', 'push failed: ' . $t->getMessage(), ['event' => $event, 'counselor' => $counselorId]);
        }
    }

    /** 站内信 */
    private static function inApp(int $counselorId, string $event, string $title, array $data): void
    {
        try {
            Database::insert('counselor_notifications', [
                'counselor_id' => $counselorId, 'kind' => str_starts_with($event, 'booking') ? 'booking' : 'system',
                'title' => str_take($title, 119, ''),
                'body' => str_take((string)($data['body'] ?? ''), 499, ''),
                'url' => (string)($data['url'] ?? '/admin/counselor-home'),
                'booking_id' => (int)($data['booking_id'] ?? 0) ?: null,
                'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('counselor-notify', 'inApp: ' . $t->getMessage());
        }
    }

    /** 服务号推送（wechat-notify 的 pushTemplate；咨询师 OpenID 从 counselor_wx） */
    private static function wechat(int $counselorId, string $event, string $title, array $data): void
    {
        try {
            if (!(bool)setting('wechat.enabled', '0')) return;
            $wx = Database::fetch('SELECT * FROM counselor_wx WHERE counselor_id=?', [$counselorId]);
            if (!$wx) return;
            $tplId = trim((string)setting('wechat.tpl_booking', ''));
            if ($tplId === '') return;
            $sent = \plugins\wechatnotify\Plugin::pushTemplateTo($wx['openid'], $tplId,
                (string)setting('site.name', '') . ' · ' . (self::EVENTS[$event] ?? '通知'),
                str_take((string)($data['body'] ?? ''), 40, ''), 'booking');
            Database::insert('counselor_notify_log', [
                'counselor_id' => $counselorId, 'event' => $event, 'channel' => 'wechat',
                'status' => $sent ? 'ok' : 'fail', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('counselor-notify', 'wechat: ' . $t->getMessage());
        }
    }

    /** 短信（settings cns.sms_push=1 时开启） */
    private static function sms(int $counselorId, string $event, string $title, array $data): void
    {
        try {
            if (!(bool)setting('cns.sms_push', '0')) return;
            if (!class_exists(\plugins\smsnotify\Plugin::class) || !(bool)setting('sms.enabled', '0')) return;
            $csl = Database::fetch('SELECT phone FROM counselors WHERE id=?', [$counselorId]);
            $phone = preg_match('/^1[3-9]\d{9}$/', trim((string)($csl['phone'] ?? ''))) ? trim((string)$csl['phone']) : '';
            if ($phone === '') return;
            $tpl = trim((string)setting('sms.template_code', ''));
            if ($tpl === '') return;
            $site = (string)setting('site.name', '');
            $ok = \plugins\smsnotify\Plugin::sendAliyun($phone, json_encode([
                'store' => str_take($site, 12, ''),
                'event' => str_take((self::EVENTS[$event] ?? '通知') . '：' . $title, 20, ''),
                'time' => date('m-d H:i'),
            ], JSON_UNESCAPED_UNICODE), $tpl) === true;
            Database::insert('counselor_notify_log', [
                'counselor_id' => $counselorId, 'event' => $event, 'channel' => 'sms',
                'status' => $ok ? 'ok' : 'fail', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('counselor-notify', 'sms: ' . $t->getMessage());
        }
    }

    /** 工作台未读数（徽标） */
    public static function unreadCount(int $counselorId): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM counselor_notifications WHERE counselor_id=? AND is_read=0', [$counselorId]);
    }


}
