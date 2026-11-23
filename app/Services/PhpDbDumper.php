<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * 纯 PHP 数据库导出（外部命令不可用时的兜底）
 * ------------------------------------------------------------------
 * 背景：宝塔默认 hardening 会在 php.ini 里 disable_functions 掉
 * exec/shell_exec/proc_open/popen/system，客户站后台的「数据库备份」因此
 * 直接报 "Call to undefined function exec()"。备份是数据安全底线，
 * 不能依赖宿主环境是否允许执行外部命令。
 *
 * 做法：用 PDO 读 SHOW TABLES / SHOW CREATE TABLE / SELECT，按主键分页
 * 写成 INSERT，边读边写，不占内存。
 *
 * 口径：
 *  * dumpPlain() 写纯 SQL（交给调用方去 gzip + 加密）；dump() 直接写 .sql.gz。
 *    —— 压缩只能做一次，否则得到「双层 gz」，恢复时必须解两遍。
 *  * 只导出表，不导出视图/存储过程/触发器（本系统不使用）。
 *  * 先 SET FOREIGN_KEY_CHECKS=0，导完恢复，避免导入顺序问题。
 *  * 二进制/非 UTF-8 值走 0xHEX，避免转义损坏。
 *  * 任何表读失败即整体失败（宁可报错，也不产出"看起来成功"的残缺备份）。
 */
class PhpDbDumper
{
    private const CHUNK = 500;

