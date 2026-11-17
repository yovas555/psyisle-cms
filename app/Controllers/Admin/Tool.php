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
use app\Services\NotifyService;
use app\Services\SeoService;
use app\Services\StatsService;
use app\Services\Updater;

class Tool extends Base
{
    protected string $permNode = 'tools';
    protected string $title = '运维工具';
    protected string $baseSeg = 'tools';

    public function index(Request $req, array $p): void
    {
        $backups = [];
        foreach (glob(STORAGE . '/backups/*.sql.gz') ?: [] as $f) {
            $backups[] = ['file' => basename($f), 'size' => filesize($f), 'mtime' => date('Y-m-d H:i', filemtime($f))];
        }
        foreach (glob(STORAGE . '/backups/*.zip') ?: [] as $f) {
            $backups[] = ['file' => basename($f), 'size' => filesize($f), 'mtime' => date('Y-m-d H:i', filemtime($f))];
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

    public function backup(Request $req, array $p): void
    {
        Csrf::guard();
        $db = CONFIG['db'];
        $file = STORAGE . '/backups/db-' . date('Ymd-His') . '.sql.gz';
        $dumpCmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s 2>/dev/null | gzip > %s',
            escapeshellarg($db['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($db['port'] ?? '3306')),
            escapeshellarg($db['user'] ?? 'root'),
            escapeshellarg($db['pass'] ?? ''),
            escapeshellarg($db['name'] ?? 'psyisle'),
            escapeshellarg($file)
        );
        @exec($dumpCmd, $out, $code);
        $ok = is_file($file) && filesize($file) > 100;
        if ($ok) {
            // 清理 30 天前备份
            foreach (glob(STORAGE . '/backups/*.sql.gz') ?: [] as $f) {
                if (filemtime($f) < strtotime('-30 days')) @unlink($f);
            }
            $this->audit('tool.backup', 'database', null, ['file' => basename($file), 'size' => filesize($file)]);
            Session::flash('success', '数据库备份完成：' . basename($file));
        } else {
            Session::flash('error', '备份失败（检查 mysqldump 可用性）');
        }
        \app\Core\ResponseRedirect('/admin/tools');
    }

    /** 全量备份：数据库 + uploads 压缩为一个 zip */
    public function fullBackup(Request $req, array $p): void
    {
        Csrf::guard();
        if (!class_exists('ZipArchive')) {
            Session::flash('error', '服务器未安装 PHP zip 扩展');
            \app\Core\ResponseRedirect('/admin/tools');
        }
        $db = CONFIG['db'];
        $sqlFile = STORAGE . '/backups/full-db-' . date('Ymd-His') . '.sql';
        $dumpCmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s 2>/dev/null > %s',
            escapeshellarg($db['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($db['port'] ?? '3306')),
            escapeshellarg($db['user'] ?? 'root'),
            escapeshellarg($db['pass'] ?? ''),
            escapeshellarg($db['name'] ?? 'psyisle'),
            escapeshellarg($sqlFile)
        );
        @exec($dumpCmd, $out, $code);
        if (!is_file($sqlFile) || filesize($sqlFile) < 100) {
            Session::flash('error', '数据库导出失败（检查 mysqldump）');
            \app\Core\ResponseRedirect('/admin/tools');
        }
        $zipFile = STORAGE . '/backups/full-' . date('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($sqlFile, 'database.sql');
        // uploads（排除 PHP 类文件以防万一）
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(UPLOAD_PATH, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (preg_match('/\.(php|phtml|php\d)$/i', $f->getExtension())) continue;
            $zip->addFile($f->getPathname(), 'uploads/' . substr($f->getPathname(), strlen(UPLOAD_PATH) + 1));
        }
        $zip->close();
        @unlink($sqlFile);
        // 30 天清理
        foreach (glob(STORAGE . '/backups/full-*.zip') ?: [] as $f) {
            if (filemtime($f) < strtotime('-30 days')) @unlink($f);
        }
        $this->audit('tool.full_backup', 'database', null, ['file' => basename($zipFile), 'size' => filesize($zipFile)]);
        Session::flash('success', '全量备份完成：' . basename($zipFile) . '（' . round(filesize($zipFile) / 1024) . 'KB）');
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
