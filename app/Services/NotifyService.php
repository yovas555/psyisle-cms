<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * 重要事件推送：站内通知 + 邮件直达管理员邮箱
 * 规则：重要事件（新预约/新留言/AI 升级/登录告警/系统事件）即时同步发送，失败入队重试。
 * 通知永远只发给管理员，绝不发给咨客。
 */
class NotifyService
{
    /** 已注册通知渠道（v2.2.0 渠道注册制；邮件通知插件走钩子模式，不受影响） */
    /** @var array<string, NotifyChannel> */
    private static array $channels = [];

    /** 渠道注册（插件 Plugin::boot() 时调用；同名覆盖） */
    public static function registerChannel(NotifyChannel $channel): void
    {
        self::$channels[$channel->name()] = $channel;
    }

    /** @return array<string, NotifyChannel> 已注册渠道（后台渠道状态展示用） */
    public static function channels(): array
    {
        return self::$channels;
    }

    /**
     * 推送一个事件（通知中心 + 邮件）
     */
    /**
     * 推送事件：写站内通知中心 + 触发 notify.push 钩子（邮件由「邮件通知」插件处理）
     * 类型开关、多收件人、SMTP、队列重试全部在插件内，核心不依赖邮件能力。
     */
    public static function push(string $type, string $title, string $body = '', array $ref = [], bool $urgent = true): void
    {
        try {
            Database::insert('notifications', [
                'type' => $type, 'title' => $title, 'body' => str_take($body, 480, '…'),
                'ref_type' => $ref['type'] ?? null, 'ref_id' => (string)($ref['id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('notify', 'insert notification failed: ' . $t->getMessage());
        }
        // 邮件通知插件监听此钩子（v2.1 兼容模式）；未启用则仅站内通知
        \app\Core\Hook::fire('notify.push', [
            'type' => $type, 'title' => $title, 'body' => $body, 'ref' => $ref, 'urgent' => $urgent,
        ]);
        // v2.2.0 渠道注册制：已注册渠道逐一分发（渠道自行处理开关/免打扰/降级/日志）
        foreach (self::$channels as $channel) {
            if (!$channel->enabled()) continue;
            try {
                $channel->send($type, $title, $body, $ref, $urgent);
            } catch (\Throwable $t) {
                \app\Core\Logger::error('notify', 'channel ' . $channel->name() . ' send failed: ' . $t->getMessage());
            }
        }
    }

    /** 兼容：旧代码可能调用 flushQueue，转发到插件分钟任务 */
    public static function flushQueue(int $limit = 30): array
    {
        $out = \app\Core\PluginManager::runCron('minutely');
        return ['jobs' => $out];
    }
}
