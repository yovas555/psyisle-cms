<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;
use app\Services\LicenseService;
use app\Services\StatsService;

class Dashboard
{
    protected string $permNode = 'dashboard';
    public function index(Request $req, array $p): void
    {
        $todo = [
            'bookings' => Database::count('bookings', "status='pending'"),
            'messages' => Database::count('messages', "status='pending'"),
            'chats' => Database::count('chat_sessions', "escalated=1 AND status<>'closed'"),
            'notifications' => Database::count('notifications', 'is_read=0'),
            'crisis' => Database::count('chat_sessions', "crisis_flag=1 AND status<>'closed'"),
        ];
        $sys = [
            'php' => PHP_VERSION,
            'disk_free_gb' => round(@disk_free_space(ROOT) / 1073741824, 1),
            'queue' => Database::count('email_queue', "status='queued'"),
            'version' => CMS_VERSION,
            'last_logins' => Database::fetchAll('SELECT username, last_login_at, last_login_ip FROM users WHERE last_login_at IS NOT NULL ORDER BY last_login_at DESC LIMIT 3'),
        ];
        // 近 7 日预约来源分布（转化归因）
        $sources = Database::fetchAll(
            "SELECT source, COUNT(*) c FROM bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY source ORDER BY c DESC"
        );
        $recentBookings = Database::fetchAll('SELECT b.*, s.name svc FROM bookings b LEFT JOIN services s ON s.id=b.service_id ORDER BY b.id DESC LIMIT 5');
        $recentMessages = Database::fetchAll('SELECT id, name, subject, contact, status, created_at FROM messages ORDER BY id DESC LIMIT 5');
        $unanswered = Database::fetchAll(
            "SELECT cs.id, cs.token, cm.content, cm.created_at FROM chat_sessions cs
             JOIN chat_messages cm ON cm.session_id=cs.id AND cm.role='user'
             WHERE cs.escalated=1 AND cs.status<>'closed'
             GROUP BY cs.id ORDER BY MAX(cm.id) DESC LIMIT 5"
        );
        $stats = StatsService::recent(7);
        $lic = LicenseService::status();
        (new Base(false))->view('dashboard', [
            'todo' => $todo,
            'sources' => $sources,
            'recentBookings' => $recentBookings,
            'recentMessages' => $recentMessages,
            'unanswered' => $unanswered,
            'stats' => $stats,
            'license' => $lic,
            'sys' => $sys,
            'title' => '仪表盘',
        ]);
    }
}
