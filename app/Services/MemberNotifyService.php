<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Hook;
use app\Core\Logger;
use app\Core\Mailer;

/**
 * 会员通知总线（v2.9.0）
 *
 * 定位（用户拍板）：
 *  - **邮件负责所有通知**：预约成功/取消/改期、套餐购买、交易、咨询套餐划扣变动——发送到会员邮箱（members.email）；
 *  - **接入微信服务号后双发**：wxhub 中台通道或 wechat-login 绑定的 openid，模板字段对标微信公众平台（first/keyword1-4/remark 超集）；
 *  - **短信启用后允许关闭邮件与微信**（通知偏好 members.notify_prefs）。
 *
 * 路由顺序：微信（Hook member.notify.wechat，wxhub/wxlogin 插件响应）→ 邮件 → 全部未发则台账记 skipped。
 * 事件清单（与中台事件名对齐）：booking_new / booking_confirm / booking_cancel / booking_reschedule /
 * booking_remind / order_created / order_paid / order_confirmed / ledger_change / assess_report。
 */
class MemberNotifyService
{
    /** 事件中文名（台账与邮件标题用） */
    public const EVENTS = [
        'booking_new' => '预约已提交',
        'booking_confirm' => '预约已确认',
        'booking_cancel' => '预约已取消',
        'booking_reschedule' => '预约改期申请',
        'booking_remind' => '咨询提醒',
        'order_created' => '咨询套餐下单成功',
        'order_paid' => '支付成功',
        'order_confirmed' => '套餐开通成功',
        'ledger_change' => '咨询套餐变动',
        'assess_report' => '测评报告',
        'session_starting' => '咨询即将开始',
        'session_room_open' => '咨询室已开启',
    ];
    /** 事件 → 是否同时发短信（会员侧） */
    public const SMS_EVENTS = [
        'booking_new' => false,       // 提交时机构未确认，省一条
        'booking_confirm' => true,
        'booking_cancel' => true,
        'booking_reschedule' => true,
        'booking_remind' => true,
        'order_paid' => true,
        'ledger_change' => true,
    ];

    /** 按手机号找会员 id（通知集成用）；找不到返回 0 */
    public static function memberIdByPhone(string $phone): int
    {
        if (!preg_match('/^1[3-9]\d{9}$/', trim($phone))) return 0;
        try {
            return (int)(Database::value('SELECT id FROM members WHERE phone=? AND status="active"', [trim($phone)]) ?: 0);
        } catch (\Throwable $t) {
            return 0;
        }
    }

