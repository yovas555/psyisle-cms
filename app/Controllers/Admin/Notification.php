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
        // v2.20.1 未查阅兜底区块：30 分钟未查阅且未补发短信的台账（管理员手动点击补发）
        try {
            $escalations = Database::fetchAll(
                "SELECT e.id, e.event, e.ref_id, e.click_token, e.sms_at, e.admin_notified_at, e.created_at,
                        m.name AS m_name, m.phone AS m_phone
                 FROM notify_escalations e JOIN members m ON m.id=e.member_id AND m.status='active'
                 WHERE e.read_at IS NULL AND e.sms_at IS NULL AND e.admin_notified_at IS NOT NULL
                 ORDER BY e.admin_notified_at DESC LIMIT 20");
        } catch (\Throwable $t) { $escalations = []; }
        $this->view('notification/index', [
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)),
            'escalations' => $escalations,
            'title' => $this->title,
        ]);
    }

    /** v2.20.1 手动补发兜底短信（含查阅短链，点击即回执） */
    public function resendSms(Request $req, array $p): void
    {
        Csrf::guard();
        [$ok, $msg] = \app\Services\MemberNotifyService::resendEscalationSms((int)$p['id']);
        json_out(['ok' => $ok, 'message' => $msg]);
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
