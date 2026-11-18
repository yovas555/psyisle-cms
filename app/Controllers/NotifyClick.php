<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Request;

/**
 * 通知查阅回执中转（v2.17.0）
 * ------------------------------------------------------------------
 * 微信模板消息 / 邮件 CTA 的链接统一指向 /n/{token}：点击即写查阅回执
 * （notify_escalations.read_at），再 302 到站内目标页。
 * 会员通知里的链接被点击 = 用户已查阅，短信兜底随之取消。
 */
class NotifyClick
{
    public function go(Request $req, array $p): void
    {
        $token = (string)($p['token'] ?? '');
        $target = \app\Services\MemberNotifyService::markEscalationClick($token);
        \app\Core\ResponseRedirect($target ?: '/member');
    }
}
