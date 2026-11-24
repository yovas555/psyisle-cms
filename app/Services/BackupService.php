<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Logger;

/**
 * 备份服务（v2.22.2）
 * ------------------------------------------------------------------
 * 把原先写死在后台「工具」页的备份逻辑抽出来，供两个入口共用：
 *  * 管理台按钮：`Admin\Tool::backup()` / `fullBackup()`
 *  * 计划任务：`GET /admin/cron/backup`（cron_secret 鉴权，无需登录）
 *
 * 这样「定时备份」不必依赖有人在后台点按钮；客户站只要在服务器
 * crontab 里加一行 curl，就能每天自动产出加密备份，再配合异地副本。
 *
 * 硬口径（与 v2.21.0/v2.22.1 保持一致，不得回退）：
 *  * 落盘前一律加密（BackupCrypto），文件名 *.enc，权限 0600；
 *  * 压缩只做一层：dump 出纯 SQL → gzip → 加密；
 *  * 不依赖外部命令：exec 可用走 mysqldump，否则走 PhpDbDumper；
 *  * 30 天滚动清理，只清本模块产物，不动 Updater 的 upgrade-backup-*.zip。
 */
class BackupService
{
    /**
     * 数据库备份：dump → gzip → 加密
     * @return array{ok:bool,msg:string,file:string,bytes:int}
     */
    public static function database(): array
    {
        self::ensureDir();
        $base = STORAGE . '/backups/db-' . date('Ymd-His');
        $sql  = $base . '.sql';
        $gz   = $base . '.sql.gz';
        $enc  = $base . '.sql.gz.enc';

        if (!self::dumpSql($sql)) {
            @unlink($sql);
            return self::fail('数据库导出失败（mysqldump 与内置导出均未成功，检查 storage/backups 写入权限）');
        }
        if (!self::gzip($sql, $gz)) {
            @unlink($sql);
            Logger::error('backup', 'gzip failed (exec=' . (PhpDbDumper::canExec() ? 'on' : 'off') . ')');
            return self::fail('数据库压缩失败（检查 storage/backups 写入权限与 zlib 扩展）');
        }
        @unlink($sql);
        if (!BackupCrypto::encryptFile($gz, $enc)) {
            @unlink($gz);
            @unlink($enc);
            return self::fail('备份加密失败（检查 openssl 扩展与目录写权限）');
        }
        @unlink($gz);
        clearstatcache(true, $enc);
        self::purgeOld();
        $bytes = (int)@filesize($enc);
        return ['ok' => true, 'msg' => '数据库备份完成（已加密）：' . basename($enc), 'file' => $enc, 'bytes' => $bytes];
    }

