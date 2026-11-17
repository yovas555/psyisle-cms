<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\Request;
use app\Services\MemberService;
use app\Services\StatsService;

/** 会员 · 咨询记录（v2.10.0：咨询师发布的小结与作业） */
class MemberNotes
{
    public function index(Request $req, array $p): void
    {
        StatsService::track();
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $rows = Database::fetchAll(
            'SELECT n.*, c.name AS counselor_name, b.code AS booking_code, b.prefer_date, b.prefer_slot, b.method
             FROM counselor_notes n
             LEFT JOIN counselors c ON c.id=n.counselor_id
             LEFT JOIN bookings b ON b.id=n.booking_id
             WHERE n.member_id=? AND n.visible=1 ORDER BY n.id DESC LIMIT 50', [(int)$m['id']]);
        $content = \app\Core\View::str(APP . '/views/frontend/member/notes.php', [
            'm' => $m, 'rows' => $rows, 'flashes' => \app\Core\Session::takeFlashes(),
        ]);
        \app\Core\View::render('plugin-page', [
            'title' => '咨询记录',
            'meta' => ['title' => '咨询记录', 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => ['type' => 'website']],
            'noindex' => true, 'content' => $content,
        ]);
    }

    public function read(Request $req, array $p): void
    {
        $m = MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $n = Database::fetch(
            'SELECT n.*, c.name AS counselor_name, b.code AS booking_code, b.prefer_date, b.prefer_slot, b.method
             FROM counselor_notes n
             LEFT JOIN counselors c ON c.id=n.counselor_id
             LEFT JOIN bookings b ON b.id=n.booking_id
             WHERE n.id=? AND n.member_id=? AND n.visible=1', [(int)$p['id'], (int)$m['id']]);
        $content = \app\Core\View::str(APP . '/views/frontend/member/note-read.php', [
            'm' => $m, 'n' => $n, 'flashes' => \app\Core\Session::takeFlashes(),
        ]);
        \app\Core\View::render('plugin-page', [
            'title' => '咨询记录阅读',
            'meta' => ['title' => '咨询记录阅读', 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => ['type' => 'website']],
            'noindex' => true, 'content' => $content,
        ]);
    }
}
