<?php
/** 邮件通知插件：把关键业务事件推送到管理员/值班邮箱 */
declare(strict_types=1);

namespace plugins\mailnotify;

use app\Core\Cache;
use app\Core\Database;
use app\Core\Hook;
use app\Core\Logger;
use app\Core\Mailer;
use app\Core\Model;

final class Plugin
{
    /** 通知类型 → 开关设置键（危机/登录告警永远发送，不在此表） */
    private const TYPE_SWITCHES = [
        'booking' => 'notify.on_booking',
        'message' => 'notify.on_message',
        'chat_question' => 'notify.on_chat_question',
        'chat_escalate' => 'notify.on_chat_escalate',
        'event' => 'notify.on_event',
        'system' => 'notify.on_system',
        'upgrade' => 'notify.on_system',
        'license' => 'notify.on_license',
    ];

    private const COLORS = [
        'booking' => '#2563eb',
        'message' => '#0d9488',
        'chat_question' => '#0ea5e9',
        'chat_escalate' => '#d97706',
        'crisis' => '#dc2626',
        'login_alert' => '#dc2626',
        'system' => '#475569',
        'upgrade' => '#7c3aed',
        'license' => '#7c3aed',
        'event' => '#db2777',
    ];

    private const LABELS = [
        'booking' => '新预约',
        'message' => '新留言',
        'chat_question' => '访客提问',
        'chat_escalate' => 'AI 客服转人工',
        'crisis' => '危机预警',
        'login_alert' => '登录告警',
        'system' => '系统通知',
        'upgrade' => '系统升级',
        'license' => '授权变更',
        'event' => '活动报名',
    ];

    public static function boot(array $meta): void
    {
        // 核心 NotifyService 触发 → 本插件负责发信
        Hook::on('notify.push', [self::class, 'onPush']);
    }

