<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;
use ZipArchive;

/**
 * 升级通道：上传/拉取 zip 包 → 校验（version.json + HMAC）→ 备份 → 覆盖 → 迁移脚本 → 记录
 * 失败自动回滚到备份
 */
class Updater
{
    /**
     * 检查更新（两通道）：
     *  A) 升级分发主站协议（update.distribute_url 配置时优先）：授权码+域名+nonce → Ed25519 验签应答
     *     返回 ['version','md5','min_from','changelog','plugins'=>[slug,version,md5,size], '_proto'=>'distribute']
     *  B) 旧静态 JSON 检查（update.check_url）：仅版本号提示
     */
    public static function checkRemote(): array
    {
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl !== '') return self::checkDistribute($distUrl);
        $url = (string)setting('update.check_url', '');
        if (!$url) return [null, '未配置更新检查地址'];
        [$ok, $status, $body] = Http::get($url, [], 15);
        if (!$ok || $status >= 400) return [null, '检查失败 status=' . $status];
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['version'])) return [null, '更新源返回格式异常'];
        return [$data, '当前版本 ' . CMS_VERSION . '，远端最新 ' . $data['version']];
    }

    /** 主站分发协议：检查更新（验签，防伪造应答） */
    public static function checkDistribute(string $distUrl): array
    {
        $lic = LicenseService::current();
        if (!$lic) return [null, '未激活授权，无法从主站检查更新'];
        $domain = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        if ((string)$lic['domain'] !== $domain) return [null, '授权域名与本站不符（' . $domain . '）'];
        $nonce = bin2hex(random_bytes(8));
        [$ok, $status, $body] = Http::post($distUrl . '/check',
            json_encode(['license' => (string)$lic['license_key'], 'domain' => $domain, 'nonce' => $nonce, 'ver' => CMS_VERSION], JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json'], 15);
        if (!$ok || $status >= 400) {
            $err = '';
            $r = json_decode((string)$body, true);
            if (is_array($r) && !empty($r['error'])) $err = (string)$r['error'];
            return [null, '主站检查失败（' . $status . '）' . ($err ? '：' . $err : '')];
        }
        $resp = json_decode((string)$body, true);
        if (!is_array($resp) || empty($resp['ok'])) return [null, '主站响应格式异常'];
        $payload = DistributeService::clientVerify((string)($resp['payload_b64'] ?? ''), (string)($resp['signature_b64'] ?? ''));
        if (!$payload) return [null, '主站应答签名验证失败（拒绝采信）'];
        $update = DistributeService::evaluateCheck($payload, $nonce, (string)$lic['license_key'], $domain);
        $plugins = is_array($payload['plugins'] ?? null) ? $payload['plugins'] : [];
        $data = [
            'version' => $update['version'] ?? CMS_VERSION,
            'md5' => $update['md5'] ?? null,
            'min_from' => $update['min_from'] ?? null,
            'changelog' => $update['changelog'] ?? '',
            'plugins' => $plugins,
            '_proto' => 'distribute',
        ];
        $msg = $update ? ('主站有新版本 ' . $update['version']) : ('已是最新版本（主站确认 ' . CMS_VERSION . '）');
        return [$data, $msg];
    }

    /** 从主站下载并安装核心升级包（令牌 → zip → md5 校验 → 现有备份/覆盖/迁移流程） */
    public static function runRemote(): array
    {
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl === '') return [false, '未配置升级分发主站地址（update.distribute_url）'];
        $lic = LicenseService::current();
        if (!$lic) return [false, '未激活授权'];
        $domain = strtolower((string)parse_url(base_url(), PHP_URL_HOST));

        // 1) 令牌
        $nonce = bin2hex(random_bytes(8));
        [$ok, $status, $body] = Http::post($distUrl . '/token',
            json_encode(['license' => (string)$lic['license_key'], 'domain' => $domain, 'nonce' => $nonce, 'kind' => 'core', 'ver' => CMS_VERSION], JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json'], 15);
        if (!$ok || $status >= 400) {
            $r = json_decode((string)$body, true);
            return [false, '主站拒绝发放下载令牌（' . $status . '）：' . (string)($r['error'] ?? '')];
        }
        $resp = json_decode((string)$body, true);
        if (!is_array($resp) || empty($resp['ok'])) return [false, '主站响应异常'];
        $p = DistributeService::clientVerify((string)$resp['payload_b64'], (string)$resp['signature_b64']);
        if (!$p || ($p['act'] ?? '') !== 'download') return [false, '下载令牌签名无效'];

        // 2) 下载
        if (!is_dir(ROOT . '/upgrades')) @mkdir(ROOT . '/upgrades', 0755, true);
        $dest = ROOT . '/upgrades/' . date('Ymd-His') . '-psyisle-' . $p['version'] . '.zip';
        [$ok, $status, $data] = Http::get($distUrl . '/download?t=' . urlencode($resp['payload_b64'] . '.' . $resp['signature_b64']), [], 120);
        if (!$ok || $status >= 400 || strlen($data) < 100) {
            @unlink($dest);
            return [false, '下载失败 status=' . $status];
        }
        file_put_contents($dest, $data);
        // 3) md5 校验（主站令牌携带，防传输损坏/篡改）
        if (!hash_equals((string)$p['md5'], (string)md5_file($dest))) {
            @unlink($dest);
            return [false, '升级包 md5 校验失败，已删除'];
        }
        // 4) 安装（备份 → 白名单覆盖 → 迁移 → 失败回滚）
        return self::run($dest);
    }

    /** 与主站比对插件版本，返回可更新列表 [slug => ['local','remote']] */
    public static function remotePluginUpdates(): array
    {
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl === '' || !LicenseService::current()) return [];
        [$data, ] = self::checkDistribute($distUrl);
        if (!$data) return [];
        $out = [];
        foreach ((array)($data['plugins'] ?? []) as $rp) {
            $slug = (string)($rp['slug'] ?? '');
            if ($slug === '') continue;
            $local = \app\Core\PluginManager::get($slug);
            $localV = (string)($local['version'] ?? '0.0.0');
            if (!version_compare($localV, (string)($rp['version'] ?? '0'), '>=')) {
                $out[$slug] = ['local' => $localV, 'remote' => (string)$rp['version']];
            }
        }
        return $out;
    }

    /** 从主站更新单个插件：令牌 → zip → 官方签名校验 → 备份旧目录 → 解压覆盖 */
    public static function updatePlugin(string $slug): array
    {
        $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', $slug));
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl === '') return [false, '未配置升级分发主站地址'];
        $lic = LicenseService::current();
        if (!$lic) return [false, '未激活授权'];
        $domain = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        $nonce = bin2hex(random_bytes(8));
        [$ok, $status, $body] = Http::post($distUrl . '/token',
            json_encode(['license' => (string)$lic['license_key'], 'domain' => $domain, 'nonce' => $nonce, 'kind' => 'plugin', 'slug' => $slug], JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json'], 15);
        if (!$ok || $status >= 400) {
            $r = json_decode((string)$body, true);
            return [false, '主站拒绝（' . $status . '）：' . (string)($r['error'] ?? '')];
        }
        $resp = json_decode((string)$body, true);
        if (!is_array($resp) || empty($resp['ok'])) return [false, '主站响应异常'];
        $p = DistributeService::clientVerify((string)$resp['payload_b64'], (string)$resp['signature_b64']);
        if (!$p || ($p['act'] ?? '') !== 'download' || ($p['kind'] ?? '') !== 'plugin') return [false, '下载令牌签名无效'];
        [$ok, $status, $data] = Http::get($distUrl . '/download?t=' . urlencode($resp['payload_b64'] . '.' . $resp['signature_b64']), [], 60);
        if (!$ok || $status >= 400 || strlen($data) < 50) return [false, '插件包下载失败 status=' . $status];

        if (!class_exists('ZipArchive')) return [false, '服务器未安装 PHP zip 扩展'];
        $tmp = sys_get_temp_dir() . '/plg-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($tmp, $data);
        if (!hash_equals((string)$p['md5'], (string)md5_file($tmp))) { @unlink($tmp); return [false, '插件包 md5 校验失败']; }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); return [false, '插件包无法解压']; }
        // 包结构必须为 {slug}/plugin.json...
        $mJson = $zip->getFromName($slug . '/plugin.json');
        if ($mJson === false) { $zip->close(); @unlink($tmp); return [false, '插件包结构不符（缺少 ' . $slug . '/plugin.json）']; }
        $m = json_decode($mJson, true);
        if (!is_array($m) || ($m['slug'] ?? '') !== $slug) { $zip->close(); @unlink($tmp); return [false, 'plugin.json 与请求不符']; }
        if (!\app\Core\PluginManager::verifySignature($m)) { $zip->close(); @unlink($tmp); return [false, '插件官方签名校验失败，拒绝安装']; }
        $minCms = (string)($m['min_cms_version'] ?? '');
        if ($minCms !== '' && version_compare(CMS_VERSION, $minCms, '<')) { $zip->close(); @unlink($tmp); return [false, "插件需要核心 ≥ {$minCms}"]; }
        // 备份旧目录 → 覆盖
        $target = ROOT . '/plugins/' . $slug;
        $bak = STORAGE . '/backups/plugin-' . $slug . '-' . date('Ymd-His') . '.zip';
        if (is_dir($target) && is_dir(dirname($bak))) {
            $b = new ZipArchive();
            if ($b->open($bak, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) { self::zipDir($target, $slug, $b); $b->close(); }
        }
        if (!is_dir($target)) @mkdir($target, 0755, true);
        $applied = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/')) continue;
            if (!str_starts_with($name, $slug . '/')) continue; // 只收 slug 目录内
            $rel = substr($name, strlen($slug . '/'));
            if (preg_match('#^(\.\.|/)#', $rel)) continue;
            $dir = dirname($target . '/' . $rel);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            file_put_contents($target . '/' . $rel, (string)$zip->getFromIndex($i));
            $applied++;
        }
        $zip->close();
        @unlink($tmp);
        \app\Core\PluginManager::invalidate();
        NotifyService::push('upgrade', '插件已从主站更新：' . $slug . ' → ' . $p['version'],
            '覆盖 ' . $applied . ' 个文件' . (is_file($bak) ? '，旧版备份 ' . basename($bak) : ''));
        return [true, '插件 ' . $slug . ' 已更新到 ' . $p['version'] . '（覆盖 ' . $applied . ' 文件）'];
    }

    /** 从包文件执行升级 */
    public static function run(string $zipPath): array
    {
        if (!is_file($zipPath)) return [false, '升级包不存在'];
        if (!class_exists('ZipArchive')) return [false, '服务器未安装 PHP zip 扩展'];

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return [false, '升级包无法解压（非 zip 或损坏）'];

        // 1) 校验 version.json
        $verJson = $zip->getFromName('version.json');
        if ($verJson === false) { $zip->close(); return [false, '包内缺少 version.json']; }
        $ver = json_decode($verJson, true);
        if (!is_array($ver) || empty($ver['version'])) { $zip->close(); return [false, 'version.json 无效']; }

        // 2) 签名校验（防止任意包升级）
        $expectSig = LicenseService::sign('UPGRADE-' . $ver['version']);
        if (!empty($ver['signature'])) {
            if (!hash_equals($expectSig, strtoupper((string)$ver['signature']))) {
                $zip->close();
                return [false, '升级包签名校验失败，拒绝安装'];
            }
        }

        $fromVer = CMS_VERSION;
        $backupFile = STORAGE . '/backups/upgrade-backup-' . date('Ymd-His') . '.zip';

        // 3) 备份现有代码（app+public+config）
        $b = new ZipArchive();
        $b->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (['app', 'config', 'public'] as $dir) {
            self::zipDir(ROOT . '/' . $dir, $dir, $b);
        }
        $b->close();

        // 4) 解压覆盖（不删除既有文件）
        $applied = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_starts_with($name, 'version.json') || str_ends_with($name, '/')) continue;
            if (preg_match('#^(\.\.|/)#', $name) || strpos($name, '\\') !== false) continue; // 防目录穿越
            if (str_starts_with($name, 'config/')) continue; // 客户站凭据永不被覆盖
            $target = ROOT . '/' . $name;
            if (!preg_match('#^/(app|public|database)/#', '/' . $name)) continue; // 只允许安全目录
            $stream = $zip->getStream($name);
            if (!$stream) continue;
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            @file_put_contents($target, stream_get_contents($stream));
            $applied++;
        }
        // version.json 保留到 upgrades/
        @file_put_contents(ROOT . '/upgrades/last-applied.json', json_encode([
            'from' => $fromVer, 'to' => $ver['version'], 'applied_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE));
        $zip->close();

        // 5) 迁移脚本
        $migrationError = '';
        if (is_file(ROOT . '/upgrades/upgrade.php')) {
            ob_start();
            try { require ROOT . '/upgrades/upgrade.php'; }
            catch (\Throwable $t) { $migrationError = $t->getMessage(); }
            ob_end_clean();
            @rename(ROOT . '/upgrades/upgrade.php', ROOT . '/upgrades/upgrade-' . $ver['version'] . '.done.php');
        }

        $result = $migrationError === '' ? 'success' : 'failed';
        Database::insert('upgrade_history', [
            'from_version' => $fromVer, 'to_version' => $ver['version'],
            'package_file' => basename($zipPath), 'backup_file' => basename($backupFile),
            'result' => $result,
            'detail' => $migrationError ?: ('applied ' . $applied . ' files'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($migrationError !== '') {
            // 迁移失败 → 回滚
            self::restoreBackup($backupFile);
            Database::run("UPDATE upgrade_history SET result='rolledback' ORDER BY id DESC LIMIT 1");
            return [false, '迁移脚本失败已回滚：' . $migrationError];
        }

        NotifyService::push('upgrade', '系统已升级到 ' . $ver['version'], "来源版本 {$fromVer}，覆盖 {$applied} 个文件，备份：" . basename($backupFile));
        return [true, '升级完成：' . $fromVer . ' → ' . $ver['version']];
    }

    private static function restoreBackup(string $backupFile): void
    {
        if (!is_file($backupFile) || !class_exists('ZipArchive')) return;
        $zip = new ZipArchive();
        if ($zip->open($backupFile) !== true) return;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;
            $target = ROOT . '/' . $name;
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            @file_put_contents($target, $zip->getFromIndex($i));
        }
        $zip->close();
    }

    private static function zipDir(string $src, string $prefix, ZipArchive $zip): void
    {
        if (!is_dir($src)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = $prefix . '/' . substr($f->getPathname(), strlen($src) + 1);
            $zip->addFile($f->getPathname(), str_replace('\\', '/', $rel));
        }
    }
}
