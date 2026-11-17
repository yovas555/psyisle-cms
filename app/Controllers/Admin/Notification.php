<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;

class Notification extends Base
{
    protected string $title = '通知中心';
    protected string $baseSeg = 'notifications';

    public function index(Request $req, array $p): void
    {
        $page = max(1, (int)$req->get('page', 1));
        $size = 20;
        $total = Database::count('notifications');
        $rows = Database::fetchAll(
            "SELECT * FROM notifications ORDER BY id DESC LIMIT $size OFFSET " . (($page - 1) * $size)
        );
        $this->view('notification/index', [
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)),
            'title' => $this->title,
        ]);
    }

    /** 删除单条通知 */
    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        Database::delete('notifications', 'id=?', [(int)$p['id']]);
        json_out(['ok' => true]);
    }

    /** 清空全部通知 */
    public function clearAll(Request $req, array $p): void
    {
        Csrf::guard();
        Database::run('DELETE FROM notifications');
        json_out(['ok' => true]);
    }

    public function markRead(Request $req, array $p): void
    {
        Csrf::guard();
        if (!empty($_POST['id'])) {
            Database::run('UPDATE notifications SET is_read=1 WHERE id=?', [(int)$_POST['id']]);
        } else {
            Database::run('UPDATE notifications SET is_read=1 WHERE is_read=0');
        }
        json_out(['ok' => true]);
    }
}
