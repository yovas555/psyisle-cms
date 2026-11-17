<?php
declare(strict_types=1);

namespace app\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;
    private static int $queryCount = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;
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

    /** 预处理执行 */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        self::$queryCount++;
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
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
