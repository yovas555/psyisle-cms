<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * 轻量迁移器（v2.22.0 起，D2 决策落地）
 * ------------------------------------------------------------------
 * 设计目标：不引入外部依赖，随包分发，安装与升级两条路径都能用。
 *
 * 约定：
 *  - 迁移文件：database/migrations/*.sql，文件名即版本号（按字典序执行）
 *  - 记录表：schema_migrations（version / checksum / applied_at）
 *  - 全部幂等：只用 CREATE TABLE IF NOT EXISTS、information_schema 判存后再 ALTER，
 *    这样「安装向导导入 schema.sql」与「老站跑迁移」两条路径结果一致。
 *  - 失败即停：DDL 不能回滚，出错必须先人工修，不能带着半截结构继续。
 *  - 并发安全：GET_LOCK 串行；多请求同时命中只会有一个真正执行。
 *
 * 性能：每次请求 1 条 SELECT（无待执行版本就到此为止），不写缓存文件，
 *      避免「库被还原成旧备份但代码更新」时误判已迁完。
 */
class Migrator
{
    public const TABLE = 'schema_migrations';
    private const LOCK_NAME = 'psyisle_migrator';
    private const LOCK_TIMEOUT = 8;

    private static bool $done = false;

    public static function dir(): string
    {
        return ROOT . '/database/migrations';
    }

    /**
     * 执行所有未应用的迁移
     *
     * @return array<int,array{version:string,status:string,note:string}>
     */
    public static function run(bool $force = false): array
    {
        if (self::$done && !$force) return [];
        self::$done = true;

        $files = self::files();
        if (!$files) return [];

        try {
            $applied = self::applied();
        } catch (\Throwable $t) {
            // 表不存在（老站首次升级）或无权限：建表后按全未应用处理
            try {
                self::createTable();
                $applied = [];
            } catch (\Throwable $t2) {
                Logger::error('MIGRATOR', 'init failed: ' . $t2->getMessage());
                return [['version' => '-', 'status' => 'failed', 'note' => $t2->getMessage()]];
            }
        }

        $out = [];
        $pending = [];
        foreach ($files as $f) {
            $v = basename($f, '.sql');
            $sum = self::checksum($f);
            if (isset($applied[$v])) {
                if ($applied[$v] !== $sum) {
                    // 已执行的迁移被人改过：不重跑（可能已产生副作用），只告警
                    Logger::warning('MIGRATOR', 'checksum changed (not re-run): ' . $v);
                    $out[] = ['version' => $v, 'status' => 'skipped', 'note' => '文件内容已变更，未重跑'];
                }
                continue;
            }
            $pending[] = [$v, $f, $sum];
        }
        if (!$pending) return $out;

        if (!self::lock()) {
            Logger::warning('MIGRATOR', 'lock busy, skip this round');
            return [['version' => '-', 'status' => 'skipped', 'note' => '迁移锁被占用，本轮跳过']];
        }
        try {
            // 拿到锁后重读已执行列表：并发请求可能在我们排队期间已经跑完了
            try { $appliedNow = self::applied(); } catch (\Throwable $t) { $appliedNow = []; }
            foreach ($pending as [$v, $f, $sum]) {
                if (isset($appliedNow[$v])) continue;
                try {
                    $n = self::applyFile($f);
                    Database::insert(self::TABLE, [
                        'version' => $v,
                        'checksum' => $sum,
                        'applied_at' => date('Y-m-d H:i:s'),
                    ]);
                    Logger::warning('MIGRATOR', 'applied ' . $v . ' (' . $n . ' stmts)');
                    $out[] = ['version' => $v, 'status' => 'applied', 'note' => $n . ' 条语句'];
                } catch (\Throwable $t) {
                    // 唯一键冲突 = 另一个进程刚写入了同版本，视为已应用（幂等）
                    $dup = $t instanceof \PDOException && (int)($t->errorInfo[1] ?? 0) === 1062;
                    if ($dup) {
                        Logger::warning('MIGRATOR', 'concurrent apply detected, treat as applied: ' . $v);
                        $out[] = ['version' => $v, 'status' => 'applied', 'note' => '并发下由其他进程完成'];
                        continue;
                    }
                    Logger::error('MIGRATOR', 'failed ' . $v . ': ' . $t->getMessage());
                    $out[] = ['version' => $v, 'status' => 'failed', 'note' => $t->getMessage()];
                    break; // 失败即停：后续迁移可能依赖本步结构
                }
            }
        } finally {
            self::unlock();
        }
        return $out;
    }