    /** 收件人解析：notify.admin_email 支持 ; ， 逗号分隔 */
    public static function recipients(): array
    {
        $raw = (string)setting('notify.admin_email', '');
        $out = [];
        foreach (preg_split('/[;，,]+/u', $raw) ?: [] as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** 事件入口（由 Hook::fire('notify.push', …) 调用） */
    public static function onPush(array $ctx): array
    {
        $type = (string)($ctx['type'] ?? 'system');
        // 类型开关（安全类不受控）
        if (isset(self::TYPE_SWITCHES[$type]) && !(bool)setting(self::TYPE_SWITCHES[$type], '1')) {
            return $ctx;
        }
        if (!(bool)setting('mail.enabled')) return $ctx;

        $recipients = self::recipients();
        if (!$recipients) return $ctx;

        $urgent = (bool)($ctx['urgent'] ?? true);
        $title = (string)($ctx['title'] ?? '');
        $body = (string)($ctx['body'] ?? '');
        $ref = (array)($ctx['ref'] ?? []);
        $site = (string)setting('site.name', '');
        $label = self::LABELS[$type] ?? '通知';
        $color = self::COLORS[$type] ?? '#475569';
        $subject = sprintf('【%s】%s：%s', $site, $label, str_take($title, 60, ''));
        $html = self::mailHtml($site, $label, $color, $title, $body, $ref);

        // 夜间免打扰：静默期内不直发，入队时间顺延到时段结束（不丢失）
        [$inQuiet, $deliverAt] = \app\Core\QuietHours::resolve($type);
        $scheduledAt = date('Y-m-d H:i:s', $deliverAt);

        if ($urgent && !$inQuiet && Mailer::enabled()) {
            $allOk = true;
            foreach ($recipients as $rcpt) {
                [$ok, $err] = Mailer::send($rcpt, $subject, $html);
                if (!$ok) { $allOk = false; Logger::error('mail-notify', "urgent to {$rcpt} failed: " . $err); }
            }
            if ($allOk) return $ctx;
        }
        // 入队（免打扰/SMTP 未配置/直发失败都会保留，到点自动发出）
        foreach ($recipients as $rcpt) {
            try {
                Database::insert('email_queue', [
                    'to_email' => $rcpt, 'subject' => $subject, 'body_html' => $html,
                    'priority' => $urgent ? 1 : 5, 'status' => 'queued',
                    'scheduled_at' => $scheduledAt, 'attempts' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $t) {
                Logger::error('mail-notify', 'enqueue failed: ' . $t->getMessage());
            }
        }
        if ($inQuiet) {
            Logger::info('mail-notify', "quiet hours: {$type} deferred to {$scheduledAt}");
        }
        return $ctx;
    }

    /** 分钟任务：消费邮件队列 */
    public static function flushQueue(): array
    {
        if (!(bool)setting('mail.enabled') || !Mailer::enabled()) {
            return ['skipped' => 'mail disabled or smtp not configured'];
        }
        $rows = Database::fetchAll(
            "SELECT * FROM email_queue WHERE status='queued' AND scheduled_at <= NOW() AND attempts < 3 ORDER BY priority ASC, id ASC LIMIT 30"
        );
        $sent = 0;
        foreach ($rows as $q) {
            Database::run("UPDATE email_queue SET status='sending' WHERE id=?", [$q['id']]);
            [$ok, $err] = Mailer::send($q['to_email'], $q['subject'], $q['body_html']);
            if ($ok) {
                Database::run("UPDATE email_queue SET status='sent', sent_at=NOW(), error=NULL WHERE id=?", [$q['id']]);
                $sent++;
            } else {
                Database::run(
                    "UPDATE email_queue SET status='queued', attempts=attempts+1, error=? WHERE id=?",
                    [str_take($err, 490, ''), $q['id']]
                );
            }
        }
        return ['found' => count($rows), 'sent' => $sent];
    }

    /** 后台首页：设置 + 队列状态 + 测试发信 */
    public static function adminIndex($req): void
    {
        $pending = (int)Database::value("SELECT COUNT(*) FROM email_queue WHERE status='queued'");
        $failed = (int)Database::value("SELECT COUNT(*) FROM email_queue WHERE status='queued' AND attempts >= 3");
        $recent = Database::fetchAll("SELECT to_email, subject, status, attempts, error, scheduled_at, created_at FROM email_queue ORDER BY id DESC LIMIT 10");
        \app\Core\PluginManager::view('mail-notify', 'admin', [
            'meta' => \app\Core\PluginManager::get('mail-notify'),
            'pending' => $pending,
            'failed' => $failed,
            'recent' => $recent,
            'recipients' => self::recipients(),
            'smtpReady' => Mailer::enabled(),
            'quietState' => \app\Core\QuietHours::describe(),
            'quietOn' => \app\Core\QuietHours::enabled(),
            'quietNext' => \app\Core\QuietHours::isQuiet() ? date('m-d H:i', \app\Core\QuietHours::nextSlot()) : '',
        ]);
    }

    /** 测试发信（表单提交） */
    public static function sendTest(): array
    {
        $to = self::recipients()[0] ?? '';
        if ($to === '') return [false, '未配置接收邮箱'];
        [$ok, $err] = Mailer::send($to, '【' . setting('site.name', '') . '】SMTP 配置测试', '<p>这是一封测试邮件，收到即表示邮件通知插件配置正确。</p>');
        return [$ok, $ok ? '测试邮件已发送至 ' . $to : ('发送失败：' . $err)];
    }

    private static function mailHtml(string $site, string $label, string $color, string $title, string $body, array $ref): string
    {
        $link = '';
        if (!empty($ref['url'])) {
            $link = (string)$ref['url'];
        } elseif (!empty($ref['type'])) {
            $map = ['booking' => '/admin/bookings', 'message' => '/admin/messages', 'chat_escalate' => '/admin/chat', 'event' => '/admin/events'];
            if (isset($map[$ref['type']])) $link = url($map[$ref['type']]);
        }
        $html = '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:-apple-system,\'PingFang SC\',\'Microsoft YaHei\',sans-serif">'
            . '<div style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">'
            . '<div style="background:' . $color . ';color:#fff;padding:18px 24px;font-size:16px;font-weight:600">'
            . e($site) . ' · ' . e($label) . '</div>'
            . '<div style="padding:24px">'
            . '<p style="margin:0 0 12px;font-size:16px;color:#0f172a;font-weight:600">' . e($title) . '</p>'
            . '<p style="margin:0 0 8px;color:#334155;font-size:14px;line-height:1.8">' . nl2br(e($body)) . '</p>';
        if ($link !== '') {
            $html .= '<p style="margin:16px 0 0"><a href="' . e($link) . '" style="display:inline-block;background:' . $color
                . ';color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-size:14px">前往后台处理</a></p>';
        }
        $html .= '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px">'
            . '本邮件由 ' . e($site) . '（' . CMS_NAME . '）自动发送 · ' . date('Y-m-d H:i')
            . '<br>由「邮件通知」插件发送 · 可在后台插件配置中调整</p>'
            . '</div></div></body></html>';
        return $html;
    }
}
