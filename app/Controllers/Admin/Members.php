<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Logger;
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
        $id = (int)$p['id'];
        try {
            $result = Database::transaction(function () use ($id): array {
                $member = Database::fetch('SELECT * FROM members WHERE id=? FOR UPDATE', [$id]);
                if (!$member) return [false, '会员不存在'];
                if (Database::value("SELECT id FROM cases WHERE member_id=? AND status='active' LIMIT 1", [$id])) {
                    return [false, '该会员仍有进行中的个案，请先由负责咨询师结案并完成业务留痕'];
                }
                Database::run(
                    "UPDATE booking_feedbacks SET member_id=NULL,
                        status=CASE WHEN status='pending' THEN 'unavailable' ELSE status END,
                        bridge_status=CASE WHEN bridge_status IN ('pending','waiting_case') THEN 'unavailable' ELSE bridge_status END,
                        skip_reason=COALESCE(skip_reason, 'member_deleted'), updated_at=NOW()
                     WHERE member_id=?", [$id]
                );
                Database::run('DELETE FROM member_notifications WHERE member_id=?', [$id]);
                Database::run('DELETE FROM member_notify_log WHERE member_id=?', [$id]);
                Database::run('DELETE FROM assessment_results WHERE member_id=?', [$id]);
                Database::run('DELETE FROM members WHERE id=?', [$id]);
                return [true, ''];
            });
        } catch (\Throwable $e) {
            Logger::error('member-delete', 'confirm delete failed: ' . $e->getMessage(), ['member' => $id]);
            $result = [false, '注销处理失败，请稍后重试'];
        }
        if (empty($result[0])) {
            Session::flash('error', (string)$result[1]);
            \app\Core\ResponseRedirect('/admin/members');
        }
        $this->audit('Members.delete', 'members', $id, ['phone' => MemberService::maskPhone((string)$m['phone'])]);
        Session::flash('success', '会员 ' . MemberService::maskPhone((string)$m['phone']) . ' 已注销，测评与个人通知已删除；预约及咨询后反馈已解除会员直接关联，作为业务留痕保留');
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
