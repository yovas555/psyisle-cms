<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;
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
        echo json_encode(['ok' => true, 'jobs' => $done], JSON_UNESCAPED_UNICODE);
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
