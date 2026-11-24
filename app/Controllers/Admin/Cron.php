<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;
use app\Services\BackupService;
use app\Services\NotifyService;
use app\Services\StatsService;
use app\Services\Updater;

/** 计划任务端点（不继承登录墙，靠 cron_secret 鉴权） */
class Cron
{
    private function guard(): bool
    {
        $secret = (string)(CONFIG['cron_secret'] ?? '');
        if ($secret === '') return false;
        $given = (string)($_GET['secret'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));
        return $given !== '' && hash_equals($secret, $given);
    }

    /** 定时发布：到时的 scheduled 文章自动发布 + 推送 + 钩子 */
    public function publish(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $rows = Database::fetchAll(
            "SELECT id, title, slug FROM articles WHERE status='scheduled' AND published_at <= NOW() LIMIT 20"
        );
        $done = 0;
        foreach ($rows as $a) {
            Database::run("UPDATE articles SET status='published' WHERE id=? AND status='scheduled'", [$a['id']]);
            \app\Core\Hook::fire('article.published', (int)$a['id']);
            try {
                $u = [\url('/news/' . $a['slug'])];
                \app\Services\SeoService::pushBaidu($u);
                \app\Services\SeoService::pushIndexNow($u);
            } catch (\Throwable $t) { \app\Core\Logger::error('seo-push', $t->getMessage()); }
            $done++;
        }
        echo json_encode(['ok' => true, 'published' => $done], JSON_UNESCAPED_UNICODE);
    }

    public function mail(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        // 兼容端点：邮件队列已由 mail-notify 插件的分钟任务处理
        $done = \app\Core\PluginManager::runCron('minutely');
        echo json_encode(['ok' => true, 'jobs' => $done], JSON_UNESCAPED_UNICODE);
    }

    public function stats(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $r = StatsService::aggregate();
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    }

    /** 预约提醒：明日 confirmed 预约推送管理员（由管理员联系会员） */
    public function bookingReminder(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $rows = Database::fetchAll(
            "SELECT b.*, s.name svc, c.name cname FROM bookings b
             LEFT JOIN services s ON s.id=b.service_id
             LEFT JOIN counselors c ON c.id=b.counselor_id
             WHERE b.status='confirmed' AND b.prefer_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
        );
        foreach ($rows as $b) {
            NotifyService::push('booking', '明日预约提醒：' . $b['name'] . '（' . mask_phone($b['phone']) . '）',
                sprintf("查询码 %s\n服务：%s\n咨询师：%s\n时间：%s %s\n请提前与会员确认到场/上线。",
                    $b['code'], $b['svc'] ?: '未填', $b['cname'] ?: '未指定',
                    $b['prefer_date'], $b['prefer_slot'] ?: ''),
                ['type' => 'booking', 'id' => $b['id']], true);
        }
        echo json_encode(['ok' => true, 'reminded' => count($rows)], JSON_UNESCAPED_UNICODE);
    }

    /** 插件任务调度：?every=minute|day（默认 day） */
    public function plugins(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $every = (string)($_GET['every'] ?? 'day') === 'minute' ? 'minutely' : 'daily';
        $done = \app\Core\PluginManager::runCron($every);
        echo json_encode(['ok' => true, 'schedule' => $every, 'jobs' => $done], JSON_UNESCAPED_UNICODE);
    }

    /** 插件分钟任务（邮件队列等） */
    public function minute(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $done = \app\Core\PluginManager::runCron('minutely');
        self::sessionReminders(); // v2.14.0：咨询提醒引擎（3h 短信 / 10min RTC 微信 / 1h 未确认短信）
        // v2.17.0：短信兜底 sweep（推送 30 分钟未查阅 → 补发短信；notify_escalations 台账防重）
        $smsFallback = \app\Services\MemberNotifyService::sweepEscalations();
        // v2.23.0：随访计划生成器（每日一次，settings 记 last_run 防重）
        $planGen = self::followupPlanGenerate();
        echo json_encode(['ok' => true, 'jobs' => $done, 'reminders' => true, 'sms_fallback' => $smsFallback, 'plan_gen' => $planGen], JSON_UNESCAPED_UNICODE);
    }

