<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;

/** 全局客户搜索：来电时输入手机号/查询码/姓名，一次定位其预约与留言 */
class ClientSearch extends Base
{
    protected string $permNode = 'bookings'; // 助理与管理员可用（RBAC 校验 bookings 模块权限）

    public function index(Request $req, array $p): void
    {
        $q = trim((string)$req->get('q', ''));
        $bookings = [];
        $messages = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $bookings = Database::fetchAll(
                "SELECT b.*, s.name svc, c.name cname FROM bookings b
                 LEFT JOIN services s ON s.id=b.service_id
                 LEFT JOIN counselors c ON c.id=b.counselor_id
                 WHERE b.name LIKE ? OR b.phone LIKE ? OR b.code = ? OR b.wechat LIKE ?
                 ORDER BY b.id DESC LIMIT 20",
                [$like, $like, strtoupper($q), $like]
            );
            $messages = Database::fetchAll(
                "SELECT * FROM messages WHERE name LIKE ? OR contact LIKE ? OR code = ? OR subject LIKE ?
                 ORDER BY id DESC LIMIT 20",
                [$like, $like, strtoupper($q), $like]
            );
        }
        $this->view('client-search', [
            'q' => $q, 'bookings' => $bookings, 'messages' => $messages,
            'title' => '客户查找',
        ]);
    }
}
