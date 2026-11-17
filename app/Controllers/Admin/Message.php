<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;

class Message extends Base
{
    protected string $table = 'messages';
    protected string $permNode = 'messages';
    protected string $title = '留言管理';
    protected string $orderBy = 'id DESC';
    protected string $baseSeg = 'messages';
    protected array $searchFields = ['name', 'contact', 'subject', 'code'];

    public function index(Request $req, array $p): void
    {
        $page = max(1, (int)$req->get('page', 1));
        $kw = trim((string)$req->get('q', ''));
        $st = (string)$req->get('status', '');
        $where = '1=1';
        $params = [];
        if ($st !== '') { $where .= ' AND status=?'; $params[] = $st; }
        if ($kw !== '') {
            $where .= ' AND (name LIKE ? OR contact LIKE ? OR subject LIKE ? OR content LIKE ? OR code=?)';
            $params = array_merge($params, ["%$kw%", "%$kw%", "%$kw%", "%$kw%", strtoupper($kw)]);
        }
        $size = 15;
        $offset = ($page - 1) * $size;
        $total = Database::count('messages', $where, $params);
        $rows = Database::fetchAll(
            "SELECT * FROM messages WHERE $where ORDER BY id DESC LIMIT $size OFFSET $offset",
            $params
        );
        $this->view('message/list', [
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)), 'kw' => $kw, 'st' => $st,
            'title' => $this->title,
        ]);
    }

    public function edit(Request $req, array $p): void
    {
        $m = Database::fetch('SELECT * FROM messages WHERE id=?', [(int)$p['id']]);
        if (!$m) { Session::flash('error', '留言不存在'); \app\Core\ResponseRedirect('/admin/messages'); }
        $replies = Database::fetchAll(
            'SELECT r.*, u.display_name FROM message_replies r
             LEFT JOIN users u ON u.id=r.admin_user_id
             WHERE r.message_id=? ORDER BY r.id ASC', [$m['id']]
        );
        Database::run('UPDATE messages SET has_unread_reply=0 WHERE id=? AND has_unread_reply=1', [$m['id']]);
        $this->view('message/detail', ['m' => $m, 'replies' => $replies, 'title' => '留言详情 #' . $m['id']]);
    }

    public function reply(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $m = Database::fetch('SELECT * FROM messages WHERE id=?', [$id]);
        if (!$m) json_out(['ok' => false, 'msg' => '留言不存在']);
        $content = trim((string)$req->post('content', ''));
        if ($content === '') json_out(['ok' => false, 'msg' => '回复内容不能为空']);
        $visible = !empty($_POST['visible_to_user']) ? 1 : 0;
        $u = Session::get('admin_user');
        Database::insert('message_replies', [
            'message_id' => $id,
            'admin_user_id' => $u['id'] ?? null,
            'content' => mb_substr($content, 0, 2999),
            'visible_to_user' => $visible,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // 精选公开问答
        $featured = !empty($_POST['is_featured']) ? 1 : 0;
        $featuredText = trim((string)$req->post('featured_text', ''));
        if ($featured && $featuredText !== '' && strpos($featuredText, '问：') !== false) {
            Database::update('messages', ['is_featured' => 1, 'content' => mb_substr($featuredText, 0, 1999)], 'id=?', [$id]);
        } elseif ($featured) {
            Database::update('messages', ['is_featured' => 1], 'id=?', [$id]);
        } else {
            Database::update('messages', ['is_featured' => 0], 'id=?', [$id]);
        }
        Database::update('messages', [
            'status' => 'replied',
            'has_unread_reply' => $visible,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
        $this->audit('message.reply', 'messages', $id, ['visible' => $visible]);
        json_out(['ok' => true, 'msg' => '已回复']);
    }

    public function close(Request $req, array $p): void
    {
        Csrf::guard();
        Database::update('messages', ['status' => 'closed', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$p['id']]);
        $this->audit('message.close', 'messages', (int)$p['id']);
        Session::flash('success', '留言已关闭');
        \app\Core\ResponseRedirect('/admin/messages');
    }

    public function export(Request $req, array $p): void
    {
        $rows = Database::fetchAll('SELECT * FROM messages ORDER BY id DESC LIMIT 5000');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="messages-' . date('Ymd-Hi') . '.csv"');
        echo "\xEF\xBB\xBF";
        $f = fopen('php://output', 'w');
        fputcsv($f, ['ID', '查询码', '称呼', '联系方式', '主题', '内容', '状态', 'IP', '提交时间']);
        foreach ($rows as $m) {
            fputcsv($f, [$m['id'], $m['code'], $m['name'], $m['contact'], $m['subject'], $m['content'], $m['status'], $m['ip'], $m['created_at']]);
        }
        fclose($f);
        $this->audit('message.export');
        exit;
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        Database::delete('message_replies', 'message_id=?', [$id]);
        Database::delete('messages', 'id=?', [$id]);
        $this->audit('message.delete', 'messages', $id);
        Session::flash('success', '已删除');
        \app\Core\ResponseRedirect('/admin/messages');
    }
}
