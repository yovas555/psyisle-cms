<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\MemberService;

/**
 * 会员管理（v2.3.0 自助中心配套）
 * 列表 / 注销申请处理（确认删除 / 恢复账号）
 */
class Members extends Base
{
    protected string $permNode = 'member';
    protected string $title = '会员管理';
    protected string $baseSeg = 'members';

    public function index(Request $req, array $p): void
    {
        $filter = (string)$req->get('status', '');
        $sql = 'SELECT m.*, 
                (SELECT COUNT(*) FROM bookings b WHERE b.phone=m.phone) AS booking_count,
                (SELECT COUNT(*) FROM assessment_results r WHERE r.member_id=m.id) AS assess_count
                FROM members m';
        $where = ''; $params = [];
        if (in_array($filter, ['active', 'delete_requested'], true)) { $where = ' WHERE m.status=?'; $params = [$filter]; }
        $rows = Database::fetchAll($sql . $where . ' ORDER BY m.id DESC LIMIT 200', $params);
        $pendingDelete = (int)Database::value("SELECT COUNT(*) FROM members WHERE status='delete_requested'");
        $total = (int)Database::value("SELECT COUNT(*) FROM members WHERE status='active'");
        $this->view('members/index', [
            'title' => $this->title, 'rows' => $rows, 'filter' => $filter,
            'pendingDelete' => $pendingDelete, 'total' => $total,
        ]);
    }

    /** 确认注销：删除测评记录 + 移除会员（预约记录为业务留痕，仅解除不了关联——保留） */
    public function confirmDelete(Request $req, array $p): void
    {
        Csrf::guard();
        $m = Database::fetch('SELECT * FROM members WHERE id=?', [(int)$p['id']]);
        if (!$m) { Session::flash('error', '会员不存在'); \app\Core\ResponseRedirect('/admin/members'); }
        Database::run('DELETE FROM assessment_results WHERE member_id=?', [(int)$m['id']]);
        Database::run('DELETE FROM members WHERE id=?', [(int)$m['id']]);
        $this->audit('Members.delete', 'members', (int)$m['id'], ['phone' => MemberService::maskPhone((string)$m['phone'])]);
        Session::flash('success', '会员 ' . MemberService::maskPhone((string)$m['phone']) . ' 已注销，测评记录已删除（预约记录作为业务留痕保留）');
        \app\Core\ResponseRedirect('/admin/members');
    }

    /** 恢复（撤回注销申请） */
    public function restore(Request $req, array $p): void
    {
        Csrf::guard();
        $m = Database::fetch('SELECT * FROM members WHERE id=?', [(int)$p['id']]);
        if (!$m) { Session::flash('error', '会员不存在'); \app\Core\ResponseRedirect('/admin/members'); }
        Database::run("UPDATE members SET status='active' WHERE id=?", [(int)$m['id']]);
        $this->audit('Members.restore', 'members', (int)$m['id'], ['phone' => MemberService::maskPhone((string)$m['phone'])]);
        Session::flash('success', '会员已恢复');
        \app\Core\ResponseRedirect('/admin/members');
    }
}
