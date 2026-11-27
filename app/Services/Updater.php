<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;
use ZipArchive;

/**
 * 升级通道：完整校验发行包 → 前置插件检查 → 备份 → 白名单逐文件写入并回读 → 迁移 → 记录。
 * 核心安装失败自动回滚；插件使用暂存目录和整目录备份切换，绝不直接覆盖旧插件。
 */
class Updater
{
    private const BACKUP_ROOTS = ['app', 'public', 'database', 'tools'];
    private const WRITE_ROOTS = ['app', 'public', 'database', 'tools'];
    private static int $lastHistoryId = 0;

    /**
     * 检查更新（两通道）：
     * A) 升级分发主站协议：授权码 + 域名 + nonce → Ed25519 验签应答
     * B) 旧静态 JSON 检查：仅版本号提示
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
            'package_type' => $update['package_type'] ?? null,
            'plugin_policy' => $update['plugin_policy'] ?? null,
            'changelog' => $update['changelog'] ?? '',
            'plugins' => $plugins,
            '_proto' => 'distribute',
        ];
        $msg = $update ? ('主站有新版本 ' . $update['version']) : ('已是最新版本（主站确认 ' . CMS_VERSION . '）');
        return [$data, $msg];
    }

    /** 从主站下载并安装核心升级包 */
    public static function runRemote(): array
    {
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl === '') return [false, '未配置升级分发主站地址（update.distribute_url）'];
        $lic = LicenseService::current();
        if (!$lic) return [false, '未激活授权'];
        $domain = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        if ((string)$lic['domain'] !== $domain) return [false, '授权域名与本站不符'];

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
        $p = DistributeService::clientVerify((string)($resp['payload_b64'] ?? ''), (string)($resp['signature_b64'] ?? ''));
        if (!$p || ($p['act'] ?? '') !== 'download' || ($p['kind'] ?? '') !== 'core'
            || !preg_match('/^\d+\.\d+\.\d+$/', (string)($p['version'] ?? ''))
            || !preg_match('/^[a-f0-9]{32}$/', (string)($p['md5'] ?? ''))) {
            return [false, '下载令牌签名或内容无效'];
        }

        [$ok, $status, $data] = Http::get($distUrl . '/download?t=' . urlencode($resp['payload_b64'] . '.' . $resp['signature_b64']), [], 120);
        if (!$ok || $status >= 400 || strlen($data) < 100) return [false, '下载失败 status=' . $status];

