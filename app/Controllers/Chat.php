<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Logger;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Services\AiService;
use app\Services\LicenseService;
use app\Services\NotifyService;

/** AI 客服接口（JSON） */
class Chat
{
    /** 开启/恢复会话 */
    public function start(Request $req, array $p): void
    {
        $token = (string)($req->get('token') ?: ($_COOKIE['psy_chat'] ?? ''));
        if ($token && preg_match('/^[a-f0-9]{32}$/', $token)) {
            $s = Database::fetch('SELECT * FROM chat_sessions WHERE token=?', [$token]);
            if ($s && $s['status'] !== 'closed') {
                $msgs = Database::fetchAll(
                    'SELECT id, role, content FROM chat_messages WHERE session_id=? ORDER BY id ASC LIMIT 50',
                    [$s['id']]
                );
                json_out(['ok' => true, 'token' => $token, 'escalated' => (bool)$s['escalated'], 'messages' => array_map(fn($m) => [
                    'id' => (int)$m['id'], 'role' => $m['role'], 'content' => AiService::markdownLite($m['content']),
                ], $msgs)]);
            }
        }
        $token = bin2hex(random_bytes(16));
        $id = Database::insert('chat_sessions', [
            'token' => $token, 'status' => 'active', 'visitor_ip' => $req->ip(),
            'created_at' => date('Y-m-d H:i:s'), 'last_active_at' => date('Y-m-d H:i:s'),
        ]);
        $secureFlag = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        setcookie('psy_chat', $token, ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => $secureFlag]);
        $greeting = '您好，我是' . setting('site.name', '本机构') . '的智能助手小壹 🌿 有关于心理咨询的问题，可以随时问我。需要预约或转人工，随时告诉我。';
        AiService::save($id, 'assistant', $greeting, 'greeting', 0);
        $mid = (int)Database::value('SELECT MAX(id) FROM chat_messages WHERE session_id=?', [$id]);
        json_out(['ok' => true, 'token' => $token, 'escalated' => false, 'messages' => [['id' => $mid, 'role' => 'assistant', 'content' => $greeting]]]);
    }

    public function send(Request $req, array $p): void
    {
        Csrf::guard();
        $token = (string)$req->post('token', '');
        $msg = trim((string)$req->post('msg', ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || $msg === '') {
            json_out(['ok' => false, 'msg' => '参数错误']);
        }
        if (mb_strlen($msg) > 1000) { $msg = mb_substr($msg, 0, 1000); }
        // 限流：每 IP 每分钟 12 条
        if (!RateLimiter::hit('chat', $req->ip(), 12, 60)) {
            json_out(['ok' => false, 'msg' => '发送太频繁了，稍等片刻再聊']);
        }
        $s = Database::fetch("SELECT * FROM chat_sessions WHERE token=? AND status<>'closed'", [$token]);
        if (!$s) { json_out(['ok' => false, 'msg' => '会话已失效，请刷新页面']); }
        if (!LicenseService::feature('ai_chat')) {
            json_out(['ok' => false, 'msg' => '智能客服未授权，请通过留言联系我们']);
        }

        AiService::save($s['id'], 'user', $msg);
        // 人工接管中：AI 静默，消息留待人工回复（前台轮询可见）
        if ($s['escalated']) {
            $mid = (int)Database::value('SELECT MAX(id) FROM chat_messages WHERE session_id=?', [$s['id']]);
            json_out([
                'ok' => true,
                'reply' => '（已转人工，顾问正在查看你的消息，请稍候……）',
                'type' => 'human-waiting',
                'escalated' => true,
                'msg_id' => $mid, // 前端同步轮询基线，防止轮询重复展示
            ]);
        }
        // 访客提问邮件通知（在 AI 应答前发出，值班邮箱即时可见；同会话 5 分钟节流）
        if ((bool)setting('notify.on_chat_question', '1') && RateLimiter::hit('chatq_' . $s['id'], 'sess', 1, 300)) {
            NotifyService::push('chat_question', '访客提问：' . str_take($msg, 60, '…'),
                '会话 #' . $s['id'] . "\n提问：" . str_take($msg, 200, '…') . "\n（AI 正在自动应答；如需人工跟进请打开会话回复）",
                ['type' => 'chat_escalate', 'id' => $s['id']], false);
        }
        // 【核心安全底线】危机干预兜底：先于任何插件判断，插件启停均不影响
        if (AiService::isCrisis($msg)) {
            Database::run('UPDATE chat_sessions SET crisis_flag=1 WHERE id=?', [$s['id']]);
            AiService::save($s['id'], 'assistant', AiService::CRISIS_REPLY, 'crisis-guard', 0);
            NotifyService::push('crisis', '访客消息触发危机预警',
                '检测到疑似自伤/危机关键词，已返回热线兜底话术。请尽快人工跟进。',
                ['type' => 'chat_escalate', 'id' => $s['id']]);
            $midC = (int)Database::value('SELECT MAX(id) FROM chat_messages WHERE session_id=?', [$s['id']]);
            json_out(['ok' => true, 'reply' => AiService::markdownLite(AiService::CRISIS_REPLY), 'type' => 'crisis', 'msg_id' => $midC]);
        }
        // AI 应答交给「AI 智能客服」插件（未启用/失败则有兜底文案）
        $ctx = \app\Core\Hook::fire('ai.reply', ['session_id' => $s['id'], 'msg' => $msg]);
        $replyHtml = is_array($ctx) ? (string)($ctx['reply'] ?? '') : '';
        $replyType = is_array($ctx) ? (string)($ctx['type'] ?? '') : '';
        if ($replyHtml === '') {
            $replyHtml = '抱歉，智能客服暂时不可用。您可以<a href="' . url('/message') . '">给我们留言</a>，或拨打 '
                . e(setting('contact.phone', '我们的电话')) . '，我们会尽快回复您。';
            $replyType = 'fallback';
            AiService::save($s['id'], 'assistant', strip_tags($replyHtml), 'fallback', 0);
        }
        $mid = (int)Database::value('SELECT MAX(id) FROM chat_messages WHERE session_id=?', [$s['id']]);
        json_out(['ok' => true, 'reply' => $replyHtml, 'type' => $replyType, 'msg_id' => $mid]);
    }

    /** 轮询新消息（含人工回复） */
    public function poll(Request $req, array $p): void
    {
        $token = (string)$req->get('token', '');
        $after = (int)$req->get('after', 0);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) { json_out(['ok' => false]); }
        $s = Database::fetch('SELECT id, status, escalated FROM chat_sessions WHERE token=?', [$token]);
        if (!$s) { json_out(['ok' => false]); }
        $msgs = Database::fetchAll(
            "SELECT id, role, content FROM chat_messages
             WHERE session_id=? AND id>? AND role IN ('assistant','system')
             ORDER BY id ASC LIMIT 20",
            [$s['id'], $after]
        );
        json_out([
            'ok' => true,
            'escalated' => (bool)$s['escalated'],
            'status' => $s['status'],
            'messages' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'role' => $m['role'],
                'content' => AiService::markdownLite($m['content']),
                'human' => strpos((string)$m['content'], '【人工客服】') === 0,
            ], $msgs),
        ]);
    }

    /** 转人工：标记会话 + 通知 */
    public function escalate(Request $req, array $p): void
    {
        Csrf::guard();
        $token = (string)$req->post('token', '');
        $s = Database::fetch('SELECT * FROM chat_sessions WHERE token=?', [$token]);
        if (!$s) json_out(['ok' => false]);
        if (!$s['escalated']) {
            Database::run("UPDATE chat_sessions SET escalated=1, status='escalated' WHERE id=?", [$s['id']]);
            $last = Database::fetch(
                "SELECT content FROM chat_messages WHERE session_id=? AND role='user' ORDER BY id DESC LIMIT 1", [$s['id']]
            );
            NotifyService::push('chat_escalate', '访客请求人工客服',
                '会话 ' . substr($token, 0, 8) . '… 请求人工。最近消息：' . str_take((string)($last['content'] ?? ''), 150, '…'),
                ['type' => 'chat_escalate', 'id' => $s['id']]);
        }
        json_out(['ok' => true, 'msg' => '已通知工作人员。您也可以留下联系方式（页面右侧「咨询留言」），我们会主动联系您。']);
    }

    /** 留联系方式 */
    public function contact(Request $req, array $p): void
    {
        Csrf::guard();
        $token = (string)$req->post('token', '');
        $contact = trim((string)$req->post('contact', ''));
        if (!preg_match('/^[a-zA-Z0-9_@.\-]{5,60}$/', $contact)) {
            json_out(['ok' => false, 'msg' => '请填写有效的电话/微信/邮箱']);
        }
        $s = Database::fetch('SELECT * FROM chat_sessions WHERE token=?', [$token]);
        if (!$s) json_out(['ok' => false, 'msg' => '会话已失效']);
        Database::run('UPDATE chat_sessions SET contact=?, escalated=1 WHERE id=?', [$contact, $s['id']]);
        NotifyService::push('chat_escalate', 'AI 客服访客留下联系方式',
            '联系方式：' . $contact . '（请在后台会话 #' . $s['id'] . ' 查看上下文）',
            ['type' => 'chat_escalate', 'id' => $s['id']]);
        json_out(['ok' => true, 'msg' => '已收到，我们会尽快与您联系 🌿']);
    }
}
