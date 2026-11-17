<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Captcha;
use app\Core\Csrf;
use app\Core\Database;
use app\Core\Logger;
use app\Core\RateLimiter;
use app\Core\Request;
use app\Core\Session;
use app\Core\Validator;
use app\Core\View;
use app\Services\NotifyService;
use app\Services\SeoService;
use app\Services\StatsService;

class Message
{
    public function create(Request $req, array $p): void
    {
        StatsService::track();
        $meta = SeoService::meta('咨询留言', '留下您的问题，我们将尽快回复；凭查询码可查看回复', '咨询留言,心理问答', url('/message'));
        View::render('message/form', [
            'meta' => $meta,
            'crumbs' => ['首页' => url('/'), '咨询留言' => ''],
        ]);
    }

    public function store(Request $req, array $p): void
    {
        Csrf::guard();
        StatsService::track();
        if (!RateLimiter::hit('message', $req->ip(), 5, 3600)) {
            Session::flash('error', '留言过于频繁，请稍后再试');
            \app\Core\ResponseRedirect('/message');
        }
        $data = $req->posts();
        $v = new Validator($data, [
            'contact' => 'required|maxlen:120|phone_or_qq',
            'content' => 'required|maxlen:2000|safe_text',
            'subject' => 'maxlen:120|safe_text',
            'name' => 'maxlen:40|safe_text',
            'captcha' => 'required',
        ], ['contact' => '联系方式', 'content' => '留言内容', 'captcha' => '验证码']);
        if ($v->fails() || !Captcha::check((string)$data['captcha'])) {
            Session::flash('error', $v->firstError() ?: '验证码不正确');
            \app\Core\ResponseRedirect('/message');
        }

        $code = gen_code(8);
        $id = Database::insert('messages', [
            'code' => $code,
            'name' => mb_substr((string)($data['name'] ?? ''), 0, 39),
            'contact' => (string)$data['contact'],
            'subject' => mb_substr((string)($data['subject'] ?? ''), 0, 119),
            'content' => (string)$data['content'],
            'status' => 'pending',
            'ip' => $req->ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        NotifyService::push('message', '收到新咨询留言', sprintf(
            "主题：%s\n留言：%s\n联系方式：%s\n查询码：%s",
            $data['subject'] ?: '（未填）', str_take((string)$data['content'], 200, '…'), mask_phone($data['contact']), $code
        ), ['type' => 'message', 'id' => $id]);
        Logger::info('message', 'new message', ['id' => $id]);

        Session::set('message_created', ['code' => $code]);
        \app\Core\ResponseRedirect('/message/reply?code=' . urlencode($code));
    }

    /** 凭码查看回复 */
    public function replyView(Request $req, array $p): void
    {
        StatsService::track();
        $result = null;
        $code = strtoupper((string)($req->isPost() ? $req->post('code', '') : $req->get('code', '')));
        $contact = $req->isPost() ? (string)$req->post('contact', '') : '';
        $newCode = Session::get('message_created')['code'] ?? null;
        if ($newCode) { Session::forget('message_created'); }

        if ($code !== '' && $req->isPost()) {
            Csrf::guard();
            if (!RateLimiter::hit('msg_check', $req->ip(), 15, 600)) {
                $result = ['error' => '查询过于频繁，请稍后再试'];
            } else {
                $m = Database::fetch('SELECT * FROM messages WHERE code=?', [$code]);
                if ($m && ($contact === '' || $m['contact'] === $contact)) {
                    $replies = Database::fetchAll(
                        'SELECT r.*, u.display_name FROM message_replies r
                         LEFT JOIN users u ON u.id=r.admin_user_id
                         WHERE r.message_id=? AND r.visible_to_user=1 ORDER BY r.id ASC',
                        [$m['id']]
                    );
                    $result = ['message' => $m, 'replies' => $replies];
                    Database::run('UPDATE messages SET has_unread_reply=0, updated_at=NOW() WHERE id=?', [$m['id']]);
                } elseif ($m) {
                    $result = ['error' => '联系方式与留言不匹配'];
                } else {
                    $result = ['error' => '未找到对应留言，请核对查询码'];
                }
            }
        }
        $meta = SeoService::meta('留言回复查询', '', '', url('/message/reply'));
        View::render('message/reply', [
            'meta' => $meta, 'result' => $result, 'newCode' => $newCode,
            'crumbs' => ['首页' => url('/'), '咨询留言' => url('/message'), '回复查询' => ''],
        ]);
    }
}
