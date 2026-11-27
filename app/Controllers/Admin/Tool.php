<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Cache;
use app\Core\Csrf;
use app\Core\Database;
use app\Core\Logger;
use app\Core\Request;
use app\Core\Session;
use app\Core\Uploader;
use app\Services\BackupCrypto;
use app\Services\BackupService;
use app\Services\NotifyService;
use app\Services\SeoService;
use app\Services\StatsService;
use app\Services\Updater;

class Tool extends Base
{
    protected string $permNode = 'tools';
    protected string $title = '运维工具';
    protected string $baseSeg = 'tools';

    /** v2.16.0：一键清除演示内容 */
    
    /** 演示内容清除向导页 */
    public function purgeDemo(Request $req, array $p): void
    {
        $demoMode = (bool)setting('site.demo_mode', '0');
        // 未来日期的 published 活动置顶标红
        $futureEvents = Database::fetchAll(
            "SELECT id, title, event_date AS start_time FROM events WHERE status='published' AND event_date > NOW() ORDER BY event_date LIMIT 10");
        $demoArticles = Database::fetchAll(
            "SELECT id, slug, title FROM articles WHERE slug IN ('welcome','about-us','hello-world') OR source='demo' LIMIT 10");
        $this->view('tool/purge-demo', [
            'title' => '清除演示内容', 'demoMode' => $demoMode,
            'futureEvents' => $futureEvents, 'demoArticles' => $demoArticles,
        ]);
    }

    /** v2.16.0：反馈上报页 */
    public function feedback(Request $req, array $p): void
    {
        $configured = \app\Services\FeedbackClient::configured();
        $lastResult = \app\Core\Cache::get('feedback_last_result');
        $this->view('tool/feedback', ['title' => '上报问题/需求', 'configured' => $configured, 'lastResult' => $lastResult]);
    }

    /** 提交反馈 */
    public function submitFeedback(Request $req, array $p): void
    {
        Csrf::guard();
        [$ok, $msg] = \app\Services\FeedbackClient::submit(
            (string)$req->post('type', 'bug'),
            str_take(trim((string)$req->post('title', '')), 80, ''),
            str_take(trim((string)$req->post('body', '')), 500, ''),
            (string)$req->post('severity', 'P3'),
            (string)$req->post('area', 'general')
        );
        Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/admin/tools/feedback');
    }

    /** v2.16.0：克隆/换身份向导 */
    public function cloneWizard(Request $req, array $p): void
    {
        $scan = \app\Services\CloneDetector::scan();
        $this->view('tool/clone-wizard', ['title' => '克隆/换身份向导', 'scan' => $scan]);
    }

    /** 一键重生成安全键 */
    public function cloneRegen(Request $req, array $p): void
    {
        Csrf::guard();
        $skey = (string)$req->post('skey', '');
        [$ok, $msg] = \app\Services\CloneDetector::regenerate($skey);
        $this->audit('Tool.cloneRegen', 'settings', null, ['skey' => $skey]);
        if ($ok) {
            // 安全键值只落库，绝不进入 flash；审计也只记录键名。
            $safeMessages = [
                'cron_secret' => '计划任务密钥已轮换；旧密钥已失效',
                'security.admin_gate' => '后台入口门禁已轮换；请使用新参数访问后台',
                'feedback.site_secret' => '反馈密钥已轮换；旧密钥已失效',
            ];
            $msg = $safeMessages[$skey] ?? '安全配置已更新';
        }
        Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/admin/tools/clone-wizard');
    }

    public function index(Request $req, array $p): void
    {
        $backups = [];
        // v2.21.0：备份一律加密落盘（.enc）；旧版明文 gz/zip 若仍存在也列出来提示尽快清理
        foreach (glob(STORAGE . '/backups/*.{enc,sql.gz,zip}', GLOB_BRACE) ?: [] as $f) {
            $backups[] = [
                'file' => basename($f),
                'size' => filesize($f),
                'mtime' => date('Y-m-d H:i', filemtime($f)),
                'encrypted' => BackupCrypto::isEncrypted($f),
            ];
        }
        usort($backups, fn($a, $b) => strcmp($b['mtime'], $a['mtime']));
        $logs = [];
        foreach (glob(STORAGE . '/logs/*-error.log') ?: [] as $f) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $logs[basename($f)] = array_slice($lines ?: [], -30);
        }
        krsort($logs);
        $logs = array_slice($logs, 0, 2, true);

