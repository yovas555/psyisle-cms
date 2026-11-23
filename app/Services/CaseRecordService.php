<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;
use app\Core\Purifier;

/**
 * 结构化咨询记录（P1-B）
 * ------------------------------------------------------------------
 * 与 P1-A 的边界：个案/会次管"有没有做"，这里管"做了什么"。
 *
 * 三条硬规则：
 *  1) **锁定不可改**：status=locked 后任何写入被拒，只能另建「修订记录」（amends_id 指向原记录）。
 *  2) **版本只增不改**：每次保存/锁定都往 case_record_versions 追加一条快照，用于追溯"当时写的什么"。
 *  3) **默认不对咨客可见**：visible 默认 0，必须显式勾选（咨询记录不是科普文章）。
 */
class CaseRecordService
{
    /** 新建或更新草稿 */
    public static function save(int $caseId, int $counselorId, array $in, int $recordId = 0, int $editorId = 0): array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return self::fail('个案不存在');
        if (!CasePolicy::owns($case, $counselorId)) {
            Logger::error('case', 'denied record write counselor=' . $counselorId . ' case=' . $caseId);
            return self::fail('无权操作该个案');
        }
        if (!CasePolicy::canWrite($case, $counselorId)) return self::fail('个案已结案，不能新增或修改记录');

        $title = str_take(trim((string)($in['title'] ?? '')), 120, '');
        $content = Purifier::clean((string)($in['content'] ?? ''));
        if ($title === '') return self::fail('请填写记录标题');
        if (trim(strip_tags($content)) === '') return self::fail('记录正文不能为空');
        $type = isset(CasePolicy::RECORD_TYPE[(string)($in['record_type'] ?? '')]) ? (string)$in['record_type'] : 'summary';
        $sessionId = (int)($in['session_id'] ?? 0) ?: null;
        if ($sessionId !== null && !Database::value('SELECT id FROM case_sessions WHERE id=? AND case_id=?', [$sessionId, $caseId])) {
            $sessionId = null;
        }
        $visible = !empty($in['visible']) ? 1 : 0;

