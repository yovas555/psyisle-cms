<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Hook;
use app\Core\Logger;
use app\Core\Mailer;

/**
 * 会员通知总线（v2.9.0，v2.17.0 通道定位与短信兜底重构）
 *
 * 定位（2026-11-19 用户拍板）：
 *  - **站内信**：事实台账，恒发，全部事件落一条（会员中心可查、可标记已读）；
 *  - **微信服务号**：会员信息推送的主力通道（wxhub 中台 / wechat-login 直连）；
 *  - **邮件**：定位收敛——网站敏感信息与系统信息送管理员邮箱（NotifyService/mail-notify 负责）；
 *    会员侧邮件只用于：帐号密码重置、预约成功、咨询开始前提醒（MAIL_EVENTS 矩阵）；
 *  - **短信**：兜底通道——微信与邮件推送后**至少 30 分钟未被查阅**才补发（notify_escalations 台账 +
 *    Cron 每分钟 sweep）；时间敏感事件（session_starting/session_room_open）例外直发。
 *
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
        'booking_completed' => '咨询已完成',
        'booking_remind' => '咨询提醒',
        'order_created' => '咨询套餐下单成功',
        'order_paid' => '支付成功',
        'order_confirmed' => '套餐开通成功',
        'ledger_change' => '咨询套餐变动',
        'assess_report' => '测评报告',
        'session_starting' => '咨询即将开始',
        'session_room_open' => '咨询室已开启',
    ];
    /**
     * 事件 → 是否发邮件（v2.17.0 定位收敛）：会员侧邮件只用于
     * 预约成功（confirm）、咨询开始前提醒（remind/session_starting/room_open）；
     * 密码重置邮件在 MemberService 独立发送，不属事件矩阵；
     * 管理员系统/敏感信息邮件走 NotifyService（mail-notify），不经本服务。
     */
    public const MAIL_EVENTS = [
        'booking_confirm' => true,
        'booking_cancel' => false,    // 取消/改期走微信+站内，邮件不发（减少噪音）
        'booking_reschedule' => false,
        'booking_completed' => false,
        'booking_remind' => true,
        'session_starting' => true,
        'session_room_open' => true,
    ];

    /**
     * 事件 → 短信通道语义（v2.17.0 短信兜底制）：
     *  - instant：时间敏感，推送即发短信（咨询开始/进房类，等不了 30 分钟）；
     *  - fallback：站内+微信+邮件先推，30 分钟无查阅回执由 Cron sweep 补发短信；
     *  - 其余事件不发短信。
     */
    public const INSTANT_SMS_EVENTS = [
        'session_starting',
        'session_room_open',
    ];
    public const FALLBACK_SMS_EVENTS = [
        'booking_confirm',
        'booking_cancel',
        'booking_reschedule',
        'booking_completed',
        'booking_remind',
        'order_paid',
        'ledger_change',
    ];
    /** 兼容旧引用：任意会发短信的事件（即时+兜底） */
    public const SMS_EVENTS = [
        'booking_confirm' => 'fallback',
        'booking_cancel' => 'fallback',
        'booking_reschedule' => 'fallback',
        'booking_completed' => 'fallback',
        'booking_remind' => 'fallback',
        'order_paid' => 'fallback',
        'ledger_change' => 'fallback',
        'session_starting' => 'instant',
        'session_room_open' => 'instant',
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
     * @param array $data 字段：title(必传)、lines[][k,v]（键值对）、url、note、inapp_url、ref_type/ref_id
     */
    public static function push(int $memberId, string $event, array $data = []): void
    {
        try {
            self::ensureEscalationTable();
            $m = Database::fetch('SELECT * FROM members WHERE id=?', [$memberId]);
            if (!$m || ($m['status'] ?? '') !== 'active') return;
            $title = (string)($data['title'] ?? (self::EVENTS[$event] ?? $event));
            $prefs = MemberService::notifyPrefs($memberId);
            $smsMode = self::SMS_EVENTS[$event] ?? null;   // instant | fallback | null

            // 0. 站内信（事实台账，恒发；拿 id 供查阅回执关联）
            $inappId = self::inApp($memberId, $event, $title, $data);

            // 0.5 兜底台账（v2.17.0：fallback 事件登记，30 分钟无查阅 → Cron sweep 补短信）
            $escToken = null; $escId = 0;
            if ($smsMode === 'fallback') {
                $esc = self::registerEscalation((int)$m['id'], $event, $data, $inappId);
                $escToken = $esc['token'] ?? null;
                $escId = (int)($esc['id'] ?? 0);
            }
            $clickUrl = $escToken !== null ? rtrim(base_url(), '/') . '/n/' . $escToken : '';

            // 1. 微信通道（插件 Hook：wxhub 中台 / wechat-login 直连 响应并返回 true；click_url 供模板消息跳转采集查阅回执）
            $wechatOk = false;
            if ($prefs['wechat']) {
                $ctx = Hook::fire('member.notify.wechat', [
                    'member' => $m, 'event' => $event, 'title' => $title,
                    'data' => $data, 'sent' => false, 'skip' => '', 'click_url' => $clickUrl,
                ]);
                if ((bool)($ctx['sent'] ?? false)) {
                    self::log((int)$m['id'], $event, 'wechat', 'ok', null, $title);
                    $wechatOk = true;
                } elseif (($ctx['skip'] ?? '') !== '') {
                    self::log((int)$m['id'], $event, 'wechat', 'skipped', str_take((string)$ctx['skip'], 110, ''), $title);
                }
            } else {
                self::log((int)$m['id'], $event, 'wechat', 'skipped', '会员偏好已关闭微信通知', $title);
            }

            // 2. 邮件通道（v2.17.0：MAIL_EVENTS 矩阵 + 偏好 + 有邮箱；CTA 走中转短链采集查阅回执）
            $mailOk = false;
            if (self::MAIL_EVENTS[$event] ?? false) {
                if ($prefs['mail']) {
                    $email = (string)($m['email'] ?? '');
                    if ($email === '') {
                        self::log((int)$m['id'], $event, 'mail', 'skipped', '会员未绑定邮箱', $title);
                    } else {
                        if ($clickUrl !== '') { $data['cta_url'] = $clickUrl; }
                        $ok = self::sendMail($email, $m, $title, $data, $event);
                        self::log((int)$m['id'], $event, 'mail', $ok ? 'ok' : 'fail', $ok ? null : 'SMTP 发送失败', $title);
                        $mailOk = $ok;
                    }
                } else {
                    self::log((int)$m['id'], $event, 'mail', 'skipped', '会员偏好已关闭邮件通知', $title);
                }
            }

            // 3. 短信通道：instant 直发；fallback 已登记台账等 sweep；其余不发
            if ($smsMode === 'instant') {
                if (($prefs['sms'] ?? false) && ($m['phone'] ?? '') !== '' && class_exists(\plugins\smsnotify\Plugin::class) && (bool)setting('sms.enabled', '0')) {
                    $site = (string)setting('site.name', '');
                    $label = self::EVENTS[$event] ?? '通知';
                    $ok = \plugins\smsnotify\Plugin::sendMemberSms((string)$m['phone'],
                        ['store' => str_take($site, 12, ''), 'event' => str_take($label . '：' . $title, 20, ''), 'time' => date('m-d H:i')]);
                    self::log((int)$m['id'], $event, 'sms', $ok ? 'ok' : 'fail', $ok ? null : '阿里云发送失败（看 sms-notify 日志）', $title);
                } else {
                    self::log((int)$m['id'], $event, 'sms', 'skipped', '短信通道未启用或偏好关闭', $title);
                }
            } elseif ($smsMode === 'fallback') {
                self::log((int)$m['id'], $event, 'sms', 'skipped', '短信兜底待触发（30 分钟无查阅后补发）', $title);
            } else {
                self::log((int)$m['id'], $event, 'sms', 'skipped', '事件不发短信', $title);
            }

            // 0.6 回填兜底台账：微信/邮件触达结果
            if ($escId > 0) {
                try {
                    Database::run('UPDATE notify_escalations SET wechat_ok=?, mail_ok=? WHERE id=?',
                        [$wechatOk ? 1 : 0, $mailOk ? 1 : 0, $escId]);
                } catch (\Throwable $t) {
                    Logger::error('member-notify', 'escalation update: ' . $t->getMessage());
                }
            }
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'push failed: ' . $t->getMessage(), ['event' => $event, 'member' => $memberId]);
        }
    }

    /** 会员站内信（member_notifications）：url 为站内跳转路径；返回插入 id（0=失败） */
    private static function inApp(int $memberId, string $event, string $title, array $data): int
    {
        try {
            $kind = str_starts_with($event, 'booking') ? 'booking'
                : (str_starts_with($event, 'order') || str_starts_with($event, 'ledger') ? 'order'
                : (str_starts_with($event, 'assess') ? 'assess' : 'system'));
            $ref = (array)($data['ref'] ?? []);
            return (int)Database::insert('member_notifications', [
                'member_id' => $memberId, 'kind' => $kind, 'title' => str_take($title, 119, ''),
                'body' => str_take((string)($data['body'] ?? json_encode($data['lines'] ?? [], JSON_UNESCAPED_UNICODE)), 499, ''),
                'url' => (string)($data['inapp_url'] ?? '/member'),
                'ref_type' => (string)($data['ref_type'] ?? ($ref['type'] ?? '')) ?: null,
                'ref_id' => (string)($data['ref_id'] ?? ($ref['id'] ?? '')) ?: null,
                'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'inApp failed: ' . $t->getMessage());
            return 0;
        }
    }

    // ==================== 短信兜底（v2.17.0） ====================

    /** 兜底台账建表（CREATE TABLE IF NOT EXISTS 幂等，老库升级安全） */
    public static function ensureEscalationTable(): void
    {
        try {
            $n = (int)Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notify_escalations'", []);
            if ($n === 0) {
                Database::run("CREATE TABLE IF NOT EXISTS notify_escalations (
                    id int unsigned NOT NULL AUTO_INCREMENT,
                    member_id int unsigned NOT NULL,
                    event varchar(30) NOT NULL,
                    ref_type varchar(20) NULL,
                    ref_id varchar(20) NULL,
                    inapp_id int unsigned NULL,
                    click_token char(16) NOT NULL,
                    wechat_ok tinyint(1) NOT NULL DEFAULT 0,
                    mail_ok tinyint(1) NOT NULL DEFAULT 0,
                    read_at datetime NULL,
                    sms_at datetime NULL,
                    created_at datetime NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_token (click_token),
                    KEY k_member (member_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'ensure escalation table: ' . $t->getMessage());
        }
    }

    /** 登记兜底台账：返回 ['id'=>…, 'token'=>…] */
    private static function registerEscalation(int $memberId, string $event, array $data, int $inappId): array
    {
        $token = bin2hex(random_bytes(8));
        try {
            $id = (int)Database::insert('notify_escalations', [
                'member_id' => $memberId, 'event' => $event,
                'ref_type' => (string)($data['ref_type'] ?? '') ?: null,
                'ref_id' => (string)($data['ref_id'] ?? '') ?: null,
                'inapp_id' => $inappId > 0 ? $inappId : null,
                'click_token' => $token,
                'wechat_ok' => 0, 'mail_ok' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['id' => $id, 'token' => $token];
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'escalation insert: ' . $t->getMessage());
            return ['id' => 0, 'token' => null];
        }
    }

    /** 查阅回执：站内信已读（markRead 时按 inapp_id 关联调用） */
    public static function markEscalationReadByInapp(int $memberId, int $inappId): void
    {
        try {
            if ($inappId > 0) {
                Database::run('UPDATE notify_escalations SET read_at=COALESCE(read_at,NOW()) WHERE member_id=? AND inapp_id=? AND read_at IS NULL', [$memberId, $inappId]);
            }
        } catch (\Throwable $t) { /* 台账缺失静默 */ }
    }

    /** 查阅回执：短链点击（/n/{token} 调用）；返回跳转目标 */
    public static function markEscalationClick(string $token): ?string
    {
        try {
            $row = Database::fetch('SELECT * FROM notify_escalations WHERE click_token=?', [$token]);
            if (!$row) return null;
            Database::run('UPDATE notify_escalations SET read_at=COALESCE(read_at,NOW()) WHERE id=?', [(int)$row['id']]);
            $target = Database::value('SELECT url FROM member_notifications WHERE id=?', [(int)($row['inapp_id'] ?? 0)]);
            return (string)($target ?: '/member');
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'escalation click: ' . $t->getMessage());
            return null;
        }
    }

    /**
     * Cron 每分钟 sweep：推送满 30 分钟仍未查阅 → 补发短信兜底（一次性，sms_at 防重）。
     * 口径（用户拍板 2026-11-19）：「微信和邮件推送都未被查阅（至少半小时）后的补充推送」；
     * read_at 任一信号（站内信已读 / 微信模板链接点击 / 邮件链接点击）即视为已查阅。
     */
    public static function sweepEscalations(): int
    {
        self::ensureEscalationTable();
        if (!class_exists(\plugins\smsnotify\Plugin::class) || !(bool)setting('sms.enabled', '0')
            || trim((string)setting('sms.template_code', '')) === '') {
            return 0;
        }
        $sent = 0;
        try {
            $rows = Database::fetchAll(
                "SELECT e.*, m.phone, m.notify_prefs FROM notify_escalations e
                 JOIN members m ON m.id=e.member_id AND m.status='active'
                 WHERE e.sms_at IS NULL AND e.read_at IS NULL
                   AND e.created_at <= NOW() - INTERVAL 30 MINUTE LIMIT 30");
            foreach ($rows as $e) {
                $prefs = json_decode((string)($e['notify_prefs'] ?? ''), true) ?: [];
                if (($prefs['sms'] ?? true) === false) {
                    Database::run('UPDATE notify_escalations SET sms_at=NOW() WHERE id=?', [(int)$e['id']]);
                    continue;
                }
                $phone = (string)($e['phone'] ?? '');
                if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                    Database::run('UPDATE notify_escalations SET sms_at=NOW() WHERE id=?', [(int)$e['id']]);
                    continue;
                }
                $site = (string)setting('site.name', '');
                $label = self::EVENTS[(string)$e['event']] ?? '通知';
                $ok = \plugins\smsnotify\Plugin::sendMemberSms($phone,
                    ['store' => str_take($site, 12, ''), 'event' => str_take($label . '（提醒查阅）', 20, ''), 'time' => date('m-d H:i')]);
                Database::run('UPDATE notify_escalations SET sms_at=NOW() WHERE id=?', [(int)$e['id']]);
                self::log((int)$e['member_id'], (string)$e['event'], 'sms', $ok ? 'ok' : 'fail',
                    $ok ? null : '短信兜底发送失败', $label . '（30 分钟未查阅兜底）');
                if ($ok) $sent++;
            }
        } catch (\Throwable $t) {
            Logger::error('member-notify', 'sweep: ' . $t->getMessage());
        }
        return $sent;
    }

    /** 品牌邮件（对标 VIP 站邮件口径：清晰正文+键值明细+行动链接）；CTA 优先走中转短链（查阅回执） */
    private static function sendMail(string $to, array $m, string $title, array $data, string $event): bool
    {
        $site = (string)setting('site.name', '心理咨询中心');
        $lines = (array)($data['lines'] ?? []);
        $url = (string)($data['cta_url'] ?? ($data['url'] ?? base_url() . '/member'));
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