        $cronUrl = rtrim(base_url(), '/');
        $this->view('tool/index', [
            'backups' => $backups, 'logs' => $logs,
            'cronUrl' => $cronUrl,
            'robotsExtra' => setting('seo.robots_extra'),
            'title' => $this->title,
        ]);
    }

    public function pushUrls(Request $req, array $p): void
    {
        Csrf::guard();
        $urls = \app\Services\SeoService::recentUrls(50);
        [$b1, $m1] = \app\Services\SeoService::pushBaidu($urls);
        [$b2, $m2] = \app\Services\SeoService::pushIndexNow($urls);
        $this->audit('tool.push_urls');
        Session::flash($b1 || $b2 ? 'success' : 'warning', $m1 . '；' . $m2 . '（未配置 token 时为提示，配置见系统设置-SEO）');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    public function rebuildSitemap(Request $req, array $p): void
    {
        Csrf::guard();
        Cache::forget('settings_all');
        Session::flash('success', '本系统 sitemap/robots/rss/llms.txt 为动态实时生成，无需重建。可到各大站长平台重新提交。');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /**
     * 一键清理缓存（v2.24.1）：应用文件缓存 + PHP opcache + 阿里云 CDN 目录刷新（已配置 AccessKey 时）。
     * 场景：系统升级后，让会员无需「强制刷新」即自动看到新页面。
     */
    public function cachePurge(Request $req, array $p): void
    {
        Csrf::guard();
        $out = [];
        // ① 应用文件缓存（settings_all / 限流 / 会话外缓存等）
        Cache::flush();
        $out[] = '应用缓存已清空';
        // ② PHP opcache（升级部署后立即生效，无需 reload）
        if (function_exists('opcache_reset') && @opcache_reset()) {
            $out[] = 'PHP opcache 已重置';
        } else {
            $out[] = 'opcache 不可用（跳过）';
        }
        // ③ 阿里云 CDN 刷新（配置 AccessKey 后自动；未配置给指引）
        $akId = trim((string)setting('cdn.access_key_id', ''));
        $akSecret = trim(\app\Core\Crypto::decrypt((string)setting('cdn.access_key_secret', '')));
        $domain = trim((string)setting('cdn.domain', ''));
        if ($domain === '') {
            $domain = preg_replace('#^https?://#', '', rtrim(base_url(), '/'));
        }
        if ($akId !== '' && $akSecret !== '' && $domain !== '') {
            [$ok, $msg] = self::aliyunCdnRefresh($akId, $akSecret, $domain);
            $out[] = $ok ? "CDN 缓存刷新已提交（{$domain}）：{$msg}" : "CDN 刷新失败：{$msg}";
        } else {
            $out[] = '未配置 CDN AccessKey（系统设置→CDN 缓存刷新）——如站点经 CDN 加速，请到阿里云 CDN 控制台手动刷新或配置后自动刷新';
        }
        $this->audit('tool.cache_purge', null, null, ['cdn' => ($akId !== '' ? 'attempted' : 'skipped'), 'domain' => $domain]);
        Session::flash('success', '清理完成：' . implode('；', $out));
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /** 阿里云 CDN 刷新（RefreshObjectCaches，RPC V1 签名）→ [ok, 消息] */
    private static function aliyunCdnRefresh(string $akId, string $akSecret, string $domain): array
    {
        try {
            $params = [
                'Action' => 'RefreshObjectCaches',
                'ObjectPath' => 'https://' . $domain . '/',
                'ObjectType' => 'Directory',
                'Version' => '2018-05-10',
                'Format' => 'JSON',
                'AccessKeyId' => $akId,
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'SignatureNonce' => bin2hex(random_bytes(8)),
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            ksort($params);
            $pairs = [];
            foreach ($params as $k => $v) {
                $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
            }
            $query = implode('&', $pairs);
            $stringToSign = 'GET&' . rawurlencode('/') . '&' . rawurlencode($query);
            $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $akSecret . '&', true));
            [$ok, $status, $resp] = \app\Core\Http::get('https://cdn.aliyuncs.com/?' . http_build_query($params), [], 15);
            $r = json_decode((string)$resp, true);
            if (!$ok) return [false, 'HTTP ' . $status];
            if (isset($r['Code'])) return [false, str_take((string)($r['Message'] ?? $r['Code']), 140, '')];
            return [true, '任务ID ' . (string)($r['RefreshTaskId'] ?? '') . '（约 5 分钟内全网生效）'];
        } catch (\Throwable $t) {
            return [false, str_take($t->getMessage(), 140, '')];
        }
    }

    /** 数据库备份（逻辑在 BackupService，与 cron 端点共用） */
    public function backup(Request $req, array $p): void
    {
        Csrf::guard();
        $r = BackupService::database();
        if (!$r['ok']) {
            Session::flash('error', $r['msg']);
            \app\Core\ResponseRedirect('/admin/tools');
        }
        $this->audit('tool.backup', 'database', null, ['file' => basename($r['file']), 'size' => $r['bytes']]);
        Session::flash('success', $r['msg'] . '（' . round($r['bytes'] / 1024) . 'KB）');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /** 全量备份：数据库 + uploads 压缩为一个 zip，落盘前整体加密 */
    /** 全量备份（逻辑在 BackupService，与 cron 端点共用） */
    public function fullBackup(Request $req, array $p): void
    {
        Csrf::guard();
        $r = BackupService::full();
        if (!$r['ok']) {
            Session::flash('error', $r['msg']);
            \app\Core\ResponseRedirect('/admin/tools');
        }
        $this->audit('tool.full_backup', 'database', null, ['file' => basename($r['file']), 'size' => $r['bytes']]);
        Session::flash('success', $r['msg'] . '（' . round($r['bytes'] / 1024) . 'KB）');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /** 内容导出：全站内容 JSON 包（迁移/备份用） */
    public function exportContent(Request $req, array $p): void
    {
        $tables = ['articles', 'pages', 'categories', 'faqs', 'counselors', 'counselor_slots', 'services', 'events', 'testimonials', 'navigations', 'links'];
        $data = ['_meta' => ['version' => CMS_VERSION, 'exported_at' => date('c'), 'kind' => 'psyisle-content-export']];
        foreach ($tables as $t) {
            try { $data[$t] = Database::fetchAll("SELECT * FROM `$t`"); } catch (\Throwable $t2) { $data[$t] = []; }
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="psyisle-content-' . date('Ymd-Hi') . '.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->audit('tool.export_content');
        exit;
    }

    /** 内容导入：JSON 包 upsert（slug 冲突自动加后缀） */
    public function importContent(Request $req, array $p): void
    {
        Csrf::guard();
        $file = $req->file('content_json');
        if (!$file) { Session::flash('error', '未选择 JSON 文件'); \app\Core\ResponseRedirect('/admin/tools'); }
        $raw = @file_get_contents($file['tmp_name']);
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || ($data['_meta']['kind'] ?? '') !== 'psyisle-content-export') {
            Session::flash('error', '文件无效：需为本系统导出的内容 JSON 包');
            \app\Core\ResponseRedirect('/admin/tools');
        }
        $tables = ['categories', 'articles', 'pages', 'faqs', 'counselors', 'counselor_slots', 'services', 'events', 'testimonials', 'navigations', 'links'];
        $counts = [];
        $u = Session::get('admin_user');
        foreach ($tables as $t) {
            if (empty($data[$t]) || !is_array($data[$t])) continue;
            $cols = Database::fetchAll("SHOW COLUMNS FROM `$t`");
            $colNames = array_column($cols, 'Field');
            $n = 0;
            foreach ($data[$t] as $row) {
                $ins = [];
                foreach ($row as $k => $v) {
                    if (in_array($k, $colNames, true) && $k !== 'id') { $ins[$k] = $v; }
                }
                if (isset($ins['slug'])) {
                    $base = $ins['slug']; $i = 1;
                    while (Database::value("SELECT COUNT(*) FROM `$t` WHERE slug=?", [$ins['slug']]) > 0) {
                        $ins['slug'] = $base . '-imp' . (++$i);
                        if ($i > 50) break;
                    }
                } elseif ($t === 'counselor_slots') {
                    // 明细表直接追加
                }
                $ins['created_at'] = $ins['created_at'] ?? date('Y-m-d H:i:s');
                try {
                    Database::insert($t, $ins);
                    $n++;
                } catch (\Throwable $t3) {
                    Logger::error('import', $t . ' → ' . $t3->getMessage());
                }
            }
            $counts[$t] = $n;
        }
        \app\Core\Cache::forget('home_data');
        $this->audit('tool.import_content', null, null, $counts);
        Session::flash('success', '导入完成：' . implode('，', array_map(fn($k, $v) => $k . ' ' . $v . ' 条', array_keys($counts), $counts)));
        \app\Core\ResponseRedirect('/admin/tools');
    }

    public function clearCache(Request $req, array $p): void
    {
        Csrf::guard();
        Cache::flush();
        $this->audit('tool.cache_flush');
        Session::flash('success', '缓存已清理');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    public function saveRobots(Request $req, array $p): void
    {
        Csrf::guard();
        \app\Core\Model::saveSetting('seo.robots_extra', (string)$req->post('robots_extra', ''), 'seo');
        Cache::forget('settings_all');
        $this->audit('tool.robots');
        Session::flash('success', 'robots.txt 追加规则已保存');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /** v2.18：AI 与开放接口开关（公开 API / MCP，默认关） */
    public function saveAiOpen(Request $req, array $p): void
    {
        Csrf::guard();
        \app\Core\Model::saveSetting('api.public_enabled', !empty($req->post('api_public')) ? '1' : '0', 'seo');
        \app\Core\Model::saveSetting('mcp_enabled', !empty($req->post('mcp')) ? '1' : '0', 'seo');
        Cache::forget('settings_all');
        $this->audit('tool.ai_open', 'settings', null, [
            'api_public' => (bool)setting('api.public_enabled', '0'),
            'mcp' => (bool)setting('mcp_enabled', '0'),
        ]);
        Session::flash('success', '开放接口设置已保存（llms.txt 与 openapi.json 即时生效）');
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /** 上传搜索引擎验证文件到站点根 */
    public function uploadVerify(Request $req, array $p): void
    {
        Csrf::guard();
        $file = $req->file('vfile');
        if (!$file) { Session::flash('error', '未选择文件'); \app\Core\ResponseRedirect('/admin/tools'); }
        $name = basename((string)$file['name']);
        // 白名单：百度/谷歌/360/搜狗/必应验证文件命名特征
        if (!preg_match('/^(baidu_verify|google[a-z0-9]*|sogou_verify|360|shenma|BingSite|bing)[\w\.]*\.(html|xml|txt)$/i', $name)) {
            Session::flash('error', '仅支持搜索引擎验证文件（baidu_verify*/google*/sogou_verify*/BingSite* 等）');
            \app\Core\ResponseRedirect('/admin/tools');
        }
        if (move_uploaded_file($file['tmp_name'], PUBLIC_PATH . '/' . $name)) {
            $this->audit('tool.verify_upload', 'file', null, ['name' => $name]);
            Session::flash('success', '验证文件已放置：' . $name);
        } else {
            Session::flash('error', '保存失败');
        }
        \app\Core\ResponseRedirect('/admin/tools');
    }

    // ===== cron 端点（无需登录，靠随机 secret）=====

    private function cronGuard(): bool
    {
        $secret = (string)(CONFIG['cron_secret'] ?? '');
        if ($secret === '') return false;
        $given = (string)($_GET['secret'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));
        return hash_equals($secret, $given);
    }

    public function cronMail(Request $req, array $p): void
    {
        if (!$this->cronGuard()) { http_response_code(403); echo 'forbidden'; exit; }
        $r = NotifyService::flushQueue(30);
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    }

    public function cronStats(Request $req, array $p): void
    {
        if (!$this->cronGuard()) { http_response_code(403); echo 'forbidden'; exit; }
        $r = StatsService::aggregate();
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    }

    public function cronUpgradeCheck(Request $req, array $p): void
    {
        if (!$this->cronGuard()) { http_response_code(403); echo 'forbidden'; exit; }
        $lic = Database::fetch('SELECT * FROM licenses ORDER BY id DESC LIMIT 1');
        if ($lic) {
            Database::update('licenses', ['last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$lic['id']]);
        }
        [$data, $msg] = Updater::checkRemote();
        if ($data && version_compare((string)$data['version'], CMS_VERSION, '>')) {
            NotifyService::push('upgrade', '发现新版本 ' . $data['version'],
                '当前版本 ' . CMS_VERSION . '。请到后台「授权与升级」上传或拉取升级包。');
        }
        echo json_encode(['ok' => true, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }
}