    /** 外部命令不可用时才用本兜底 */
    public static function canExec(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /**
     * 导出为 .sql.gz
     * @return array{ok:bool,msg:string,tables:int,rows:int,bytes:int}
     */
    public static function dump(string $destGz): array
    {
        return self::export($destGz, true);
    }

    /**
     * 导出为纯 SQL（供上层自行压缩/加密）
     * @return array{ok:bool,msg:string,tables:int,rows:int,bytes:int}
     */
    public static function dumpPlain(string $destSql): array
    {
        return self::export($destSql, false);
    }

    /**
     * 用 PHP 流把普通文件压成 .gz（替代 `exec gzip`，宝塔禁用 exec 时唯一可用路径）
     */
    public static function gzipFile(string $src, string $dstGz): bool
    {
        if (!function_exists('gzopen') || !is_file($src)) {
            return false;
        }
        $in = @fopen($src, 'rb');
        if (!$in) return false;
        $out = @gzopen($dstGz, 'wb9');
        if (!$out) {
            fclose($in);
            return false;
        }
        $ok = true;
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false) { $ok = false; break; }
            if ($buf === '') continue;
            if (gzwrite($out, $buf) === false) { $ok = false; break; }
        }
        fclose($in);
        gzclose($out);
        clearstatcache(true, $dstGz);
        if (!$ok || !is_file($dstGz) || (int)@filesize($dstGz) < 100) {
            @unlink($dstGz);
            return false;
        }
        @chmod($dstGz, 0600);
        return true;
    }

    /**
     * @return array{ok:bool,msg:string,tables:int,rows:int,bytes:int}
     */
    private static function export(string $dest, bool $gzip): array
    {
        $fail = static function (string $msg, int $t = 0, int $r = 0): array {
            return ['ok' => false, 'msg' => $msg, 'tables' => $t, 'rows' => $r, 'bytes' => 0];
        };

        if ($gzip && !function_exists('gzopen')) {
            return $fail('缺少 zlib 扩展，无法压缩备份');
        }

        $fh = $gzip ? @gzopen($dest, 'wb9') : @fopen($dest, 'wb');
        if (!$fh) {
            return $fail('无法写入备份文件（检查 storage/backups 权限）');
        }
        $close = static function () use ($fh, $gzip): void {
            if ($gzip) { @gzclose($fh); } else { @fclose($fh); }
        };
        $w = static function (string $s) use ($fh, $gzip): void {
            if ($gzip) { gzwrite($fh, $s); } else { fwrite($fh, $s); }
        };

        $pdo = Database::pdo();
        $dbName = (string)(CONFIG['db']['name'] ?? '');
        $tables = 0;
        $rows = 0;

        try {
            $w("-- PsyIsle 数据库备份（纯 PHP 导出）\n");
            $w('-- 数据库：' . $dbName . ' · 生成时间：' . date('Y-m-d H:i:s') . ' · 系统版本：' . (defined('CMS_VERSION') ? CMS_VERSION : '-') . "\n");
            $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $list = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($list as $t) {
                $t = (string)$t;
                $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(\PDO::FETCH_ASSOC);
                $ddl = (string)($create['Create Table'] ?? '');
                if ($ddl === '') {
                    throw new \RuntimeException('读取表结构失败：' . $t);
                }
                $w("-- ----------------------------\n-- Table `$t`\n-- ----------------------------\n");
                $w("DROP TABLE IF EXISTS `$t`;\n" . $ddl . ";\n\n");
                $tables++;

                $pk = self::pkColumn($pdo, $t);
                $offset = 0;
                $last = null;
                while (true) {
                    if ($pk !== null) {
                        $sql = 'SELECT * FROM `' . $t . '`' . ($last === null ? '' : ' WHERE `' . $pk . '` > ' . (int)$last)
                            . ' ORDER BY `' . $pk . '` LIMIT ' . self::CHUNK;
                    } else {
                        $sql = 'SELECT * FROM `' . $t . '` LIMIT ' . self::CHUNK . ' OFFSET ' . $offset;
                    }
                    $rowsChunk = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
                    if (!$rowsChunk) break;

                    $cols = array_keys($rowsChunk[0]);
                    $colSql = '`' . implode('`,`', $cols) . '`';
                    foreach (array_chunk($rowsChunk, 200) as $part) {
                        $vals = [];
                        foreach ($part as $row) {
                            $vals[] = '(' . implode(',', array_map(static function ($v) use ($pdo) {
                                if ($v === null) return 'NULL';
                                if (is_int($v)) return (string)$v;
                                if (is_float($v)) return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
                                $s = (string)$v;
                                // 二进制/非 UTF-8 用十六进制，避免转义或字符集丢字节
                                return preg_match('//u', $s) === 1 ? $pdo->quote($s) : '0x' . bin2hex($s);
                            }, array_values($row))) . ')';
                        }
                        $w('INSERT INTO `' . $t . '` (' . $colSql . ") VALUES\n" . implode(",\n", $vals) . ";\n");
                        $rows += count($part);
                    }
                    if ($pk !== null) {
                        $lastRow = end($rowsChunk);
                        $last = $lastRow[$pk] ?? null;
                        if ($last === null) break;
                    } else {
                        $offset += self::CHUNK;
                    }
                    if (count($rowsChunk) < self::CHUNK) break;
                }
                $w("\n");
            }

            $w("SET FOREIGN_KEY_CHECKS=1;\n-- 导出完成：{$tables} 张表 / {$rows} 行\n");
        } catch (\Throwable $e) {
            $close();
            @unlink($dest);
            Logger::error('backup', 'php dump failed: ' . $e->getMessage());
            return $fail('导出失败：' . $e->getMessage(), $tables, $rows);
        }
        $close();

        clearstatcache(true, $dest);
        $bytes = (int)@filesize($dest);
        if ($bytes < 100) {
            @unlink($dest);
            return $fail('备份文件异常（过小）', $tables, $rows);
        }
        @chmod($dest, 0600);
        return ['ok' => true, 'msg' => "已导出 {$tables} 张表 / {$rows} 行", 'tables' => $tables, 'rows' => $rows, 'bytes' => $bytes];
    }

    /** 单列整型主键才用 keyset 分页；复合/缺失主键退回 OFFSET */
    private static function pkColumn(\PDO $pdo, string $table): ?string
    {
        try {
            $rows = $pdo->query('SHOW KEYS FROM `' . str_replace('`', '``', $table) . "` WHERE Key_name='PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (count($rows) !== 1) return null;
        $col = (string)$rows[0]['Column_name'];
        try {
            $c = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . "` LIKE " . $pdo->quote($col))->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        $type = strtolower((string)($c['Type'] ?? ''));
        return str_starts_with($type, 'int') || str_starts_with($type, 'bigint') || str_starts_with($type, 'smallint') || str_starts_with($type, 'tinyint')
            ? $col : null;
    }
}