        $dest = '';
        try {
            self::guarded(static function () use ($data, $p, &$dest): void {
                self::ensureDirectory(ROOT . '/upgrades');
                $dest = ROOT . '/upgrades/' . date('Ymd-His') . '-psyisle-' . $p['version'] . '.zip';
                self::writeVerified($dest, $data, hash('sha256', $data), 0600);
            });
        } catch (\Throwable $t) {
            return [false, '升级包落盘失败：' . $t->getMessage()];
        }
        $actualMd5 = md5_file($dest);
        if ($actualMd5 === false || !hash_equals((string)$p['md5'], $actualMd5)) {
            [$removed, $removeError] = self::removeFile($dest);
            return [false, '升级包 md5 校验失败，已删除' . ($removed ? '' : '（清理失败：' . $removeError . '）')];
        }
        [$meta, $inspectError] = ReleasePackage::inspect($dest);
        if (!$meta) {
            [$removed, $removeError] = self::removeFile($dest);
            return [false, '升级包完整校验失败：' . $inspectError . ($removed ? '' : '（清理失败：' . $removeError . '）')];
        }
        $tokenError = self::validateTokenPackage($p, $meta, 'core', '');
        if ($tokenError !== '') {
            [$removed, $removeError] = self::removeFile($dest);
            return [false, $tokenError . ($removed ? '' : '（清理失败：' . $removeError . '）')];
        }
        return self::run($dest);
    }

    /** 与主站比对插件版本，返回可更新列表 [slug => ['local','remote']] */
    public static function remotePluginUpdates(): array
    {
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl === '' || !LicenseService::current()) return [];
        [$data] = self::checkDistribute($distUrl);
        if (!$data) return [];
        $out = [];
        foreach ((array)($data['plugins'] ?? []) as $rp) {
            $slug = (string)($rp['slug'] ?? '');
            if ($slug === '') continue;
            $local = \app\Core\PluginManager::get($slug);
            $localV = (string)($local['version'] ?? '0.0.0');
            if (version_compare($localV, (string)($rp['version'] ?? '0'), '<')) {
                $out[$slug] = ['local' => $localV, 'remote' => (string)$rp['version']];
            }
        }
        return $out;
    }

    /** 从主站更新单个插件：完整 inspect → 暂存逐文件回读 → 整目录备份切换 */
    public static function updatePlugin(string $slug): array
    {
        if (!preg_match('/^[a-z0-9\-]{1,60}$/', $slug)) return [false, '插件 slug 非法'];
        $distUrl = rtrim((string)setting('update.distribute_url', ''), '/');
        if ($distUrl === '') return [false, '未配置升级分发主站地址'];
        $lic = LicenseService::current();
        if (!$lic) return [false, '未激活授权'];
        $domain = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        if ((string)$lic['domain'] !== $domain) return [false, '授权域名与本站不符'];
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
        $p = DistributeService::clientVerify((string)($resp['payload_b64'] ?? ''), (string)($resp['signature_b64'] ?? ''));
        if (!$p || ($p['act'] ?? '') !== 'download' || ($p['kind'] ?? '') !== 'plugin' || ($p['slug'] ?? '') !== $slug
            || !preg_match('/^\d+\.\d+\.\d+$/', (string)($p['version'] ?? ''))
            || !preg_match('/^[a-f0-9]{32}$/', (string)($p['md5'] ?? ''))) {
            return [false, '下载令牌签名或内容无效'];
        }
        [$ok, $status, $data] = Http::get($distUrl . '/download?t=' . urlencode($resp['payload_b64'] . '.' . $resp['signature_b64']), [], 60);
        if (!$ok || $status >= 400 || strlen($data) < 50) return [false, '插件包下载失败 status=' . $status];

        $tmp = sys_get_temp_dir() . '/plg-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            self::guarded(static function () use ($tmp, $data): void {
                self::writeVerified($tmp, $data, hash('sha256', $data), 0600, sys_get_temp_dir());
            });
        } catch (\Throwable $t) {
            return [false, '插件包落盘失败：' . $t->getMessage()];
        }
        $actualMd5 = md5_file($tmp);
        if ($actualMd5 === false || !hash_equals((string)$p['md5'], $actualMd5)) {
            [$removed, $removeError] = self::removeFile($tmp);
            return [false, '插件包 md5 校验失败' . ($removed ? '' : '（清理失败：' . $removeError . '）')];
        }
        [$meta, $inspectError] = ReleasePackage::inspect($tmp);
        if (!$meta) {
            [$removed, $removeError] = self::removeFile($tmp);
            return [false, '插件包完整校验失败：' . $inspectError . ($removed ? '' : '（清理失败：' . $removeError . '）')];
        }
        $tokenError = self::validateTokenPackage($p, $meta, 'plugin', $slug);
        if ($tokenError !== '') {
            [$removed, $removeError] = self::removeFile($tmp);
            return [false, $tokenError . ($removed ? '' : '（清理失败：' . $removeError . '）')];
        }
        $minCms = (string)$meta['min_from'];
        if ($minCms !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $minCms)) {
            [$removed] = self::removeFile($tmp);
            return [false, '插件 min_cms_version 格式非法' . ($removed ? '' : '（临时包清理失败）')];
        }
        if ($minCms !== '' && version_compare(CMS_VERSION, $minCms, '<')) {
            [$removed] = self::removeFile($tmp);
            return [false, "插件需要核心 ≥ {$minCms}" . ($removed ? '' : '（临时包清理失败）')];
        }

        $target = ROOT . '/plugins/' . $slug;
        try {
            self::guarded(static function () use ($target): void {
                self::assertDirectoryPath(ROOT . '/plugins');
                if (is_link($target)) throw new \RuntimeException('插件目标不得为符号链接');
                if (file_exists($target) && !is_dir($target)) throw new \RuntimeException('插件目标不是目录');
            });
        } catch (\Throwable $t) {
            [$removed] = self::removeFile($tmp);
            return [false, '插件目标检查失败：' . $t->getMessage() . ($removed ? '' : '（临时包清理失败）')];
        }

        $backupDir = STORAGE . '/backups/plugin-' . $slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $stage = STORAGE . '/backups/.plugin-' . $slug . '-stage-' . bin2hex(random_bytes(6));
        $zip = new ZipArchive();
        $applied = 0;
        try {
            self::guarded(static function () use ($zip, $tmp, $meta, $slug, $stage, &$applied): void {
                if ($zip->open($tmp) !== true) throw new \RuntimeException('插件包无法解压');
                self::ensureDirectory($stage);
                foreach ($meta['entries'] as $name => $entry) {
                    $rel = substr($name, strlen($slug . '/'));
                    $content = $zip->getFromName($name);
                    if ($content === false || strlen($content) !== (int)$entry['size']) {
                        throw new \RuntimeException('读取插件包文件失败：' . $rel);
                    }
                    self::writeVerified($stage . '/' . $rel, $content, (string)$entry['sha256'], 0644);
                    $applied++;
                }
                if (!$zip->close()) throw new \RuntimeException('关闭插件包失败');
            });
        } catch (\Throwable $t) {
            [$removed, $removeError] = self::removeFile($tmp);
            [, $stageError] = self::removeTree($stage);
            return [false, '插件文件写入失败，旧插件未改动：' . $t->getMessage()
                . ($removed ? '' : '（临时包清理失败：' . $removeError . '）')
                . ($stageError === '' ? '' : '（暂存清理失败：' . $stageError . '）')];
        }
        [$removed, $removeError] = self::removeFile($tmp);
        if (!$removed) {
            [, $stageError] = self::removeTree($stage);
            return [false, '插件临时包清理失败：' . $removeError . ($stageError === '' ? '' : '；暂存清理失败：' . $stageError)];
        }

        $hadOld = false;
        $switched = false;
        try {
            self::guarded(static function () use ($target, $backupDir, $stage, $meta, &$hadOld, &$switched): void {
                self::ensureDirectory(ROOT . '/plugins');
                self::ensureDirectory(dirname($backupDir));
                if (is_link($target)) throw new \RuntimeException('插件目标在切换前变为符号链接');
                if (file_exists($target)) {
                    if (!is_dir($target) || file_exists($backupDir) || is_link($backupDir)) {
                        throw new \RuntimeException('插件备份目标不可用');
                    }
                    if (!rename($target, $backupDir)) throw new \RuntimeException('备份旧插件目录失败');
                    $hadOld = true;
                }
                if (!is_dir($backupDir) && $hadOld) throw new \RuntimeException('旧插件备份校验失败');
                if (file_exists($target) || is_link($target)) throw new \RuntimeException('插件目标在切换前已被占用');
                if (!rename($stage, $target)) throw new \RuntimeException('安装新插件目录失败');
                $switched = true;
                clearstatcache(true, $target);
                foreach ($meta['entries'] as $name => $entry) {
                    $installed = $target . '/' . substr($name, strlen($meta['slug'] . '/'));
                    clearstatcache(true, $installed);
                    if (!is_file($installed) || is_link($installed) || filesize($installed) !== (int)$entry['size']
                        || !hash_equals((string)$entry['sha256'], (string)hash_file('sha256', $installed))) {
                        throw new \RuntimeException('新插件目录回读校验失败：' . $name);
                    }
                }
            });
        } catch (\Throwable $t) {
            $restoreError = '';
            $oldRestored = !$hadOld;
            if ($switched) {
                [, $removeError] = self::removeTree($target);
                if ($removeError !== '') {
                    $restoreError = '；新插件目录清理失败，旧插件未恢复：' . $removeError;
                }
            }
            if ($hadOld && $restoreError === '') {
                try {
                    self::guarded(static function () use ($backupDir, $target): void {
                        if (file_exists($target) || is_link($target)) throw new \RuntimeException('旧插件目标仍被占用');
                        if (!is_dir($backupDir) || is_link($backupDir)) throw new \RuntimeException('旧插件备份不可用');
                        if (!rename($backupDir, $target)) throw new \RuntimeException('恢复旧插件目录失败');
                    });
                    $oldRestored = true;
                } catch (\Throwable $restore) {
                    $restoreError = '；旧插件恢复失败，备份位于 ' . $backupDir . '：' . $restore->getMessage();
                }
            }
            if ($hadOld && !$oldRestored && $restoreError === '') {
                $restoreError = '；旧插件未恢复，备份位于 ' . $backupDir;
            }
            [, $stageError] = self::removeTree($stage);
            return [false, '插件更新失败：' . $t->getMessage() . $restoreError
                . ($stageError === '' ? '' : '；暂存清理失败：' . $stageError)];
        }

        \app\Core\PluginManager::invalidate();
        try {
            NotifyService::push('upgrade', '插件已从主站更新：' . $slug . ' → ' . $meta['version'],
                '覆盖 ' . $applied . ' 个文件' . ($hadOld ? '，旧版备份 ' . basename($backupDir) : ''));
        } catch (\Throwable $t) {
            Logger::error('upgrade', 'plugin update notification failed: ' . $t->getMessage());
        }
        return [true, '插件 ' . $slug . ' 已更新到 ' . $meta['version'] . '（覆盖 ' . $applied . ' 文件）'];
    }

    /** 从包文件执行严格核心升级 */
    public static function run(string $zipPath): array
    {
        [$meta, $inspectError] = ReleasePackage::inspect($zipPath);
        if (!$meta) return [false, $inspectError];
        if ($meta['kind'] !== 'core' || $meta['package_type'] !== 'upgrade') {
            return [false, '仅允许安装 package_type=upgrade 的核心升级包'];
        }
        $version = (string)$meta['version'];
        $fromVer = CMS_VERSION;
        if (version_compare($version, $fromVer, '<=')) {
            return [false, '升级包版本必须高于当前版本 ' . $fromVer . '，拒绝降级或重复安装'];
        }
        if ($meta['min_from'] !== '' && version_compare($fromVer, (string)$meta['min_from'], '<')) {
            return [false, '当前版本过低（最低直升版本 ' . $meta['min_from'] . '），请逐级升级'];
        }
        [$pluginsReady, $pluginReason] = ReleasePackage::checkPluginVersions((array)$meta['plugin_policy']['require_plugins']);
        if (!$pluginsReady) return [false, $pluginReason];

        $backupFile = STORAGE . '/backups/upgrade-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        try {
            self::guarded(static function () use ($backupFile): void {
                self::createBackup($backupFile);
            });
        } catch (\Throwable $t) {
            $cleanupError = '';
            if (file_exists($backupFile) || is_link($backupFile)) {
                [, $cleanupError] = self::removeFile($backupFile);
            }
            $historyError = self::recordHistory($fromVer, $version, $zipPath, $backupFile, 'failed',
                '备份失败：' . $t->getMessage() . ($cleanupError === '' ? '' : '；备份清理失败：' . $cleanupError));
            return [false, '升级备份失败，未写入核心文件：' . $t->getMessage()
                . ($cleanupError === '' ? '' : '；备份清理失败：' . $cleanupError)
                . ($historyError === '' ? '' : '；' . $historyError)];
        }

        $created = [];
        $applied = 0;
        $zip = new ZipArchive();
        try {
            self::guarded(static function () use ($zip, $zipPath, $meta, $version, $fromVer, &$created, &$applied): void {
                if ($zip->open($zipPath) !== true) throw new \RuntimeException('升级包无法重新打开');
                foreach ($meta['entries'] as $name => $entry) {
                    if ($name === 'version.json') continue;
                    if (!self::allowedWriteName($name)) throw new \RuntimeException('升级包包含白名单外文件：' . $name);
                    $target = ROOT . '/' . $name;
                    $content = $zip->getFromName($name);
                    if ($content === false || strlen($content) !== (int)$entry['size']) {
                        throw new \RuntimeException('读取升级包文件失败：' . $name);
                    }
                    if (!file_exists($target) && !is_link($target)) $created[] = $target;
                    self::writeVerified($target, $content, (string)$entry['sha256'], 0644);
                    $applied++;
                }
                if (!$zip->close()) throw new \RuntimeException('关闭升级包失败');

                $record = json_encode([
                    'from' => $fromVer,
                    'to' => $version,
                    'applied_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($record === false) throw new \RuntimeException('生成 last-applied.json 失败');
                $lastApplied = ROOT . '/upgrades/last-applied.json';
                if (!file_exists($lastApplied) && !is_link($lastApplied)) $created[] = $lastApplied;
                self::writeVerified($lastApplied, $record, hash('sha256', $record), 0644);
            });

            $migrationError = '';
            $migrationFile = ROOT . '/upgrades/upgrade.php';
            if (is_file($migrationFile)) {
                ob_start();
                try {
                    if (is_link($migrationFile)) throw new \RuntimeException('迁移脚本不得为符号链接');
                    self::guarded(static function () use ($migrationFile): void {
                        require $migrationFile;
                    });
                } catch (\Throwable $t) {
                    $migrationError = $t->getMessage();
                } finally {
                    ob_end_clean();
                }
                if ($migrationError !== '') throw new \RuntimeException('迁移脚本失败：' . $migrationError);
                self::guarded(static function () use ($migrationFile, $version): void {
                    $done = ROOT . '/upgrades/upgrade-' . $version . '.done.php';
                    self::assertDestination($done);
                    if (!rename($migrationFile, $done)) throw new \RuntimeException('迁移脚本归档失败');
                });
            }
            $historyError = self::recordHistory($fromVer, $version, $zipPath, $backupFile, 'success', 'applied ' . $applied . ' files');
            if ($historyError !== '') throw new \RuntimeException($historyError);
        } catch (\Throwable $t) {
            $historyError = self::recordHistory($fromVer, $version, $zipPath, $backupFile, 'failed', $t->getMessage());
            [$restored, $restoreError] = self::restoreBackup($backupFile, $created);
            $historyId = $historyError === '' ? self::$lastHistoryId : 0;
            if ($historyId > 0) {
                try {
                    Database::run("UPDATE upgrade_history SET result=?, detail=? WHERE id=?", [
                        $restored ? 'rolledback' : 'failed',
                        str_take($t->getMessage() . ($restored ? '；已回滚' : '；回滚未完成：' . $restoreError), 1000, ''),
                        $historyId,
                    ]);
                } catch (\Throwable $history) {
                    $historyError = '升级历史状态更新失败：' . $history->getMessage();
                }
            }
            return [false, '升级失败：' . $t->getMessage()
                . ($restored ? '，已回滚' : '，回滚未完成：' . $restoreError)
                . ($historyError === '' ? '' : '；' . $historyError)];
        }

        $message = '升级完成：' . $fromVer . ' → ' . $version;
        try {
            LicenseService::rebuildIntegrityBaseline();
        } catch (\Throwable $t) {
            $error = '完整性基线刷新失败：' . $t->getMessage();
            Logger::error('upgrade', $error);
            $message .= '；' . $error . '，请检查后手动重建';
        }
        try {
            NotifyService::push('upgrade', '系统已升级到 ' . $version, "来源版本 {$fromVer}，覆盖 {$applied} 个文件，备份：" . basename($backupFile));
        } catch (\Throwable $t) {
            Logger::error('upgrade', 'upgrade notification failed: ' . $t->getMessage());
        }
        return [true, $message];
    }

    /** 恢复核心备份；逐项尝试，返回 [是否全部恢复, 错误说明] */
    private static function restoreBackup(string $backupFile, array $created = []): array
    {
        if (!is_file($backupFile)) return [false, '备份文件不存在'];
        $zip = new ZipArchive();
        if ($zip->open($backupFile) !== true) return [false, '备份文件无法打开'];
        $errors = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || substr($name, -1) === '/') continue;
            if ($name !== 'upgrades/last-applied.json' && !self::allowedWriteName($name)) {
                $errors[] = '备份包含非法路径：' . $name;
                continue;
            }
            $target = ROOT . '/' . $name;
            try {
                self::guarded(static function () use ($zip, $i, $target, $name): void {
                    $content = $zip->getFromIndex($i);
                    if ($content === false) throw new \RuntimeException('读取备份失败：' . $name);
                    self::writeVerified($target, $content, hash('sha256', $content), 0644);
                });
            } catch (\Throwable $t) {
                $errors[] = $t->getMessage();
            }
        }
        if (!$zip->close()) $errors[] = '关闭备份文件失败';

        foreach (array_unique($created) as $target) {
            if ($target === ROOT . '/upgrades/last-applied.json' || self::allowedWriteName(substr($target, strlen(ROOT) + 1))) {
                [, $error] = self::removeFile($target);
                if ($error !== '') $errors[] = $error;
            }
        }
        self::removeEmptyCreatedDirs($created, $errors);
        return [$errors === [], implode('；', $errors)];
    }

    private static function createBackup(string $backupFile): void
    {
        self::ensureDirectory(dirname($backupFile));
        self::assertDestination($backupFile);
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建升级备份');
        }
        try {
            foreach (self::BACKUP_ROOTS as $root) {
                $path = ROOT . '/' . $root;
                if (is_link($path)) throw new \RuntimeException('备份目录不得为符号链接：' . $root);
                if (file_exists($path)) self::zipDir($path, $root, $zip);
            }
            $upgrades = ROOT . '/upgrades';
            if (is_link($upgrades) || (file_exists($upgrades) && !is_dir($upgrades))) {
                throw new \RuntimeException('upgrades 目录不得为符号链接或普通文件');
            }
            $lastApplied = $upgrades . '/last-applied.json';
            if (is_link($lastApplied)) throw new \RuntimeException('last-applied.json 不得为符号链接');
            if (file_exists($lastApplied) && !is_file($lastApplied)) throw new \RuntimeException('last-applied.json 不是普通文件');
            if (is_file($lastApplied)) {
                $content = file_get_contents($lastApplied);
                if ($content === false || !$zip->addFromString('upgrades/last-applied.json', $content)) {
                    throw new \RuntimeException('备份 last-applied.json 失败');
                }
            }
        } catch (\Throwable $t) {
            $zip->close();
            throw $t;
        }
        if (!$zip->close()) throw new \RuntimeException('写入升级备份失败');
        if (!chmod($backupFile, 0600)) throw new \RuntimeException('设置升级备份权限失败');
        if (!is_file($backupFile) || filesize($backupFile) < 1 || hash_file('sha256', $backupFile) === false) {
            throw new \RuntimeException('升级备份回读校验失败');
        }
    }

    private static function zipDir(string $src, string $prefix, ZipArchive $zip): void
    {
        if (!is_dir($src)) throw new \RuntimeException('备份目录不存在：' . $prefix);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isLink()) throw new \RuntimeException('备份内容不得包含符号链接：' . $f->getPathname());
            if (!$f->isFile()) continue;
            $content = file_get_contents($f->getPathname());
            if ($content === false) throw new \RuntimeException('读取备份文件失败：' . $f->getPathname());
            $rel = str_replace('\\', '/', $prefix . '/' . substr($f->getPathname(), strlen($src) + 1));
            if (!$zip->addFromString($rel, $content)) throw new \RuntimeException('写入备份条目失败：' . $rel);
        }
    }

    private static function validateTokenPackage(array $token, array $meta, string $kind, string $slug): string
    {
        if (($token['kind'] ?? '') !== $kind || $meta['kind'] !== $kind) return '下载令牌与包类型不符';
        if ($kind === 'plugin' && ((string)($token['slug'] ?? '') !== $slug || (string)$meta['slug'] !== $slug)) {
            return '下载令牌与插件 slug 不符';
        }
        if ($kind === 'core' && !in_array($token['slug'] ?? null, [null, ''], true)) {
            return '核心下载令牌不得携带插件 slug';
        }
        if ((string)($token['version'] ?? '') !== (string)$meta['version']) return '下载令牌与包版本不符';
        if (!hash_equals((string)($token['md5'] ?? ''), (string)$meta['md5'])) return '下载令牌与包 md5 不符';
        if ($kind === 'core' && $meta['package_type'] !== 'upgrade') return '下载令牌指向的不是核心升级包';
        return '';
    }

    private static function allowedWriteName(string $name): bool
    {
        foreach (self::WRITE_ROOTS as $root) {
            if (strpos($name, $root . '/') === 0) return true;
        }
        return false;
    }

    /** 原子写入并立即回读 SHA-256；任何告警都转为异常，禁止吞错。 */
    private static function writeVerified(string $target, string $content, string $expectedHash, int $mode = 0644, ?string $root = null): void
    {
        $root = $root === null ? ROOT : $root;
        self::assertDestination($target, $root);
        self::ensureDirectory(dirname($target), $root);
        $tmp = $target . '.upgrade-' . bin2hex(random_bytes(8));
        try {
            $written = file_put_contents($tmp, $content, LOCK_EX);
            if ($written === false || $written !== strlen($content)) throw new \RuntimeException('写入文件失败：' . $target);
            $tempHash = hash_file('sha256', $tmp);
            if ($tempHash === false || !hash_equals($expectedHash, $tempHash)) throw new \RuntimeException('临时文件 SHA-256 回读不一致：' . $target);
            if (!chmod($tmp, $mode)) throw new \RuntimeException('设置文件权限失败：' . $target);
            if (!rename($tmp, $target)) throw new \RuntimeException('替换文件失败：' . $target);
            clearstatcache(true, $target);
            $actualHash = hash_file('sha256', $target);
            if ($actualHash === false || !hash_equals($expectedHash, $actualHash)) throw new \RuntimeException('文件 SHA-256 回读不一致：' . $target);
        } finally {
            if (file_exists($tmp) || is_link($tmp)) {
                if (!unlink($tmp)) throw new \RuntimeException('清理临时文件失败：' . $tmp);
            }
        }
    }

    private static function assertDestination(string $target, ?string $root = null): void
    {
        $root = $root === null ? ROOT : $root;
        $realRoot = realpath($root);
        if ($realRoot === false) throw new \RuntimeException('目标根目录不存在：' . $root);
        $target = str_replace('\\', '/', $target);
        $prefix = rtrim($realRoot, '/') . '/';
        if (strpos($target, $prefix) !== 0) throw new \RuntimeException('目标路径越界：' . $target);
        $relative = substr($target, strlen($prefix));
        if ($relative === '') throw new \RuntimeException('目标路径为空');
        $parts = explode('/', $relative);
        $current = rtrim($realRoot, '/');
        foreach ($parts as $i => $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new \RuntimeException('目标路径非法：' . $target);
            $current .= '/' . $part;
            if (is_link($current)) throw new \RuntimeException('目标路径不得包含符号链接：' . $current);
            if ($i === count($parts) - 1) {
                if (file_exists($current) && !is_file($current)) throw new \RuntimeException('目标不是普通文件：' . $current);
            } elseif (file_exists($current) && !is_dir($current)) {
                throw new \RuntimeException('目标父路径不是目录：' . $current);
            }
        }
    }

    private static function assertDirectoryPath(string $path): void
    {
        self::assertDestination($path . '/.directory-check');
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new \RuntimeException('目标不是普通目录：' . $path);
        }
    }

    private static function ensureDirectory(string $dir, ?string $root = null): void
    {
        $root = $root === null ? ROOT : $root;
        $realRoot = realpath($root);
        if ($realRoot === false) throw new \RuntimeException('目标根目录不存在：' . $root);
        $dir = str_replace('\\', '/', $dir);
        $realBase = rtrim($realRoot, '/');
        if ($dir === $realBase) return;
        $prefix = $realBase . '/';
        if (strpos($dir, $prefix) !== 0) throw new \RuntimeException('目录路径越界：' . $dir);
        $relative = substr($dir, strlen($prefix));
        if ($relative === '') return;
        $current = rtrim($realRoot, '/');
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new \RuntimeException('目录路径非法：' . $dir);
            $current .= '/' . $part;
            if (is_link($current)) throw new \RuntimeException('目录路径不得包含符号链接：' . $current);
            if (file_exists($current)) {
                if (!is_dir($current)) throw new \RuntimeException('目录路径被文件占用：' . $current);
            } elseif (!mkdir($current, 0755) || !is_dir($current)) {
                throw new \RuntimeException('创建目录失败：' . $current);
            }
        }
    }

    private static function removeEmptyCreatedDirs(array $created, array &$errors): void
    {
        $dirs = [];
        foreach ($created as $file) {
            $dir = dirname($file);
            while ($dir !== ROOT && strpos($dir, ROOT . '/') === 0) {
                $dirs[$dir] = true;
                $dir = dirname($dir);
            }
        }
        krsort($dirs);
        foreach ($dirs as $dir => $_) {
            if (!is_dir($dir) || is_link($dir)) continue;
            $items = scandir($dir);
            if ($items === false || count($items) !== 2) continue;
            try {
                self::guarded(static function () use ($dir): void {
                    if (!rmdir($dir)) throw new \RuntimeException('清理升级空目录失败：' . $dir);
                });
            } catch (\Throwable $t) {
                $errors[] = $t->getMessage();
            }
        }
    }

    private static function removeFile(string $path): array
    {
        try {
            return self::guarded(static function () use ($path): array {
                if (!file_exists($path) && !is_link($path)) return [true, ''];
                if (is_dir($path) && !is_link($path)) return [false, '目标不是文件：' . $path];
                if (!unlink($path)) return [false, '删除文件失败：' . $path];
                return [true, ''];
            });
        } catch (\Throwable $t) {
            return [false, $t->getMessage()];
        }
    }

    private static function removeTree(string $path): array
    {
        try {
            return self::guarded(static function () use ($path): array {
                if (is_link($path)) {
                    if (!unlink($path)) throw new \RuntimeException('清理暂存符号链接失败：' . $path);
                    return [true, ''];
                }
                if (!file_exists($path)) return [true, ''];
                if (!is_dir($path)) {
                    if (!unlink($path)) throw new \RuntimeException('清理暂存文件失败：' . $path);
                    return [true, ''];
                }
                $items = scandir($path);
                if ($items === false) throw new \RuntimeException('读取暂存目录失败：' . $path);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    [, $error] = self::removeTree($path . '/' . $item);
                    if ($error !== '') throw new \RuntimeException($error);
                }
                if (!rmdir($path)) throw new \RuntimeException('清理暂存目录失败：' . $path);
                return [true, ''];
            });
        } catch (\Throwable $t) {
            return [false, $t->getMessage()];
        }
    }

    private static function recordHistory(string $from, string $to, string $zipPath, string $backupFile, string $result, string $detail): string
    {
        self::$lastHistoryId = 0;
        try {
            self::$lastHistoryId = Database::insert('upgrade_history', [
                'from_version' => $from,
                'to_version' => $to,
                'package_file' => basename($zipPath),
                'backup_file' => basename($backupFile),
                'result' => $result,
                'detail' => str_take($detail, 1000, ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return '';
        } catch (\Throwable $t) {
            $error = '升级历史记录失败：' . $t->getMessage();
            Logger::error('upgrade', $error);
            return $error;
        }
    }

    private static function guarded(callable $callback)
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
