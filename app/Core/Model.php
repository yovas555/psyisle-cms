<?php
declare(strict_types=1);

namespace app\Core;

class Model
{
    /** settings 全量（key=group.key） */
    public static function loadSettings(): array
    {
        $rows = Database::fetchAll('SELECT `skey`,`svalue` FROM `settings`');
        $out = [];
        foreach ($rows as $r) {
            $v = $r['svalue'];
            // 敏感配置密文自动解密（enc: 前缀）
            if (Crypto::isEncrypted($v)) { $v = Crypto::decrypt((string)$v); }
            $out[$r['skey']] = $v;
        }
        return $out;
    }

    public static function saveSetting(string $key, $value, string $group = 'general'): void
    {
        Database::run(
            'INSERT INTO `settings` (`skey`,`svalue`,`sgroup`,`updated_at`) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE `svalue`=VALUES(`svalue`), `sgroup`=VALUES(`sgroup`), `updated_at`=VALUES(`updated_at`)',
            [$key, (string)$value, $group, date('Y-m-d H:i:s')]
        );
    }

    /** 通用分页取数 */
    public static function paginate(string $table, array $opts = []): array
    {
        $page = max(1, (int)($opts['page'] ?? 1));
        $size = min(100, max(1, (int)($opts['size'] ?? 15)));
        $where = $opts['where'] ?? '1=1';
        $params = $opts['params'] ?? [];
        $order = $opts['order'] ?? 'id DESC';
        $offset = ($page - 1) * $size;
        $total = Database::count($table, $where, $params);
        $rows = Database::fetchAll(
            "SELECT * FROM `$table` WHERE $where ORDER BY $order LIMIT $size OFFSET $offset",
            $params
        );
        $pages = max(1, (int)ceil($total / $size));
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'size' => $size];
    }
}
