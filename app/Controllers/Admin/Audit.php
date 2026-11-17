<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;

class Audit extends Base
{
    protected string $permNode = 'audits';
    protected string $title = '操作审计';

    /** 校验审计链：返回 [状态, 断链位置] */
    private function verifyChain(int $limit): array
    {
        try {
            $rows = Database::fetchAll('SELECT id, prev_hash, row_hash, action, username, target_type, target_id, detail, ip, created_at FROM audit_logs ORDER BY id DESC LIMIT ' . (int)$limit);
        } catch (\Throwable $t) {
            return ['unknown', 0];
        }
        if (!$rows) return ['empty', 0];
        $rows = array_reverse($rows);
        $prev = null;
        foreach ($rows as $r) {
            if ($r['row_hash'] === null) { $prev = null; continue; } // 旧数据（升级前）跳过
            if ($prev !== null && (string)$r['prev_hash'] !== $prev) {
                return ['broken', (int)$r['id']];
            }
            $payload = ($r['prev_hash'] ?? '') . '|' . $r['action'] . '|' . $r['username'] . '|' . $r['target_type'] . '|'
                . $r['target_id'] . '|' . ($r['detail'] ?? '') . '|' . ($r['ip'] ?? '') . '|' . $r['created_at'];
            if (!hash_equals(hash('sha256', $payload), (string)$r['row_hash'])) {
                return ['tampered', (int)$r['id']];
            }
            $prev = (string)$r['row_hash'];
        }
        return ['ok', 0];
    }

    public function index(Request $req, array $p): void
    {
        $page = max(1, (int)$req->get('page', 1));
        $kw = trim((string)$req->get('q', ''));
        $where = '1=1';
        $params = [];
        if ($kw !== '') {
            $where .= ' AND (username LIKE ? OR action LIKE ?)';
            $params = ["%$kw%", "%$kw%"];
        }
        $size = 30;
        $offset = ($page - 1) * $size;
        $total = Database::count('audit_logs', $where, $params);
        $rows = Database::fetchAll(
            "SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT $size OFFSET $offset",
            $params
        );
        // 链式哈希完整性校验（抽样最近 500 条）
        $chain = $this->verifyChain(500);
        $this->view('audit/index', [
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)), 'kw' => $kw,
            'chain' => $chain,
            'title' => $this->title,
        ]);
    }
}
