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

class Booking
{
    public function create(Request $req, array $p): void
    {
        StatsService::track();
        // v2.14.0 提案：预约可选要求登录会员（booking.require_member 默认 0 关闭，等用户拍板）
        $mCur = \app\Services\MemberService::current();
        if (!$mCur && (bool)setting('booking.require_member', '0')) {
            \app\Core\Session::set('member_return', '/booking');
            \app\Core\Session::flash('error', '预约请先登录会员账号（预约与提醒、支付均在会员中心管理）');
            \app\Core\ResponseRedirect('/member/login');
        }
        $services = Database::fetchAll('SELECT id, name, price_from, price_to FROM services WHERE status=1 ORDER BY sort');
        $counselors = Database::fetchAll('SELECT id, name, title FROM counselors WHERE status=1 ORDER BY sort');
        $preService = (int)$req->get('service', 0);
        $preCounselor = (int)$req->get('counselor', 0);
        // v2.8.0 插件扩展点：门店选择（stores-pro）/ EAP 上下文（eap-pro）注入表单
        $extras = \app\Core\Hook::fire('booking.form.extras', [
            'req' => $req, 'top_html' => '', 'html' => '',
        ]);
        $meta = SeoService::meta('预约咨询', '在线预约心理咨询：填写表单后我们会尽快与您确认时间', '预约心理咨询,心理预约', url('/booking'));
        View::render('booking/form', [
            'meta' => $meta,
            'services' => $services,
            'counselors' => $counselors,
            'preService' => $preService,
            'preCounselor' => $preCounselor,
            'source' => (string)$req->get('source', 'web'),
            'formTopExtras' => (string)($extras['top_html'] ?? ''),
            'formExtras' => (string)($extras['html'] ?? ''),
            'crumbs' => ['首页' => url('/'), '预约咨询' => ''],
        ]);
    }

    /** v2.17.0：槽位预约确认+支付页（从咨询师日历点击时段进入） */
    public function confirm(Request $req, array $p): void
    {
        StatsService::track();
        $cid = (int)$req->get('counselor', 0);
        $date = (string)$req->get('date', '');
        $start = (string)$req->get('start', '');
        if ($cid <= 0 || $date === '' || $start === '') {
            Session::flash('error', '请先选择咨询时段');
            \app\Core\ResponseRedirect('/booking');
        }
        $c = Database::fetch('SELECT id, name, title, slug, avatar, introduction, fee_from, fee_to FROM counselors WHERE id=? AND status=1', [$cid]);
        if (!$c) {
            Session::flash('error', '咨询师不存在或已停用');
            \app\Core\ResponseRedirect('/booking');
        }
        [$ok, $msgOrLabel] = \app\Services\BookingSlots::checkAvailable($cid, $date, $start);
        if (!$ok) {
            Session::flash('error', $msgOrLabel);
            \app\Core\ResponseRedirect('/counselors/' . (string)$c['slug']);
        }
        $slotLabel = (string)$msgOrLabel;
        $services = Database::fetchAll('SELECT id, name, price_from, price_to FROM services WHERE status=1 ORDER BY sort');
        $mCur = \app\Services\MemberService::current();
        if (!$mCur && (bool)setting('booking.require_member', '0')) {
            \app\Core\Session::set('member_return', '/booking/confirm?counselor=' . $cid . '&date=' . urlencode($date) . '&start=' . urlencode($start));
            \app\Core\Session::flash('error', '预约请先登录会员账号（预约与提醒、支付均在会员中心管理）');
            \app\Core\ResponseRedirect('/member/login');
        }
        // 价格预览（视频=在线支付；fee_override 分时价优先）
        $feeCents = \app\Services\BookingSlots::slotPriceCents($cid, $date, $slotLabel);
        $extras = \app\Core\Hook::fire('booking.form.extras', [
            'req' => $req, 'top_html' => '', 'html' => '',
        ]);
        $meta = SeoService::meta('预约确认', '确认咨询时段并提交预约', '预约确认,心理咨询', url('/booking'));
        View::render('booking/confirm', [
            'meta' => $meta,
            'c' => $c, 'date' => $date, 'slotLabel' => $slotLabel,
            'slotStart' => $start,
            'feeCents' => $feeCents,
            'services' => $services,
            'member' => $mCur,
            'week' => '周' . mb_substr('一二三四五六日', (int)date('N', strtotime($date)) - 1, 1),
            'formTopExtras' => (string)($extras['top_html'] ?? ''),
            'formExtras' => (string)($extras['html'] ?? ''),
            'crumbs' => ['首页' => url('/'), '咨询师' => url('/counselors'), $c['name'] => url('/counselors/' . $c['slug']), '预约确认' => ''],
        ]);
    }