        try {
            if ($recordId > 0) {
                $rec = Database::fetch('SELECT * FROM case_records WHERE id=? AND case_id=?', [$recordId, $caseId]);
                if (!$rec) return self::fail('记录不存在');
                if (!CasePolicy::canEditRecord($rec, $counselorId)) return self::fail('记录已锁定，如需修改请另开修订记录');

                $ver = (int)$rec['version'] + 1;
                Database::transaction(function () use ($recordId, $title, $content, $type, $sessionId, $visible, $ver, $editorId) {
                    Database::run('SELECT id FROM case_records WHERE id=? FOR UPDATE', [$recordId]);
                    Database::run(
                        'UPDATE case_records SET title=?, content=?, record_type=?, session_id=?, visible=?, version=?, updated_at=NOW() WHERE id=?',
                        [$title, $content, $type, $sessionId, $visible, $ver, $recordId]
                    );
                    self::snapshot($recordId, $ver, $title, $content, 'draft', $editorId);
                });
                return ['ok' => true, 'id' => $recordId, 'version' => $ver, 'msg' => '记录已保存（第 ' . $ver . ' 版）'];
            }

            $id = Database::transaction(function () use ($caseId, $counselorId, $case, $title, $content, $type, $sessionId, $visible, $editorId, $in) {
                $newId = Database::insert('case_records', [
                    'case_id' => $caseId,
                    'session_id' => $sessionId,
                    'counselor_id' => $counselorId,
                    'member_id' => (int)$case['member_id'],
                    'record_type' => $type,
                    'title' => $title,
                    'content' => $content,
                    'version' => 1,
                    'status' => 'draft',
                    'visible' => $visible,
                    'amends_id' => (int)($in['amends_id'] ?? 0) ?: null,
                    'created_by' => $editorId ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                self::snapshot($newId, 1, $title, $content, 'draft', $editorId);
                return $newId;
            });
            return ['ok' => true, 'id' => $id, 'version' => 1, 'msg' => '记录已创建（草稿）'];
        } catch (\Throwable $t) {
            Logger::error('case', 'record save failed: ' . $t->getMessage());
            return self::fail('保存失败，请稍后重试');
        }
    }

    /** 锁定（签字）：草稿 → 不可改 */
    public static function lock(int $recordId, int $counselorId, int $editorId = 0): array
    {
        $rec = Database::fetch('SELECT * FROM case_records WHERE id=?', [$recordId]);
        if (!$rec) return self::fail('记录不存在');
        if (!CasePolicy::canReadRecord($rec, $counselorId)) return self::fail('无权操作该记录');
        if ((string)$rec['status'] === 'locked') return self::fail('该记录已锁定');
        if (trim(strip_tags((string)$rec['content'])) === '') return self::fail('正文为空，不能锁定');

        try {
            Database::transaction(function () use ($recordId, $rec, $editorId) {
                Database::run('SELECT id FROM case_records WHERE id=? FOR UPDATE', [$recordId]);
                // 锁定即定稿版：版本 +1，快照状态写 locked。
                // 若沿用草稿的 version，会与 uk_record_version 撞键，locked 快照被静默跳过。
                $ver = (int)$rec['version'] + 1;
                Database::run(
                    "UPDATE case_records SET status='locked', version=?, locked_at=NOW(), locked_by=?, updated_at=NOW()
                     WHERE id=? AND status='draft' AND version=?",
                    [$ver, $editorId ?: null, $recordId, (int)$rec['version']]
                );
                $now = Database::fetch('SELECT status, version FROM case_records WHERE id=?', [$recordId]);
                if ((string)($now['status'] ?? '') !== 'locked' || (int)($now['version'] ?? 0) !== $ver) {
                    throw new \RuntimeException('并发锁定冲突');
                }
                self::snapshot($recordId, $ver, (string)$rec['title'], (string)$rec['content'], 'locked', $editorId);
            });
        } catch (\Throwable $t) {
            Logger::error('case', 'record lock failed: ' . $t->getMessage());
            return self::fail('锁定失败，请稍后重试');
        }
        return ['ok' => true, 'msg' => '记录已锁定，之后如需修改请另开修订记录'];
    }

    /** 单条记录（带归属检查） */
    public static function find(int $recordId, int $counselorId): ?array
    {
        $row = Database::fetch(
            'SELECT r.*, s.seq AS session_seq, s.held_at AS session_held_at
             FROM case_records r LEFT JOIN case_sessions s ON s.id=r.session_id WHERE r.id=?', [$recordId]);
        if (!$row) return null;
        if (!CasePolicy::canReadRecord($row, $counselorId)) return null;
        return $row;
    }

    /** 某个案下的记录列表（不含正文，列表页不需要） */
    public static function listFor(int $caseId, int $counselorId): array
    {
        return Database::fetchAll(
            'SELECT r.id, r.session_id, r.record_type, r.title, r.version, r.status, r.visible,
                    r.locked_at, r.amends_id, r.created_at, r.updated_at, s.seq AS session_seq
             FROM case_records r LEFT JOIN case_sessions s ON s.id=r.session_id
             WHERE r.case_id=? AND r.counselor_id=?
             ORDER BY r.id DESC LIMIT 200', [$caseId, $counselorId]);
    }

    /** 版本历史 */
    public static function versionsOf(int $recordId): array
    {
        return Database::fetchAll(
            'SELECT id, version, status, editor_id, created_at FROM case_record_versions
             WHERE record_id=? ORDER BY version DESC, id DESC LIMIT 100', [$recordId]);
    }

    /** 某个版本正文（只读回溯） */
    public static function versionContent(int $recordId, int $version, int $counselorId): ?array
    {
        $rec = self::find($recordId, $counselorId);
        if (!$rec) return null;
        return Database::fetch('SELECT * FROM case_record_versions WHERE record_id=? AND version=?', [$recordId, $version]);
    }

    /** 由已锁定记录发起修订：新记录 amends_id 指向原记录，正文预填 */
    public static function startAmendment(int $recordId, int $counselorId): array
    {
        $rec = Database::fetch('SELECT * FROM case_records WHERE id=?', [$recordId]);
        if (!$rec) return self::fail('记录不存在');
        if (!CasePolicy::canReadRecord($rec, $counselorId)) return self::fail('无权操作该记录');
        if ((string)$rec['status'] !== 'locked') return self::fail('只有已锁定的记录才需要修订');
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [(int)$rec['case_id']]);
        if (!$case || !CasePolicy::canWrite($case, $counselorId)) return self::fail('个案已结案，不能新增修订');

        return self::save((int)$rec['case_id'], $counselorId, [
            'title' => str_take((string)$rec['title'] . '（修订）', 120, ''),
            'content' => (string)$rec['content'],
            'record_type' => (string)$rec['record_type'],
            'session_id' => (int)($rec['session_id'] ?? 0),
            'visible' => (int)$rec['visible'],
            'amends_id' => (int)$rec['id'],
        ], 0, 0);
    }

    /** 记录统计（工作台/驾驶舱用） */
    public static function stats(int $counselorId): array
    {
        return [
            'draft' => (int)Database::value("SELECT COUNT(*) FROM case_records WHERE counselor_id=? AND status='draft'", [$counselorId]),
            'locked' => (int)Database::value("SELECT COUNT(*) FROM case_records WHERE counselor_id=? AND status='locked'", [$counselorId]),
            'month' => (int)Database::value(
                "SELECT COUNT(*) FROM case_records WHERE counselor_id=? AND created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
                [$counselorId]
            ),
        ];
    }

    // ==================== 内部 ====================

    private static function snapshot(int $recordId, int $version, string $title, string $content, string $status, int $editorId): void
    {
        try {
            Database::insert('case_record_versions', [
                'record_id' => $recordId,
                'version' => $version,
                'title' => str_take($title, 120, ''),
                'content' => $content,
                'status' => $status,
                'editor_id' => $editorId ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            // 版本号唯一键冲突：并发同版本保存，保留先到的那份即可（不阻断主流程）
            Logger::warning('case', 'snapshot skipped r=' . $recordId . ' v=' . $version . ': ' . $t->getMessage());
        }
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }
}
