<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\AiService;

class Chat extends Base
{
    protected string $table = 'chat_sessions';
    protected string $permNode = 'chat';
    protected string $title = 'AI 客服';
    protected string $baseSeg = 'chat';

    public function index(Request $req, array $p): void
    {
        // 会话列表 + AI 配置 + 知识库
        $sessions = Database::fetchAll(
            'SELECT cs.*, (SELECT content FROM chat_messages cm WHERE cm.session_id=cs.id ORDER BY cm.id DESC LIMIT 1) last_msg
             FROM chat_sessions cs ORDER BY cs.last_active_at DESC LIMIT 50'
        );
        $kb = Database::fetchAll('SELECT * FROM ai_kb ORDER BY id DESC LIMIT 100');
        $cfg = [
            'enabled' => setting('ai.enabled'),
            'base_url' => setting('ai.base_url'),
            'api_key' => setting('ai.api_key'),
            'model' => setting('ai.model'),
        ];
        $this->view('chat/index', [
            'sessions' => $sessions, 'kb' => $kb, 'cfg' => $cfg,
            'title' => $this->title,
        ]);
    }

    public function edit(Request $req, array $p): void
    {
        $s = Database::fetch('SELECT * FROM chat_sessions WHERE id=?', [(int)$p['id']]);
        if (!$s) { Session::flash('error', '会话不存在'); \app\Core\ResponseRedirect('/admin/chat'); }
        $msgs = Database::fetchAll('SELECT * FROM chat_messages WHERE session_id=? ORDER BY id ASC', [$s['id']]);
        $this->view('chat/detail', ['s' => $s, 'msgs' => $msgs, 'title' => '会话 #' . $s['id']]);
    }

    /** 人工回复（开口即接管：此后 AI 对该会话静默） */
    public function reply(Request $req, array $p): void
    {
        Csrf::guard();
        $sid = (int)$p['id'];
        $content = trim((string)$req->post('content', ''));
        if ($content === '') json_out(['ok' => false, 'msg' => '内容为空']);
        // 人工接入规则：只要人工回复过，会话即进入接管状态，AI 保持静默
        Database::run("UPDATE chat_sessions SET escalated=1, status='escalated' WHERE id=? AND escalated=0", [$sid]);
        $content = '【人工客服】' . $content;
        AiService::save($sid, 'assistant', $content, 'human', 0);
        $mid = (int)Database::value('SELECT MAX(id) FROM chat_messages WHERE session_id=?', [$sid]);
        $u = Session::get('admin_user');
        $this->audit('chat.reply', 'chat_sessions', $sid, ['by' => $u['username'] ?? '']);
        json_out([
            'ok' => true,
            'msg' => '已发送（人工接管中，AI 已静默）',
            'msg_id' => $mid,
            'content' => \app\Services\AiService::markdownLite($content),
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 交还 AI：解除接管，恢复自动应答 */
    public function handback(Request $req, array $p): void
    {
        Csrf::guard();
        $sid = (int)$p['id'];
        Database::run("UPDATE chat_sessions SET escalated=0, status='active' WHERE id=?", [$sid]);
        $this->audit('chat.handback', 'chat_sessions', $sid);
        Session::flash('success', '已交还 AI（恢复自动应答）');
        \app\Core\ResponseRedirect('/admin/chat/' . $sid . '/edit');
    }

    public function close(Request $req, array $p): void
    {
        Csrf::guard();
        Database::run("UPDATE chat_sessions SET status='closed' WHERE id=?", [(int)$p['id']]);
        $this->audit('chat.close', 'chat_sessions', (int)$p['id']);
        \app\Core\ResponseRedirect('/admin/chat');
    }

    /** 删除会话（含全部消息） */
    public function destroy(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        Database::delete('chat_messages', 'session_id=?', [$id]);
        Database::delete('chat_sessions', 'id=?', [$id]);
        $this->audit('chat.destroy', 'chat_sessions', $id);
        Session::flash('success', '会话及消息已删除');
        \app\Core\ResponseRedirect('/admin/chat');
    }

    /** 已关闭会话保留 30 天后删除（cron 每日自动 + 手动按钮共用） */
    public static function autoCleanup(): int
    {
        $ids = Database::fetchAll(
            "SELECT id FROM chat_sessions WHERE status='closed' AND last_active_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 500"
        );
        if (!$ids) return 0;
        $idList = implode(',', array_map(fn($r) => (int)$r['id'], $ids));
        Database::run("DELETE FROM chat_messages WHERE session_id IN ($idList)");
        $n = Database::run("DELETE FROM chat_sessions WHERE id IN ($idList)")->rowCount();
        return $n;
    }

    /** 手动清理（同自动语义） */
    public function cleanup(Request $req, array $p): void
    {
        Csrf::guard();
        $n = self::autoCleanup();
        $this->audit('chat.cleanup', null, null, ['deleted' => $n]);
        Session::flash('success', $n ? "已清理 {$n} 个过期会话（已关闭满 30 天）及其消息" : '没有满 30 天的已关闭会话需要清理');
        \app\Core\ResponseRedirect('/admin/chat');
    }

    /** 批量删除会话（复选框多选） */
    public function batchDelete(Request $req, array $p): void
    {
        Csrf::guard();
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_filter($ids, fn($v) => $v > 0);
        if (!$ids) { Session::flash('error', '未选择任何会话'); \app\Core\ResponseRedirect('/admin/chat'); }
        $idList = implode(',', $ids);
        Database::run("DELETE FROM chat_messages WHERE session_id IN ($idList)");
        $n = Database::run("DELETE FROM chat_sessions WHERE id IN ($idList)")->rowCount();
        $this->audit('chat.batch_delete', null, null, ['count' => $n, 'ids' => $idList]);
        Session::flash('success', "已删除 {$n} 个会话");
        \app\Core\ResponseRedirect('/admin/chat');
    }

    public function testApi(Request $req, array $p): void
    {
        Csrf::guard();
        $res = \app\Core\PluginManager::call('ai-concierge', 'testApi');
        if (!is_array($res)) $res = [false, '「AI 智能客服」插件未启用'];
        json_out(['ok' => (bool)$res[0], 'msg' => (string)$res[1]]);
    }

    /** 知识库增删 */
    public function kbToggle(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $kb = Database::fetch('SELECT enabled FROM ai_kb WHERE id=?', [$id]);
        if ($kb) {
            Database::update('ai_kb', ['enabled' => (int)!$kb['enabled']], 'id=?', [$id]);
        }
        \app\Core\ResponseRedirect('/admin/chat');
    }

    public function update(Request $req, array $p): void
    {
        // 知识库编辑（通过 update/id 路由复用）
        Csrf::guard();
        $id = (int)$p['id'];
        $q = trim((string)$req->post('kb_question', ''));
        $a = trim((string)$req->post('kb_answer', ''));
        if ($q !== '' && $a !== '') {
            Database::update('ai_kb', [
                'question' => $q, 'answer' => $a,
                'keywords' => trim((string)$req->post('kb_keywords', '')),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=?', [$id]);
            $this->audit('ai.kb.update', 'ai_kb', $id);
        }
        Session::flash('success', '知识条目已保存');
        \app\Core\ResponseRedirect('/admin/chat');
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        Database::delete('ai_kb', 'id=?', [(int)$p['id']]);
        $this->audit('ai.kb.delete', 'ai_kb', (int)$p['id']);
        Session::flash('success', '已删除');
        \app\Core\ResponseRedirect('/admin/chat');
    }
}