    /** v2.23.0：随访计划每日生成（P2-A）。幂等：同日只跑一次，生成器内部按 next_due_at 递推 + 次数封顶。 */
    private static function followupPlanGenerate(): array
    {
        $today = date('Y-m-d');
        if ((string)setting('followup_plan.last_run', '') === $today) {
            return ['ok' => true, 'generated' => 0, 'completed' => 0, 'skipped' => true];
        }
        try {
            $r = \app\Services\FollowupPlanService::generateDue();
            setting_save('followup_plan.last_run', $today, 'cron');
            return ['ok' => true, 'generated' => (int)$r['generated'], 'completed' => (int)$r['completed'], 'skipped' => false];
        } catch (\Throwable $t) {
            \app\Core\Logger::error('followup-plan', 'cron: ' . $t->getMessage());
            return ['ok' => false, 'msg' => $t->getMessage()];
        }
    }

    /**
     * v2.20.1 咨询提醒引擎（每分钟 cron；用户拍板：短信提醒仅保留一条——开始前 2 小时）：
     *  - 前 2 小时：短信提醒（会员+咨询师，防重 remind_sms_3h_at 字段复用，语义改为 2h）
     *  - 前 10 分钟：服务号微信推送（method=视频，含开启时间与进房路径）——非短信通道
     *  - 前 30 分钟：双方微信提醒（session_starting 已移出即时短信矩阵，不再发短信）
     *  - 8:00 当日汇总（morningPush，微信+站内信）
     */
    private static function sessionReminders(): void
    {
        $smsReady = class_exists(\plugins\smsnotify\Plugin::class) && (bool)setting('sms.enabled', '0')
            && trim((string)setting('sms.template_code', '')) !== '';
        $rows = Database::fetchAll(
            "SELECT b.*, c.phone AS csl_phone, c.name AS cname, m.id mid, m.openid
             FROM bookings b
             LEFT JOIN counselors c ON c.id=b.counselor_id
             LEFT JOIN members m ON m.phone=b.phone AND m.status='active'
             WHERE b.status='confirmed' AND b.prefer_date=CURDATE()
               AND (b.remind_sms_3h_at IS NULL OR b.remind_wx_seen_at IS NULL)");
        foreach ($rows as $b) {
            $slot = (string)$b['prefer_slot'];
            if (!preg_match('/^(\d{1,2}):(\d{2})/', $slot, $mm)) continue;
            $startTs = strtotime((string)$b['prefer_date'] . ' ' . sprintf('%02d:%02d', (int)$mm[1], (int)$mm[2]));
            if ($startTs === false) continue;
            $diff = $startTs - time();
            $bid = (int)$b['id'];
            $when = date('H:i', $startTs);
            $mid = (int)($b['mid'] ?? 0);
            $site = (string)setting('site.name', '');
            // ⓪ 前 30 分钟：双方微信提醒（全部咨询方式；member_notify_log/counselor_notify_log 20 分钟内去重）
            if ($diff > 0 && $diff <= 30 * 60) {
                if ($mid > 0
                    && (int)Database::value("SELECT COUNT(*) FROM member_notify_log WHERE member_id=? AND event='session_starting' AND created_at >= NOW() - INTERVAL 20 MINUTE", [$mid]) === 0) {
                    \app\Services\MemberNotifyService::push($mid, 'session_starting', [
                        'title' => '咨询即将开始（' . $when . '）',
                        'body' => mb_stripos((string)$b['method'], '视频') !== false ? '请提前进入咨询室做准备' : '请提前做好咨询准备',
                        'lines' => ['咨询方式' => (string)$b['method'], '开始时间' => $when, '咨询师' => (string)($b['cname'] ?? '')],
                        'inapp_url' => mb_stripos((string)$b['method'], '视频') !== false ? '/plugin/rtc-pro/room/' . $bid : '/member/bookings',
                        'ref_type' => 'booking', 'ref_id' => (string)$b['code'],
                    ]);
                }
                if ((int)($b['counselor_id'] ?? 0) > 0
                    && (int)Database::value("SELECT COUNT(*) FROM counselor_notify_log WHERE counselor_id=? AND event='session_starting' AND created_at >= NOW() - INTERVAL 20 MINUTE", [(int)$b['counselor_id']]) === 0) {
                    \app\Services\CounselorNotifyService::push((int)$b['counselor_id'], 'session_starting', [
                        'title' => '咨询即将开始：' . (string)$b['name'] . '（' . $when . '）',
                        'body' => (string)$b['method'] . ' · ' . mask_phone((string)$b['phone']),
                        'url' => '/admin/counselor-home/bookings', 'booking_id' => $bid,
                    ]);
                }
            }
            // ① 前 2 小时短信（会员+咨询师）——唯一保留的咨询开始短信提醒；至少一条成功即置位（防重复轰炸），全失败下分钟重试
            if ($diff > 0 && $diff <= 2 * 3600 && $b['remind_sms_3h_at'] === null) {
                if ($smsReady) {
                    $label = '咨询即将开始';
                    $phones = [];
                    if ($mid > 0 && preg_match('/^1[3-9]\d{9}$/', (string)$b['phone'])) $phones[] = (string)$b['phone'];
                    if (!empty($b['csl_phone']) && preg_match('/^1[3-9]\d{9}$/', (string)$b['csl_phone'])) $phones[] = (string)$b['csl_phone'];
                    $sentAny = false;
                    foreach ($phones as $ph) {
                        if (\plugins\smsnotify\Plugin::sendMemberSms($ph, ['store' => str_take($site, 12, ''), 'event' => str_take($label, 20, ''), 'time' => $when])) $sentAny = true;
                    }
                    if ($sentAny || !$phones) {
                        Database::run('UPDATE bookings SET remind_sms_3h_at=NOW() WHERE id=?', [$bid]);
                    }
                } else {
                    // 短信未配置：置位避免每分钟空转；配置后新预约自动生效
                    Database::run('UPDATE bookings SET remind_sms_3h_at=NOW() WHERE id=?', [$bid]);
                }
            }
            // ② 前 10 分钟：视频咨询会员进房推送（微信+站内信；v2.20.1 已移出即时短信矩阵）
            $isVideo = mb_stripos((string)$b['method'], '视频') !== false;
            if ($isVideo && $diff > 0 && $diff <= 10 * 60 && $mid > 0 && $b['remind_wx_seen_at'] === null) {
                \app\Services\MemberNotifyService::push($mid, 'session_room_open', [
                    'title' => '咨询室已开启（' . $when . '）',
                    'body' => '请进入咨询室准备开始视频咨询（移动端进入）',
                    'lines' => ['咨询方式' => '视频咨询', '开始时间' => $when, '进入路径' => '会员中心→我的预约→进入咨询室'],
                    'inapp_url' => '/plugin/rtc-pro/room/' . $bid,
                    'ref_type' => 'booking', 'ref_id' => (string)$b['code'],
                ]);
                Database::run('UPDATE bookings SET remind_wx_seen_at=COALESCE(remind_wx_seen_at,NOW()) WHERE id=?', [$bid]); // 推送即视为已提醒（10 分钟节点不再重复）
            }
        }
    }

    /** v2.14.0：每日 8:00 当日咨询微信汇总推送（会员+咨询师） */
    public function morningPush(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $rows = Database::fetchAll(
            "SELECT b.*, m.id mid, c.phone csl_phone FROM bookings b
             LEFT JOIN members m ON m.phone=b.phone AND m.status='active'
             LEFT JOIN counselors c ON c.id=b.counselor_id
             WHERE b.status='confirmed' AND b.prefer_date=CURDATE() ORDER BY b.prefer_slot");
        $pushed = 0;
        foreach ($rows as $b) {
            $when = (string)$b['prefer_slot'];
            $mid = (int)($b['mid'] ?? 0);
            if ($mid > 0) {
                \app\Services\MemberNotifyService::push($mid, 'booking_remind', [
                    'title' => '今日咨询提醒（' . $when . '）',
                    'body' => (string)$b['method'] . ' · 咨询师 ' . (string)($b['cname'] ?? '未指定'),
                    'lines' => ['时间' => '今天 ' . $when, '方式' => (string)$b['method'], '咨询师' => (string)($b['cname'] ?? '')],
                    'inapp_url' => mb_stripos((string)$b['method'], '视频') !== false ? '/plugin/rtc-pro/room/' . (int)$b['id'] : '/member/bookings',
                    'ref_type' => 'booking', 'ref_id' => (string)$b['code'],
                ]);
            }
            if ((int)($b['counselor_id'] ?? 0) > 0) {
                \app\Services\CounselorNotifyService::push((int)$b['counselor_id'], 'session_remind', [
                    'title' => '今日咨询：' . (string)$b['name'] . '（' . $when . '）',
                    'body' => (string)$b['method'] . ' · ' . mask_phone((string)$b['phone']),
                    'url' => '/admin/counselor-home/bookings', 'booking_id' => (int)$b['id'],
                ]);
            }
            $pushed++;
        }
        echo json_encode(['ok' => true, 'pushed' => $pushed], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 每日自动备份（v2.22.2）
     * 客户站 crontab 示例：
     *   30 4 * * * curl -s -H 'X-Cron-Secret: <cron_secret>' 'https://站点域名/admin/cron/backup?kind=db' >/dev/null
     * kind=db（默认）只备数据库；kind=full 连 uploads 一起打 zip（体积大，建议每周一次）。
     */
    public function backup(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        $kind = strtolower((string)$req->get('kind', 'db'));
        $r = $kind === 'full' ? BackupService::full() : BackupService::database();
        echo json_encode([
            'ok'    => (bool)$r['ok'],
            'kind'  => $kind === 'full' ? 'full' : 'db',
            'msg'   => $r['msg'],
            'file'  => $r['file'] !== '' ? basename($r['file']) : '',
            'bytes' => (int)$r['bytes'],
            'exec'  => \app\Services\PhpDbDumper::canExec() ? 'mysqldump' : 'php',
        ], JSON_UNESCAPED_UNICODE);
    }

    public function upgradeCheck(Request $req, array $p): void
    {
        if (!$this->guard()) { http_response_code(403); echo 'forbidden'; exit; }
        \app\Services\LicenseService::heartbeat();               // 远程 token 续期
        \app\Services\LicenseService::integrityCronCheck();      // 核心完整性校验+告警
        $cleaned = \app\Controllers\Admin\Chat::autoCleanup();   // 已关闭会话 30 天自动删除
        $lic = Database::fetch('SELECT * FROM licenses ORDER BY id DESC LIMIT 1');
        if ($lic) {
            Database::update('licenses', ['last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$lic['id']]);
        }
        [$data, $msg] = Updater::checkRemote();
        if ($data && version_compare((string)$data['version'], CMS_VERSION, '>')) {
            NotifyService::push('upgrade', '发现新版本 ' . $data['version'],
                '当前版本 ' . CMS_VERSION . '。请到后台「授权与升级」上传或拉取升级包。');
        }
        echo json_encode(['ok' => true, 'msg' => $msg, 'chats_cleaned' => $cleaned], JSON_UNESCAPED_UNICODE);
    }
}