    /** 迁移状态（供 CLI 与后台诊断） */
    public static function status(): array
    {
        $applied = [];
        try {
            foreach (Database::fetchAll('SELECT version, checksum, applied_at FROM `' . self::TABLE . '`') as $r) {
                $applied[(string)$r['version']] = [
                    'checksum' => (string)$r['checksum'],
                    'applied_at' => (string)($r['applied_at'] ?? ''),
                ];
            }
        } catch (\Throwable $t) {
            // 表还没建：全部按未应用
        }
        $rows = [];
        foreach (self::files() as $f) {
            $v = basename($f, '.sql');
            $rows[] = [
                'version' => $v,
                'state' => isset($applied[$v]) ? 'applied' : 'pending',
                'checksum' => substr(self::checksum($f), 0, 12),
                'applied_at' => $applied[$v]['applied_at'] ?? null,
            ];
        }
        return $rows;
    }

    // ==================== 内部 ====================

    /** @return string[] 绝对路径，已按版本排序 */
    private static function files(): array
    {
        $files = glob(self::dir() . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string,string> version => checksum */
    private static function applied(): array
    {
        $rows = Database::fetchAll('SELECT version, checksum FROM `' . self::TABLE . '`');
        $map = [];
        foreach ($rows as $r) $map[(string)$r['version']] = (string)$r['checksum'];
        return $map;
    }

    private static function createTable(): void
    {
        Database::run(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `version` varchar(80) NOT NULL COMMENT "迁移版本（文件名）",
                `checksum` char(64) NOT NULL DEFAULT "" COMMENT "文件 sha256，防止改文件重跑",
                `applied_at` datetime NULL,
                PRIMARY KEY (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function checksum(string $file): string
    {
        $sum = @hash_file('sha256', $file);
        return $sum === false ? '' : $sum;
    }

    private static function lock(): bool
    {
        try {
            return (int)Database::value('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, self::LOCK_TIMEOUT]) === 1;
        } catch (\Throwable $t) {
            return false;
        }
    }

    private static function unlock(): void
    {
        try { Database::run('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]); } catch (\Throwable $t) {}
    }

    /** 逐条执行文件内语句，返回语句数 */
    private static function applyFile(string $file): int
    {
        $sql = (string)@file_get_contents($file);
        if (trim($sql) === '') return 0;
        $n = 0;
        foreach (self::split($sql) as $stmt) {
            Database::run($stmt);
            $n++;
        }
        return $n;
    }

    /**
     * SQL 语句切分（只处理本项目会用到的语法：行注释、块注释、三种引号、转义）
     * 不依赖 mysqli 的多语句执行，避免 PDO 模拟预处理带来的注入面。
     *
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        $quote = '';
        while ($i < $len) {
            $ch = $sql[$i];
            if ($quote !== '') {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== '`') {
                    if ($i + 1 < $len) { $buf .= $sql[$i + 1]; $i += 2; continue; }
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) { // '' / "" / `` 转义
                        $buf .= $quote;
                        $i += 2;
                        continue;
                    }
                    $quote = '';
                }
                $i++;
                continue;
            }
            if (($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') || $ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") $i++;
                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) $i++;
                $i += 2;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf .= $ch;
                $i++;
                continue;
            }
            if ($ch === ';') {
                $t = trim($buf);
                if ($t !== '') $out[] = $t;
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $ch;
            $i++;
        }
        $t = trim($buf);
        if ($t !== '') $out[] = $t;
        return $out;
    }
}
