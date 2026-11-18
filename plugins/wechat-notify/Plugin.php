<?php
/** 微信服务号通知插件：关键事件推送到管理员微信（模板消息），免打扰延迟不丢失，失败降级邮件 */
declare(strict_types=1);

namespace plugins\wechatnotify;

use app\Core\Cache;
use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;
use app\Core\Hook;
use app\Core\Mailer;
use app\Core\QuietHours;
use app\Services\NotifyChannel;
use app\Services\NotifyService;

final class Plugin implements NotifyChannel
{
    /** 通知类型 → [开关设置键, 默认值]（危机/登录告警永远发送，不在此表） */
    private const TYPE_SWITCHES = [
        'booking' => ['wechat.on_booking', '1'],
        'message' => ['wechat.on_message', '1'],
        'chat_question' => ['wechat.on_chat_question', '0'],
        'chat_escalate' => ['wechat.on_chat_escalate', '1'],
        'event' => ['wechat.on_event', '1'],
        'system' => ['wechat.on_system', '1'],
        'upgrade' => ['wechat.on_system', '1'],
        'license' => ['wechat.on_license', '1'],
    ];

    /** 事件类型 → 模板 ID 设置键 */
    private const TYPE_TPL = [
        'booking' => 'wechat.tpl_booking',
        'message' => 'wechat.tpl_message',
        'chat_escalate' => 'wechat.tpl_escalate',
        'crisis' => 'wechat.tpl_crisis',
        'login_alert' => 'wechat.tpl_crisis',
    ];

    private const LABELS = [
        'booking' => '新预约', 'message' => '新留言', 'chat_question' => '访客提问',
        'chat_escalate' => 'AI 转人工', 'crisis' => '危机预警', 'login_alert' => '登录告警',
        'system' => '系统通知', 'upgrade' => '系统升级', 'license' => '授权变更', 'event' => '活动报名',
    ];

    public static function boot(array $meta): void
    {
        self::ensureTable();
        NotifyService::registerChannel(new self());
        // v2.14.0：会员服务号推送响应者（MemberNotifyService 微信通道）
        Hook::on('member.notify.wechat', [self::class, 'onMemberNotify']);
    }

    /** ===== NotifyChannel 实现 ===== */

    public function name(): string { return 'wechat'; }
    public function label(): string { return '微信服务号'; }

    public function enabled(): bool
    {
        return (bool)setting('wechat.enabled', '0')
            && trim((string)setting('wechat.appid', '')) !== ''
            && trim((string)setting('wechat.secret', '')) !== ''
            && self::openids() !== [];
    }

