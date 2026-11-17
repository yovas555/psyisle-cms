<?php
/** 预约周报插件（官方示例：展示钩子/每日任务/后台配置三要素） */
declare(strict_types=1);

namespace plugins\bookingweeklyreport;

use app\Core\Database;
use app\Core\Hook;
use app\Core\Logger;

final class Plugin
{
    public static function boot(array $meta): void
    {
        // 钩子演示：每笔新预约计入日志（未来可扩展为实时播报/短信）
        Hook::on('booking.created', function ($bookingId) {
            Logger::info('plugin-bwr', 'booking captured #' . $bookingId);
        });
    }

    /** 每日任务（仅周一发送周报），由 PluginManager 调度 */
    public static function dailyCron(): array
    {
        if (date('N') !== '1') return ['skipped' => 'not monday'];
        $from = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $bookings = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE created_at >= ?", [$from]);
        $messages = (int)Database::value("SELECT COUNT(*) FROM messages WHERE created_at >= ?", [$from]);
        $chats = (int)Database::value("SELECT COUNT(*) FROM chat_sessions WHERE created_at >= ?", [$from]);
        $regs = (int)Database::value("SELECT COUNT(*) FROM event_registrations WHERE created_at >= ?", [$from]);
        $site = (string)setting('site.name', '一二三');
        $email = (string)setting('notify.admin_email', '');
        if ($email === '' || !\app\Core\Mailer::enabled()) {
            return ['skipped' => 'mail not configured'];
        }
        $html = '<div style="font-family:-apple-system,PingFang SC,sans-serif"><h2 style="color:#2a9d8f">上周运营周报</h2>'
            . '<table style="border-collapse:collapse" cellpadding="8">' . '<tr><td>新预约</td><td><b>' . $bookings . '</b></td></tr>'
            . '<tr><td>新留言</td><td><b>' . $messages . '</b></td></tr>'
            . '<tr><td>AI 会话</td><td><b>' . $chats . '</b></td></tr>'
            . '<tr><td>活动报名</td><td><b>' . $regs . '</b></td></tr></table>'
            . '<p style="color:#64748b;font-size:12px">由预约周报插件自动生成 · ' . e($site) . '</p></div>';
        [$ok, $err] = \app\Core\Mailer::send($email, '【' . $site . '】运营周报（' . date('m-d', strtotime('-7 days')) . ' 至 ' . date('m-d') . '）', $html);
        Logger::info('plugin-bwr', 'weekly report sent', ['ok' => $ok]);
        return ['sent' => $ok, 'bookings' => $bookings, 'messages' => $messages];
    }
}
