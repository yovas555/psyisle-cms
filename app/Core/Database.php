<?php
declare(strict_types=1);

namespace app\Core;

use PDO;
use PDOException;

/**
 * 数据库访问层（v2.18 专业内核强化）
 * ------------------------------------------------------------------
 * v2.18 新增：
 *  - transaction(callable)：事务助手（异常自动回滚，闭包返回值透传）
 *  - 死锁/锁等待自动重试（SQLSTATE 1213/1205，3 次指数退避）
 *  - 断线自动重连（2006 MySQL server has gone away，单次重连重试——cron 长进程跨夜必备）
 *  - 慢查询观测（>200ms 落 warning 日志，供后台健康卡统计）
 */
class Database
{
    private static ?PDO $pdo = null;
    private static int $queryCount = 0;
    public const SLOW_MS = 200;
    private const RETRYABLE = [1213, 1205]; // 死锁 / 锁等待超时
    private const GONE = [2006, 2013];      // server has gone away / connection lost

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;
        return self::connect();
    }

    private static function connect(): PDO
    {
        $cfg = CONFIG['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? '3306',
            $cfg['name'] ?? 'psyisle'
        );
        try {
            self::$pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            Logger::error('DB', $e->getMessage());
            throw new \RuntimeException('数据库连接失败，请检查 config/config.php');
        }
        return self::$pdo;
    }

    /** 事务助手：闭包内抛任意异常自动回滚并向上抛；成功返回闭包返回值 */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $t) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $t;
        }
    }

    /** 预处理执行（含死锁重试/断线重连/慢查询观测） */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        self::$queryCount++;
        $t0 = microtime(true);
        $attempt = 0;
        retry:
        try {
            $st = self::pdo()->prepare($sql);
            $st->execute($params);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($ms >= self::SLOW_MS) {
                Logger::warning('DB', sprintf('slow query %dms', $ms), [
                    'sql' => str_take(preg_replace('/\s+/', ' ', $sql), 160, ''),
                    'params' => str_take(json_encode($params, JSON_UNESCAPED_UNICODE), 160, ''),
                ]);
            }
            return $st;
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            $driverCode = (int)($e->getCode() ?? 0);
            // 死锁/锁等待：最多重试 3 次（指数退避 10/50/250ms）
            if (in_array($code, self::RETRYABLE, true) && $attempt < 3) {
                $attempt++;
                usleep((int)(10 * 5 ** ($attempt - 1) * 1000)); // 10ms / 50ms / 250ms
                goto retry;
            }
            // 断线：重连一次再试（cron 长进程跨夜场景）
            if ((in_array($driverCode, self::GONE, true) || in_array($code, self::GONE, true)) && self::$pdo !== null) {
                self::$pdo = null;
                try { self::connect(); goto retry; } catch (\Throwable $t2) { /* 重连失败走原错误 */ }
            }
            throw $e;
        }
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`,`', $cols) . '`',
            implode(',', $ph)
        );
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = '`' . $col . '` = :' . $col;
            $params[':' . $col] = $val;
        }
        $i = 0;
        $whereSql = preg_replace_callback('/\?/', function () use (&$i) { return ':w' . ($i++); }, $where);
        foreach ($whereParams as $k => $v) { $params['w' . $k] = $v; }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(',', $sets), $whereSql);
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function delete(string $table, string $where, array $whereParams = []): int
    {
        $i = 0;
        $whereSql = preg_replace_callback('/\?/', function () use (&$i) { return ':w' . ($i++); }, $where);
        $params = [];
        foreach ($whereParams as $k => $v) { $params['w' . $k] = $v; }
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $whereSql);
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int)self::value("SELECT COUNT(*) FROM `$table` WHERE $where", $params);
    }
}