    /** 会员通知总入口
     * @param int $memberId
     * @param string $event 事件名（见 EVENTS）
     * @param array $data 字段：title(必传)、lines[][k,v]（键值对）、url、note
     */
    public static function push(int $memberId, string $event, array $data = []): void
    {
        try {
            $m = Database::fetch('SELECT * FROM members WHERE id=?', [$memberId]);
            if (!$m || ($m['status'] ?? '') !== 'active') return;
            $title = (string)($data['title'] ?? (self::EVENTS[$event] ?? $event));
            $prefs = MemberService::notifyPrefs($memberId);
            $sent = false;

            // 0. 站内信（v2.14.0：会员中心「站内消息」，所有事件落一条，可跳转）
            self::inApp($memberId, $event, $title, $data);

            // 1. 微信通道（插件 Hook：wxhub 中台 / wechat-login 直连 响应并返回 true）
            if ($prefs['wechat']) {
                $ctx = Hook::fire('member.notify.wechat', [
                    'member' => $m, 'event' => $event, 'title' => $title,
                    'data' => $data, 'sent' => false, 'skip' => '',
                ]);
                if ((bool)($ctx['sent'] ?? false)) {
                    self::log((int)$m['id'], $event, 'wechat', 'ok', null, $title);
                    $sent = true;
                } elseif (($ctx['skip'] ?? '') !== '') {
                    self::log((int)$m['id'], $event, 'wechat', 'skipped', str_take((string)$ctx['skip'], 110, ''), $title);
                }
            } else {
                self::log((int)$m['id'], $event, 'wechat', 'skipped', '会员偏好已关闭微信通知', $title);
            }

            // 2. 邮件通道（偏好开 + 有邮箱）
            if ($prefs['mail']) {
                $email = (string)($m['email'] ?? '');
                if ($email === '') {
                    self::log((int)$m['id'], $event, 'mail', 'skipped', '会员未绑定邮箱', $title);
                } else {
                    $ok = self::sendMail($email, $m, $title, $data, $event);
                    self::log((int)$m['id'], $event, 'mail', $ok ? 'ok' : 'fail', $ok ? null : 'SMTP 发送失败', $title);
                    if ($ok) $sent = true;
                }
            } else {
                self::log((int)$m['id'], $event, 'mail', 'skipped', '会员偏好已关闭邮件通知', $title);
            }

            // 3. 短信通道（v2.14.0：按事件矩阵 + 手机号 + 短信插件可用）
            if (in_array($event, self::SMS_EVENTS, true) && ($prefs['sms'] ?? false) && ($m['phone'] ?? '') !== '') {
                $site = (string)setting('site.name', '');
                $label = self::EVENTS[$event] ?? '通知';
                $ok = \plugins\smsnotify\Plugin::sendMemberSms((string)$m['phone'],
                    ['site' => str_take($site, 12, ''), 'event' => str_take($label . '：' . $title, 20, ''), 'time' => date('m-d H:i')]);
                self::log((int)$m['id'], $event, 'sms', $ok ? 'ok' : 'fail', $ok ? null : '阿里云发送失败（看 sms-notify 日志）', $title);
            } else {
                self::log((int)$m['id'], $event, 'sms', 'skipped', '事件不发短信或通道未启用', $title);
            }
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'push failed: ' . $t->getMessage(), ['event' => $event, 'member' => $memberId]);
        }
    }

    /** 会员站内信（member_notifications）：url 为站内跳转路径 */
    private static function inApp(int $memberId, string $event, string $title, array $data): void
    {
        try {
            $kind = str_starts_with($event, 'booking') ? 'booking'
                : (str_starts_with($event, 'order') || str_starts_with($event, 'ledger') ? 'order'
                : (str_starts_with($event, 'assess') ? 'assess' : 'system'));
            $ref = (array)($data['ref'] ?? []);
            Database::insert('member_notifications', [
                'member_id' => $memberId, 'kind' => $kind, 'title' => str_take($title, 119, ''),
                'body' => str_take((string)($data['body'] ?? json_encode($data['lines'] ?? [], JSON_UNESCAPED_UNICODE)), 499, ''),
                'url' => (string)($data['inapp_url'] ?? '/member'),
                'ref_type' => (string)($data['ref_type'] ?? ($ref['type'] ?? '')) ?: null,
                'ref_id' => (string)($data['ref_id'] ?? ($ref['id'] ?? '')) ?: null,
                'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'inApp failed: ' . $t->getMessage());
        }
    }

    /** 品牌邮件（对标 VIP 站邮件口径：清晰正文+键值明细+行动链接） */
    private static function sendMail(string $to, array $m, string $title, array $data, string $event): bool
    {
        $site = (string)setting('site.name', '心理咨询中心');
        $lines = (array)($data['lines'] ?? []);
        $url = (string)($data['url'] ?? base_url() . '/member');
        $rows = '';
        foreach ($lines as $k => $v) {
            $rows .= '<tr><td style="color:#64748b;padding:6px 14px 6px 0;white-space:nowrap">' . e((string)$k) . '</td>'
                . '<td style="padding:6px 0">' . e((string)$v) . '</td></tr>';
        }
        $html = '<div style="max-width:560px;margin:0 auto;font-family:-apple-system,PingFang SC,Microsoft YaHei,sans-serif;color:#173010">'
            . '<div style="border-bottom:2px solid #0d9488;padding:12px 0;margin-bottom:14px">'
            . '<span style="font-size:17px;font-weight:700">' . e($site) . '</span>'
            . '<span style="float:right;color:#64748b;font-size:13px">' . e(date('Y-m-d H:i')) . '</span></div>'
            . '<h2 style="font-size:17px;margin:0 0 10px">' . e($title) . '</h2>'
            . ($rows !== '' ? '<table style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>' : '')
            . ((string)($data['note'] ?? '') !== '' ? '<p style="font-size:13px;color:#475569">' . e((string)$data['note']) . '</p>' : '')
            . '<p style="margin:16px 0"><a href="' . e($url) . '" style="display:inline-block;background:#0d9488;color:#fff;padding:9px 20px;border-radius:8px;text-decoration:none;font-size:14px">进入会员中心查看</a></p>'
            . '<p style="font-size:12px;color:#94a3b8;margin-top:18px">本邮件由系统自动发送，请勿直接回复。紧急情况请拨打 12356（心理援助热线）。</p>'
            . '</div>';
        try {
            $r = Mailer::send($to, '【' . $site . '】' . $title, $html);
            return (bool)($r['ok'] ?? false);
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'mail failed: ' . $t->getMessage());
            return false;
        }
    }

    private static function log(int $memberId, string $event, string $channel, string $status, ?string $reason, string $title): void
    {
        try {
            Database::insert('member_notify_log', [
                'member_id' => $memberId, 'event' => $event, 'channel' => $channel,
                'status' => $status, 'skip_reason' => $reason !== null ? str_take($reason, 110, '') : null,
                'title' => str_take($title, 90, ''), 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'log failed: ' . $t->getMessage());
        }
    }

    /** 会员通知台账（后台排查） */
    public static function recent(int $limit = 50): array
    {
        try {
            return Database::fetchAll(
                'SELECT l.*, m.phone FROM member_notify_log l LEFT JOIN members m ON m.id=l.member_id
                 ORDER BY l.id DESC LIMIT ' . max(1, min(200, $limit)));
        } catch (\Throwable $t) {
            return [];
        }
    }

    /** 会员自己的通知记录（会员中心） */
    public static function myLog(int $memberId, int $limit = 20): array
    {
        try {
            return Database::fetchAll(
                'SELECT event, channel, status, skip_reason, title, created_at FROM member_notify_log
                 WHERE member_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), [$memberId]);
        } catch (\Throwable $t) {
            return [];
        }
    }
}