    public function store(Request $req, array $p): void
    {
        Csrf::guard();
        StatsService::track();
        // 限流：同 IP 1 小时 5 次
        if (!RateLimiter::hit('booking', $req->ip(), 5, 3600)) {
            Session::flash('error', '操作过于频繁，请稍后再试');
            \app\Core\ResponseRedirect('/booking');
        }
        $data = $req->posts();
        // v2.14.0：会员预约手机号锁定为登录会员本人
        $mCur2 = \app\Services\MemberService::current();
        if ($mCur2) { $data['phone'] = (string)$mCur2['phone']; $data['name'] = (string)($mCur2['name'] ?: $data['name']); }
        $v = new Validator($data, [
            'name' => 'required|maxlen:40|safe_text',
            'phone' => 'required|phone',
            'method' => 'in:面对面,视频,电话',
            'prefer_date' => 'date',
            'issue' => 'maxlen:1000|safe_text',
            'email' => 'email|maxlen:120',
            'captcha' => 'required',
        ], ['name' => '姓名', 'phone' => '手机号', 'captcha' => '验证码']);
        if ($v->fails() || !Captcha::check((string)$data['captcha'])) {
            Session::flash('error', $v->firstError() ?: '验证码不正确');
            if ((string)($data['booking_mode'] ?? '') === 'slot') {
                \app\Core\ResponseRedirect('/booking/confirm?counselor=' . (int)($data['counselor_id'] ?? 0)
                    . '&date=' . urlencode((string)($data['prefer_date'] ?? '')) . '&start=' . urlencode((string)($data['slot_start'] ?? '')));
            }
            \app\Core\ResponseRedirect('/booking');
        }
        if (empty($data['consent'])) {
            Session::flash('error', '请先阅读并勾选《咨询知情同意书》与《隐私政策》');
            \app\Core\ResponseRedirect('/booking');
        }
        if (($data['age_range'] ?? '') === '18岁以下' && empty($data['guardian_consent'])) {
            Session::flash('error', '未成年人预约需勾选监护人知情同意');
            \app\Core\ResponseRedirect('/booking');
        }
        // v2.17.0：槽位预约（确认页提交）——精确时段校验 + 标准化 prefer_slot「HH:MM-HH:MM」
        $isSlotMode = (string)($data['booking_mode'] ?? '') === 'slot';
        if ($isSlotMode) {
            $cidS = (int)($data['counselor_id'] ?? 0);
            $dateS = (string)($data['prefer_date'] ?? '');
            $startS = (string)($data['slot_start'] ?? '');
            if ($cidS <= 0 || $dateS === '' || $startS === '') {
                Session::flash('error', '预约信息不完整，请从咨询师日历重新选择时段');
                \app\Core\ResponseRedirect('/booking');
            }
            [$okS, $msgOrLabelS] = \app\Services\BookingSlots::checkAvailable($cidS, $dateS, $startS);
            if (!$okS) {
                Session::flash('error', $msgOrLabelS);
                \app\Core\ResponseRedirect('/booking?counselor=' . $cidS . '&source=counselor');
            }
            $data['prefer_slot'] = (string)$msgOrLabelS; // 「HH:MM-HH:MM」
        }

        $code = gen_code(8);
        // v2.5.0 排班钩子：插件可校验（如同一咨询师同日时段冲突）；返回 error 字符串即拦截
        $hookErr = \app\Core\Hook::fire('booking.validate', ['data' => $data, 'error' => null])['error'] ?? null;
        if ($hookErr) {
            Session::flash('error', (string)$hookErr);
            \app\Core\ResponseRedirect('/booking');
        }
        // v2.8.0 插件扩展点：stores-pro / eap-pro 校验并补充字段（store_id/enterprise_id 等）
        $extra = \app\Core\Hook::fire('booking.before_insert', ['data' => $data, 'fields' => [], 'note' => '', 'error' => null]);
        if (!empty($extra['error'])) {
            Session::flash('error', (string)$extra['error']);
            \app\Core\ResponseRedirect('/booking');
        }
        // v2.13.0：视频咨询 → 在线支付；v2.17.0：分时定价（fee_override）优先，兜底「收费起」
        $amountCents = null; $payStatus = 'free';
        if (mb_stripos((string)($data['method'] ?? ''), '视频') !== false) {
            $cidP = (int)($data['counselor_id'] ?? 0);
            $amountCents = $cidP > 0
                ? \app\Services\BookingSlots::slotPriceCents($cidP, (string)($data['prefer_date'] ?? ''), (string)($data['prefer_slot'] ?? ''))
                : null;
            if ($amountCents === null || $amountCents <= 0) { $payStatus = 'free'; } // 咨询师未定价 → 免费占位（线下沟通）
            else { $payStatus = 'unpaid'; }
        }
        $id = Database::insert('bookings', array_merge([
            'code' => $code,
            'service_id' => (int)($data['service_id'] ?? 0) ?: null,
            'counselor_id' => (int)($data['counselor_id'] ?? 0) ?: null,
            'name' => (string)$data['name'],
            'gender' => in_array(($data['gender'] ?? ''), ['1','2'], true) ? (int)$data['gender'] : null,
            'age_range' => mb_substr((string)($data['age_range'] ?? ''), 0, 19),
            'phone' => (string)$data['phone'],
            'email' => mb_substr((string)($data['email'] ?? ''), 0, 119),
            'wechat' => mb_substr((string)($data['wechat'] ?? ''), 0, 59),
            'method' => mb_substr((string)($data['method'] ?? ''), 0, 19),
            'prefer_date' => $data['prefer_date'] ?: null,
            'prefer_slot' => mb_substr((string)($data['prefer_slot'] ?? ''), 0, 59),
            'issue' => mb_substr((string)($data['issue'] ?? ''), 0, 999),
            'urgency' => !empty($data['urgency']) ? 1 : 0,
            'status' => 'pending',
            // v2.13.0：线上咨询定价与支付状态（面对面/电话默认 free 线下结算；视频按时段价在线支付）
            'amount_cents' => $amountCents,
            'pay_status' => $payStatus,
            'source' => in_array(($data['source'] ?? ''), ['assessment', 'chat', 'counselor', 'slot'], true)
                ? (string)$data['source'] : 'web',
            'ip' => $req->ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ], is_array($extra['fields'] ?? null) ? $extra['fields'] : []));

        // 通知管理员（紧急时高优先）
        $svc = $id && $data['service_id'] ? Database::fetch('SELECT name FROM services WHERE id=?', [(int)$data['service_id']]) : null;
        $csl = $id && $data['counselor_id'] ? Database::fetch('SELECT name FROM counselors WHERE id=?', [(int)$data['counselor_id']]) : null;
        $body = sprintf(
            "姓名：%s\n手机：%s\n服务：%s\n咨询师：%s\n期望日期：%s %s\n方式：%s\n主诉：%s\n查询码：%s",
            $data['name'], mask_phone($data['phone']),
            $svc['name'] ?? '未选择', $csl['name'] ?? '未指定',
            $data['prefer_date'] ?: '不限', $data['prefer_slot'] ?: '',
            $data['method'] ?? '不限', str_take((string)($data['issue'] ?? ''), 200, '…'), $code
        );
        if (($extra['note'] ?? '') !== '') { $body .= "\n" . (string)$extra['note']; }
        NotifyService::push(
            'booking',
            '收到新预约（' . ($data['urgency'] ? '标注紧急' : '常规') . '）',
            $body,
            ['type' => 'booking', 'id' => $id],
            true
        );
        Logger::info('booking', 'new booking', ['id' => $id]);
        // v2.9.0：预约提交回执 → 通知会员（登录会员或手机号匹配的会员；v2.17.0 合并去重，只推一次）

        // 若留了邮箱，队列发送预约确认邮件（SMTP 配置后自动生效）
        $email = trim((string)($data['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $siteName = (string)setting('site.name', '一二三');
            $confirmHtml = '<div style="font-family:-apple-system,\'PingFang SC\',sans-serif;max-width:560px;margin:0 auto">'
                . '<h2 style="color:#2a9d8f">预约已收到</h2>'
                . '<p>' . e((string)$data['name']) . '，您好！我们已收到你的预约，会在工作时间尽快与你确认具体时间。</p>'
                . '<p style="background:#f0fdf4;padding:12px 16px;border-radius:8px">预约查询码：<strong>' . e($code) . '</strong>（凭此码+手机号可随时 <a href="' . url('/booking/check') . '">查询进度</a>）</p>'
                . '<p style="color:#64748b;font-size:13px">如需改期或取消，请回复本邮件或拨打 ' . e(setting('contact.phone', '我们的电话')) . '。<br>—— ' . e($siteName) . '</p></div>';
            try {
                Database::insert('email_queue', [
                    'to_email' => $email, 'subject' => '【' . $siteName . '】预约确认（查询码 ' . $code . '）',
                    'body_html' => $confirmHtml, 'priority' => 3, 'status' => 'queued',
                    'scheduled_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $t) {
                Logger::error('booking-mail', $t->getMessage());
            }
        }
        // v2.14.0：会员三通道（站内/微信/短信）+ 咨询师三通道
        $notifyMid = $mCur2 ? (int)$mCur2['id'] : \app\Services\MemberNotifyService::memberIdByPhone((string)$data['phone']);
        $notifyData = [
            'title' => '预约已提交：' . $code,
            'body' => ($data['prefer_date'] ?: '待定') . ' ' . ($data['prefer_slot'] ?? '') . ' · ' . ($data['method'] ?? ''),
            'lines' => ['查询码' => $code, '日期' => $data['prefer_date'] ?: '不限', '时段' => $data['prefer_slot'] ?? '', '方式' => $data['method'] ?? ''],
            'inapp_url' => '/member/bookings', 'ref_type' => 'booking', 'ref_id' => $code,
            'booking' => ['id' => $id, 'code' => $code, 'phone' => (string)$data['phone'], 'name' => (string)$data['name'],
                'prefer_date' => $data['prefer_date'], 'prefer_slot' => $data['prefer_slot'], 'counselor_id' => (int)($data['counselor_id'] ?? 0)],
        ];
        if ($mCur2) {
            \app\Services\MemberNotifyService::push((int)$mCur2['id'], 'booking_new', $notifyData);
        } elseif ((int)$notifyMid > 0) {
            \app\Services\MemberNotifyService::push((int)$notifyMid, 'booking_new', $notifyData);
        }
        if ((int)($data['counselor_id'] ?? 0) > 0) {
            \app\Services\CounselorNotifyService::push((int)$data['counselor_id'], 'booking_new', [
                'title' => '新预约：' . (string)$data['name'] . '（' . mask_phone((string)$data['phone']) . '）',
                'body' => ($data['prefer_date'] ?: '待定') . ' ' . ($data['prefer_slot'] ?? '') . ' · ' . ($data['method'] ?? ''),
                'url' => '/admin/counselor-home/bookings', 'booking_id' => $id,
                'booking' => ['id' => $id, 'code' => $code, 'phone' => (string)$data['phone']],
            ]);
        }
        Session::set('booking_created', [
            'code' => $code, 'name' => (string)$data['name'],
            'amount_cents' => $amountCents, 'pay_status' => $payStatus,
            'method' => (string)($data['method'] ?? ''),
            'id' => $id,
            'slot_mode' => $isSlotMode,
        ]);
        // v2.17.0：槽位预约且需在线支付 → 登录会员直接进收银台；未登录到成功页引导登录后支付
        if ($isSlotMode && $amountCents !== null && $mCur2) {
            \app\Core\ResponseRedirect('/plugin/wechat-pay/pay-booking/' . $id);
        }
        \app\Core\ResponseRedirect('/booking/success');
    }

    public function success(Request $req, array $p): void
    {
        $b = Session::get('booking_created');
        if (!$b) { \app\Core\ResponseRedirect('/booking'); }
        Session::forget('booking_created');
        $meta = SeoService::meta('预约已提交', '', '', url('/booking/success'));
        // v2.13.0：视频咨询在线支付入口（需会员登录；未登录提示先登录）
        $m = \app\Services\MemberService::current();
        View::render('booking/success', ['meta' => $meta, 'b' => $b,
            'payBooking' => $b['pay_status'] ?? null, 'payAmount' => $b['amount_cents'] ?? null,
            'isMember' => (bool)$m,
            'crumbs' => ['首页' => url('/'), '预约咨询' => url('/booking'), '提交成功' => '']]);
    }

    /** AJAX：某咨询师某日期的可约时段（v2.17.0 走统一槽位服务：切片/例外/请假/占用/提前截止） */
    public function slots(Request $req, array $p): void
    {
        $cid = (int)$req->get('counselor_id', 0);
        $date = (string)$req->get('date', '');
        if ($cid <= 0 || $date === '' || strtotime($date) === false) {
            json_out(['ok' => false, 'slots' => []]);
        }
        if (strtotime($date) < strtotime(date('Y-m-d'))) {
            json_out(['ok' => true, 'slots' => [], 'msg' => '日期已过期']);
        }
        $day = \app\Services\BookingSlots::slotsFor($cid, $date);
        // 兼容旧消费方：slots 为「HH:MM-HH:MM」字符串数组（含已占/不可约——与旧口径一致按模板给全量）
        $legacy = [];
        foreach ($day['slots'] as $s) { $legacy[] = $s['label']; }
        // v2.5.0 排班钩子保留（schedule-pro 幂等增强）
        $legacy = \app\Core\Hook::fire('booking.slots', ['counselor_id' => $cid, 'date' => $date, 'slots' => $legacy])['slots'] ?? $legacy;
        json_out([
            'ok' => true,
            'slots' => $legacy,
            'slots_full' => array_map(fn($s) => [
                'label' => $s['label'], 'start' => $s['start'], 'end' => $s['end'], 'available' => $s['available'],
            ], $day['slots']),
            'leave' => $day['leave'], 'closed_note' => $day['closed_note'],
            'confirm_url' => url('/booking/confirm'),
        ]);
    }

    /** 凭码查询进度 */
    public function check(Request $req, array $p): void
    {
        StatsService::track();
        $result = null;
        if ($req->isPost()) {
            Csrf::guard();
            if (!RateLimiter::hit('booking_check', $req->ip(), 10, 600)) {
                $result = ['error' => '查询过于频繁，请稍后再试'];
            } else {
                $code = strtoupper((string)$req->post('code', ''));
                $phone = (string)$req->post('phone', '');
                $b = Database::fetch('SELECT * FROM bookings WHERE code=? AND phone=?', [$code, $phone]);
                if (!$b) {
                    $result = ['error' => '未找到对应预约，请核对查询码与手机号'];
                } else {
                    $statusMap = ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'];
                    $result = ['booking' => [
                        'code' => $b['code'],
                        'name' => $b['name'],
                        'status' => $statusMap[$b['status']] ?? $b['status'],
                        'prefer_date' => $b['prefer_date'],
                        'prefer_slot' => $b['prefer_slot'],
                        'note' => $b['admin_note'],
                        'created_at' => $b['created_at'],
                    ]];
                }
            }
        }
        $meta = SeoService::meta('预约进度查询', '', '', url('/booking/check'));
        View::render('booking/check', [
            'meta' => $meta, 'result' => $result,
            'crumbs' => ['首页' => url('/'), '预约进度查询' => ''],
        ]);
    }
}
