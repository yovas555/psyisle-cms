<?php
declare(strict_types=1);

namespace app\Services;

/**
 * 通知渠道接口（v2.2.0 通知渠道抽象）
 *
 * 核心 NotifyService 只负责：站内通知中心 + notify.push 钩子（向后兼容）+ 遍历已注册渠道分发。
 * 渠道实现方（插件）自行处理：启用开关、类型过滤、免打扰（Core\QuietHours）、失败降级、日志与统计。
 *
 * 约定：
 *  - send() 必须吞掉一切异常（通知失败不影响业务主流程），返回 false 并在渠道内记录原因
 *  - 危机预警（type=crisis）与登录告警（login_alert）默认豁免免打扰（渠道应使用 QuietHours::resolve 判断）
 *  - 通知永远只发给管理员/值班人员，绝不发给咨客
 */
interface NotifyChannel
{
    /** 渠道标识（如 wechat / sms） */
    public function name(): string;

    /** 渠道显示名（后台渠道状态卡用，如 微信服务号） */
    public function label(): string;

    /** 是否已启用（配置齐全且开关打开）；未启用的渠道核心不调用 send */
    public function enabled(): bool;

    /**
     * 发送一条通知
     * @param string $type 事件类型（booking/message/chat_escalate/crisis/login_alert/system/upgrade/license/event）
     * @param array $ref 关联实体 ['type'=>'booking','id'=>3]
     * @return bool 是否成功（false = 渠道内部已记录失败/降级）
     */
    public function send(string $type, string $title, string $body, array $ref, bool $urgent): bool;

    /** 今日到达率统计（后台展示）：['ok'=>int,'fail'=>int,'queued'=>int] */
    public function stats(): array;
}