    /**
     * 全量备份：数据库 + uploads → zip → 加密
     * @return array{ok:bool,msg:string,file:string,bytes:int}
     */
    public static function full(): array
    {
        if (!class_exists('ZipArchive')) {
            return self::fail('服务器未安装 PHP zip 扩展');
        }
        self::ensureDir();
        $base = STORAGE . '/backups/full-' . date('Ymd-His');
        $sqlFile = $base . '.sql';
        if (!self::dumpSql($sqlFile)) {
            @unlink($sqlFile);
            return self::fail('数据库导出失败（mysqldump 与内置导出均未成功，检查 storage/backups 写入权限）');
        }
        $zipFile = $base . '.zip';
        $encFile = $zipFile . '.enc';
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlFile);
            return self::fail('无法创建备份压缩包（目录权限？）');
        }
        $zip->addFile($sqlFile, 'database.sql');
        // uploads（排除 PHP 类文件以防万一）
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(UPLOAD_PATH, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (preg_match('/\.(php|phtml|php\d)$/i', $f->getExtension())) continue;
            $zip->addFile($f->getPathname(), 'uploads/' . substr($f->getPathname(), strlen(UPLOAD_PATH) + 1));
        }
        $zip->close();
        @unlink($sqlFile);
        if (!is_file($zipFile) || !BackupCrypto::encryptFile($zipFile, $encFile)) {
            @unlink($zipFile);
            @unlink($encFile);
            return self::fail('全量备份加密失败（检查 openssl 扩展）');
        }
        @unlink($zipFile);
        clearstatcache(true, $encFile);
        self::purgeOld();
        $bytes = (int)@filesize($encFile);
        return ['ok' => true, 'msg' => '全量备份完成（已加密）：' . basename($encFile), 'file' => $encFile, 'bytes' => $bytes];
    }

    /** 30 天滚动清理：只碰本模块产出的备份，不动 Updater 的 upgrade-backup-*.zip */
    public static function purgeOld(): int
    {
        $pats = ['db-*.sql.gz.enc', 'full-*.zip.enc', 'db-*.sql.gz', 'full-*.zip', 'backup-*.sql.gz'];
        $n = 0;
        foreach ($pats as $pat) {
            foreach (glob(STORAGE . '/backups/' . $pat) ?: [] as $f) {
                if (filemtime($f) < strtotime('-30 days')) { @unlink($f); $n++; }
            }
        }
        return $n;
    }

    private static function ensureDir(): void
    {
        $dir = STORAGE . '/backups';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg, 'file' => '', 'bytes' => 0];
    }

    /**
     * 导出纯 SQL 到指定文件（不压缩，压缩由调用方做一次）
     * 凭据走临时 defaults-extra-file（0600，跑完即删），不把密码拼进命令行。
     */
    private static function dumpSql(string $dest): bool
    {
        // 宝塔默认 hardening 会禁用 exec/shell_exec，客户站后台备份不能因此瘫掉
        if (!PhpDbDumper::canExec()) {
            $r = PhpDbDumper::dumpPlain($dest);
            if (!$r['ok']) {
                Logger::error('backup', 'php dump fallback failed: ' . $r['msg']);
            }
            return $r['ok'];
        }
        $db = CONFIG['db'];
        $cnfFile = STORAGE . '/backups/.my-' . bin2hex(random_bytes(6)) . '.cnf';
        $q = static function (string $v): string {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        };
        $cnf = "[client]\n"
            . 'user=' . $q((string)($db['user'] ?? 'root')) . "\n"
            . 'password=' . $q((string)($db['pass'] ?? '')) . "\n"
            . 'host=' . $q((string)($db['host'] ?? '127.0.0.1')) . "\n"
            . 'port=' . (int)($db['port'] ?? 3306) . "\n";
        if (@file_put_contents($cnfFile, $cnf) === false) {
            return false;
        }
        @chmod($cnfFile, 0600);
        $cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnfFile)
            . ' --single-transaction --quick --default-character-set=utf8mb4 '
            . escapeshellarg((string)($db['name'] ?? 'psyisle'))
            . ' > ' . escapeshellarg($dest) . ' 2>/dev/null';
        @exec($cmd, $out, $code);
        @unlink($cnfFile);
        if (is_file($dest) && filesize($dest) > 100) {
            return true;
        }
        // mysqldump 不存在或被策略拦截时，退回纯 PHP 导出（纯 SQL，压缩交给上层只做一次）
        @unlink($dest);
        $r = PhpDbDumper::dumpPlain($dest);
        if (!$r['ok']) {
            Logger::error('backup', 'mysqldump failed and php dump fallback failed: ' . $r['msg']);
        }
        return $r['ok'];
    }

    /** 压缩：exec 可用用 gzip 命令，否则用 PHP 流（宝塔禁用 exec 时） */
    private static function gzip(string $src, string $dstGz): bool
    {
        if (PhpDbDumper::canExec()) {
            @exec('gzip -9 -c ' . escapeshellarg($src) . ' > ' . escapeshellarg($dstGz));
        }
        clearstatcache(true, $dstGz);
        if (is_file($dstGz) && (int)filesize($dstGz) >= 100) {
            return true;
        }
        @unlink($dstGz);
        return PhpDbDumper::gzipFile($src, $dstGz);
    }
}