    public function send(string $type, string $title, string $body, array $ref, bool $urgent): bool
    {
        if (isset(self::TYPE_SWITCHES[$type])) {
            [$swKey, $swDefault] = self::TYPE_SWITCHES[$type];
            if (!(bool)setting($swKey, $swDefault)) return false; // 类型被关闭：非失败，不降级
        }
        $tplKey = self::TYPE_TPL[$type] ?? 'wechat.tpl_system';
        $tplId = trim((string)setting($tplKey, ''));
        $openids = self::openids();
        if ($tplId === '' || !$openids) {
            return false;
        }

        // 免打扰：静默期入队（延迟到时段结束，不丢失）；危机/登录告警豁免
        [$inQuiet, $deliverAt] = QuietHours::resolve($type);
        if ($inQuiet) {
            foreach ($openids as $oid) {
                Database::insert('wechat_notify_log', [
                    'type' => $type, 'openid_mask' => self::mask($oid), 'status' => 'queued',
                    'err' => null, 'scheduled_at' => date('Y-m-d H:i:s', $deliverAt),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return true;
        }

        $ok = false;
        foreach ($openids as $oid) {
            $sent = self::pushTemplate($oid, $tplId, $type, $title, $body, $ref);
            // pushTemplate 成功返回 true，失败返回错误串——必须严格 === 判定（错误串为 truthy，误判会虚报到达率）
            self::log($type, $oid, $sent === true ? 'ok' : 'fail', is_string($sent) ? $sent : null);
            $ok = $ok || $sent === true;
        }
        if (!$ok && $urgent) {
            self::fallbackMail($type, $title, $body, '模板消息发送失败');
        }
        return $ok;
    }

    public function stats(): array
    {
        return [
            'ok' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='ok' AND created_at >= CURDATE()"),
            'fail' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='fail' AND created_at >= CURDATE()"),
            'queued' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='queued'"),
        ];
    }

    /**
     * 会员服务号推送响应者（Hook member.notify.wechat）：发模板消息到 members.openid
     * 模板映射：booking_confirm/new → 预约成功提醒；booking_cancel/reschedule → 留言模板；order_paid → 系统模板
     */
    public static function onMemberNotify(array $ctx): array
    {
        if (!(bool)setting('wechat.enabled', '0')) { $ctx['skip'] = '服务号通知未启用'; return $ctx; }
        $openid = (string)($ctx['member']['openid'] ?? '');
        if ($openid === '') { $ctx['skip'] = '会员未绑定微信'; return $ctx; }
        $event = (string)$ctx['event'];
        $tplMap = [
            'booking_confirm' => 'wechat.tpl_booking',
            'booking_new' => 'wechat.tpl_booking',
            'booking_cancel' => 'wechat.tpl_message',
            'booking_reschedule' => 'wechat.tpl_message',
            'booking_completed' => 'wechat.tpl_message',
            'order_paid' => 'wechat.tpl_system',
            'session_starting' => 'wechat.tpl_system',
            'session_room_open' => 'wechat.tpl_crisis',
        ];
        $tplKey = $tplMap[$event] ?? 'wechat.tpl_system';
        $tplId = trim((string)setting($tplKey, ''));
        if ($tplId === '') { $ctx['skip'] = '未配置模板 ' . $tplKey; return $ctx; }
        $title = (string)$ctx['title'];
        // v2.17.0：模板消息带中转短链（点击=已查阅，取消短信兜底）；无台账事件留空
        $sent = self::pushMemberTemplate($openid, $tplId, $event, $title,
            str_take((string)($ctx['data']['body'] ?? ''), 40, ''), (array)($ctx['data']['ref'] ?? []),
            (string)($ctx['click_url'] ?? ''));
        if ($sent === true) {
            Database::insert('wechat_notify_log', [
                'type' => $event, 'openid_mask' => substr($openid, 0, 6) . '…' . substr($openid, -4),
                'status' => 'ok', 'err' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ctx['sent'] = true;
        } else {
            $ctx['skip'] = '发送失败：' . (is_string($sent) ? $sent : 'unknown');
        }
        return $ctx;
    }

    /**
     * 会员类目模板推送（中台模板池字段结构：thing/time/character_string/amount）
     * 事件 → 模板字段映射按中台 msg_templates 的 field_map 对齐。
     * $clickUrl：v2.17.0 中转短链（/n/{token}），点击=已查阅 → 取消短信兜底。
     */
    public static function pushMemberTemplate(string $openid, string $tplId, string $event, string $title, string $body, array $ref, string $clickUrl = ''): bool
    {
        if (!(bool)setting('wechat.enabled', '0')) return false;
        try { $token = self::accessToken(); } catch (\Throwable $t) { Logger::error('wechat-notify', 'member token: ' . $t->getMessage()); return false; }
        $site = (string)setting('site.name', '');
        // 事件字段映射（与中台模板池 field_map 对齐；first/remark 兜底字段微信自动填充）
        // 中台模板池 field_map（同一服务号已审核的类目模板）：字段名必须精确匹配
        // 预约成功 SAO1jxc: thing3=咨询师 thing4=服务 thing6=开始时间 thing26=地点 thing51=会员
        // 取消 nJwT4eY: thing1=会员 thing2=咨询师 time3=开始时间 time5=取消时间
        // 支付成功 4W-8Oaj: character_string2=单号 thing3=服务 time6=支付时间 thing8=会员 amount9=金额
        $fieldSets = [
            'booking_confirm' => ['thing3' => $site, 'thing4' => str_take($title, 20, ''), 'thing6' => date('Y-m-d H:i'), 'thing26' => '线上', 'thing51' => str_take($body, 20, '')],
            'booking_new' => ['thing3' => $site, 'thing4' => str_take($title, 20, ''), 'thing6' => date('Y-m-d H:i'), 'thing26' => '线上', 'thing51' => str_take($body, 20, '')],
            'booking_cancel' => ['thing1' => str_take($body, 20, ''), 'thing2' => $site, 'time3' => date('Y-m-d H:i'), 'time5' => date('Y-m-d H:i')],
            'booking_completed' => ['thing1' => str_take($body, 20, ''), 'thing2' => $site, 'time3' => date('Y-m-d H:i'), 'time5' => date('Y-m-d H:i')],
            'order_paid' => ['character_string2' => str_take((string)($ref['id'] ?? 'TEST'), 32, ''), 'thing3' => str_take($title, 20, ''), 'time6' => date('Y-m-d H:i'), 'thing8' => str_take($body, 20, ''), 'amount9' => ''],
            'session_room_open' => ['character_string2' => str_take($title, 32, ''), 'thing3' => str_take($body, 20, ''), 'time6' => date('Y-m-d H:i'), 'thing8' => '会员', 'amount9' => ''],
            'session_starting' => ['character_string2' => str_take($title, 32, ''), 'thing3' => str_take($body, 20, ''), 'time6' => date('Y-m-d H:i'), 'thing8' => '会员', 'amount9' => ''],
        ];
        $fields = $fieldSets[$event] ?? ['thing3' => $title, 'thing4' => str_take($body, 20, ''), 'time6' => date('Y-m-d H:i')];
        $data = [];
        foreach ($fields as $k => $v) {
            if ((string)$v !== '') $data[$k] = ['value' => $v];
        }
        if (!$data) $data['thing3'] = ['value' => $title];
        $payload = ['touser' => $openid, 'template_id' => $tplId, 'url' => $clickUrl, 'data' => $data];
        [$ok, $status, $resp] = Http::post(
            'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . urlencode($token),
            json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], 10
        );
        $r = json_decode((string)$resp, true);
        if (!$ok || ($r['errcode'] ?? 0) !== 0) {
            $err = ($r['errmsg'] ?? 'network ' . $status) . (isset($r['errcode']) ? ' #' . $r['errcode'] : '');
            if (in_array(($r['errcode'] ?? 0), [40001, 42001], true)) Cache::forget('wechat_access_token');
            Logger::error('wechat-notify', 'member push failed: ' . str_take((string)$err, 110, ''));
            return false;
        }
        return true;
    }

    /** 给指定 OpenID 发模板消息（CounselorNotifyService 等官方服务复用） */
    public static function pushTemplateTo(string $openid, string $tplId, string $title, string $body, string $event): bool
    {
        if (!(bool)setting('wechat.enabled', '0')) return false;
        $sent = self::pushTemplate($openid, $tplId, $event, $title, $body, []);
        return $sent === true;
    }

    /** ===== 发送内核 ===== */

    /** 发送模板消息；成功 true，失败返回错误串 */
    private static function pushTemplate(string $openid, string $tplId, string $type, string $title, string $body, array $ref)
    {
        try { $token = self::accessToken(); } catch (\Throwable $t) { return $t->getMessage(); }
        $site = (string)setting('site.name', '');
        $label = self::LABELS[$type] ?? '通知';
        $refUrl = '';
        if (($ref['type'] ?? '') === 'booking') $refUrl = rtrim(base_url(), '/') . '/admin/bookings/' . (int)($ref['id'] ?? 0) . '/edit';
        $payload = [
            'touser' => $openid,
            'template_id' => $tplId,
            'url' => $refUrl,
            'topcolor' => $type === 'crisis' ? '#DC2626' : '#0D9488',
            'data' => [
                'first' => ['value' => str_take("【{$site}】{$label}", 40, ''), 'color' => $type === 'crisis' ? '#DC2626' : '#173010'],
                'keyword1' => ['value' => str_take($title, 40, '')],
                'keyword2' => ['value' => str_take($body, 40, '') ?: '—'],
                'keyword3' => ['value' => date('m-d H:i')],
                'remark' => ['value' => str_take('详情请登录后台处理 · ' . $site, 60, '')],
            ],
        ];
        [$ok, $status, $resp] = Http::post(
            'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . urlencode($token),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json'], 10
        );
        $r = json_decode((string)$resp, true);
        if (!$ok || ($r['errcode'] ?? 0) !== 0) {
            $err = ($r['errmsg'] ?? 'network status ' . $status) . (isset($r['errcode']) ? ' #' . $r['errcode'] : '');
            // token 失效（40001/42001）→ 清缓存，本条本次不重试（下条触发刷新）
            if (in_array(($r['errcode'] ?? 0), [40001, 42001], true)) Cache::forget('wechat_access_token');
            return str_take((string)$err, 120, '');
        }
        return true;
    }

    /** @return string access_token（成功）；失败抛 RuntimeException */
    private static function accessToken(): string
    {
        $c = Cache::get('wechat_access_token');
        if (is_string($c) && $c !== '') return $c;
        $appid = trim((string)setting('wechat.appid', ''));
        $secret = trim((string)\app\Core\Crypto::decrypt((string)setting('wechat.secret', '')));
        if ($appid === '' || $secret === '') throw new \RuntimeException('appid/secret 未配置');
        [$ok, $status, $resp] = Http::get(
            'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . urlencode($appid) . '&secret=' . urlencode($secret),
            [], 10
        );
        $r = json_decode((string)$resp, true);
        if (!$ok || empty($r['access_token'])) {
            return '获取 access_token 失败：' . str_take((string)($r['errmsg'] ?? ('status ' . $status)), 100, '');
        }
        Cache::set('wechat_access_token', (string)$r['access_token'], 6900);
        return (string)$r['access_token'];
    }

    /** 失败降级邮件（复用邮件通知插件的收件人与 SMTP 配置，不走钩子避免回环） */
    private static function fallbackMail(string $type, string $title, string $body, string $why): void
    {
        try {
            if (!(bool)setting('mail.enabled') || !Mailer::enabled()) return;
            $emails = plugins_mailnotify_PluginRecipients();
            if (!$emails) return;
            $label = self::LABELS[$type] ?? '通知';
            $html = '<p><b>【降级邮件】微信模板消息未送达</b>（' . e($why) . '）</p>'
                . '<p>事件：' . e("【{$label}】" . $title) . '</p><p>' . nl2br(e(str_take($body, 400, ''))) . '</p>';
            foreach ($emails as $to) {
                Mailer::send($to, '【' . setting('site.name', '') . '】' . $label . '（微信通知失败降级）', $html);
            }
        } catch (\Throwable $t) {
            Logger::error('wechat-notify', 'fallback mail failed: ' . $t->getMessage());
        }
    }

    /** 引用 mail-notify 的收件人解析（避免重复代码；其未启用时该类仍可加载） */
    private static function plugins_mailnotify_PluginRecipients(): array
    {
        $out = [];
        foreach (preg_split('/[;，,]+/u', (string)setting('notify.admin_email', '')) ?: [] as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false) $out[] = $e;
        }
        return $out;
    }

    /** ===== 队列与日志 ===== */

    public static function flushQueue(): array
    {
        if (!(bool)setting('wechat.enabled', '0')) return ['skipped' => 'disabled'];
        $rows = Database::fetchAll(
            "SELECT * FROM wechat_notify_log WHERE status='queued' AND scheduled_at <= NOW() LIMIT 20"
        );
        if (!$rows) return ['found' => 0];
        $okCount = 0;
        foreach ($rows as $q) {
            $openids = self::openids();
            $tplId = trim((string)setting((self::TYPE_TPL[$q['type']] ?? 'wechat.tpl_system'), ''));
            $sent = ($tplId !== '' && $openids) ? self::pushTemplate($openids[0], $tplId, (string)$q['type'], $q['type'], '(延迟通知)', []) : '配置缺失';
            if ($sent === true) {
                Database::run("UPDATE wechat_notify_log SET status='ok', sent_at=NOW() WHERE id=?", [(int)$q['id']]);
                $okCount++;
            } else {
                Database::run("UPDATE wechat_notify_log SET attempts=attempts+1, err=? WHERE id=?",
                    [str_take((string)$sent, 110, ''), (int)$q['id']]);
                Database::run("UPDATE wechat_notify_log SET status='failed' WHERE id=? AND attempts>=3", [(int)$q['id']]);
            }
        }
        return ['found' => count($rows), 'sent' => $okCount];
    }

    private static function log(string $type, string $openid, string $status, ?string $err): void
    {
        try {
            Database::insert('wechat_notify_log', [
                'type' => $type, 'openid_mask' => self::mask($openid), 'status' => $status,
                'err' => $err !== null ? str_take($err, 110, '') : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('wechat-notify', 'log failed: ' . $t->getMessage());
        }
    }

    private static function mask(string $openid): string
    {
        return strlen($openid) > 10 ? substr($openid, 0, 6) . '…' . substr($openid, -4) : $openid;
    }

    private static function openids(): array
    {
        $out = [];
        foreach (preg_split('/[;，,]+/u', (string)setting('wechat.admin_openids', '')) ?: [] as $o) {
            $o = trim($o);
            if ($o !== '' && preg_match('/^[A-Za-z0-9_\-]{20,50}$/', $o)) $out[] = $o;
        }
        return $out;
    }

    private static function ensureTable(): void
    {
        try {
            Database::run("CREATE TABLE IF NOT EXISTS `wechat_notify_log` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `type` varchar(30) NOT NULL,
                `openid_mask` varchar(60) NULL,
                `status` varchar(10) NOT NULL DEFAULT 'ok' COMMENT 'ok/fail/queued/failed',
                `err` varchar(120) NULL,
                `attempts` tinyint NOT NULL DEFAULT 0,
                `scheduled_at` datetime NULL,
                `sent_at` datetime NULL,
                `created_at` datetime NULL,
                PRIMARY KEY (`id`), KEY `k_status` (`status`,`scheduled_at`), KEY `k_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $t) {
            Logger::error('wechat-notify', 'ensureTable: ' . $t->getMessage());
        }
    }

    public static function uninstall(): void
    {
        Database::run('DROP TABLE IF EXISTS wechat_notify_log');
        Cache::forget('wechat_access_token');
    }

    /** ===== 后台 ===== */

    public static function adminIndex($req = null): void
    {
        $stats = [
            'ok' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='ok' AND created_at >= CURDATE()"),
            'fail' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='fail' AND created_at >= CURDATE()"),
            'queued' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='queued'"),
            'failed' => (int)Database::value("SELECT COUNT(*) FROM wechat_notify_log WHERE status='failed'"),
        ];
        $recent = Database::fetchAll('SELECT type, openid_mask, status, err, attempts, scheduled_at, created_at FROM wechat_notify_log ORDER BY id DESC LIMIT 12');
        \app\Core\PluginManager::view('wechat-notify', 'admin', [
            'meta' => \app\Core\PluginManager::get('wechat-notify'),
            'stats' => $stats,
            'recent' => $recent,
            'openids' => array_map([self::class, 'mask'], self::openids()),
            'quietState' => QuietHours::describe(),
            'quietOn' => QuietHours::enabled(),
            'tplCount' => count(array_filter(array_map(fn ($k) => trim((string)setting($k, '')), self::TYPE_TPL), fn ($v) => $v !== '')),
        ]);
    }

    public static function sendTest($req = null): array
    {
        if (!(bool)setting('wechat.enabled', '0')) return [false, '插件未启用'];
        $openids = self::openids();
        if (!$openids) return [false, '未配置接收人 OpenID'];
        $tplId = trim((string)setting('wechat.tpl_system', ''));
        if ($tplId === '') return [false, '未配置「系统」模板 ID（模板字段须含 first/keyword1/keyword2/keyword3/remark）'];
        $sent = self::pushTemplate($openids[0], $tplId, 'system', '配置测试消息', '收到即表示微信通知配置正确', []);
        return $sent === true ? [true, '测试消息已发送（请查收微信）'] : [false, '发送失败：' . (string)$sent];
    }
}
