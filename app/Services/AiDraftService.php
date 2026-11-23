<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Logger;

/**
 * AI 记录草稿（P1-C）
 * ------------------------------------------------------------------
 * 一句话原则：**AI 永远只交草稿，咨询师点了「采用」并且自己再保存/锁定，才算入档。**
 *
 * 三条闸门：
 *  1) 总开关：`ai.draft_enabled=1` + AI 客服本身可用（ai.enabled/api_key/model 齐全）。
 *  2) 外发开关：`ai.outbound_scope=none` 时**零外发**，直接拒绝并说明原因。
 *  3) 脱敏：`ai.redact=1` 时先替换手机/身份证/邮箱/卡号，库里只存脱敏后的输入。
 *
 * 不做什么：不自动写 case_records、不自动锁定、不绕过咨询师。
 */
class AiDraftService
{
    private const TIMEOUT = 60;
    private const MAX_INPUT = 12000;

    private const SYSTEM = <<<'TXT'
你是心理咨询机构的记录整理助手。请把咨询师提供的会谈要点/逐字稿整理成结构化记录草稿。

硬性要求：
1. 只做「整理」，不做诊断、不下结论、不承诺疗效、不臆测未提及的信息。
2. 输出中文，使用 SOAP 四段小标题：主观（S）、客观（O）、评估（A）、计划（P）。
3. 信息缺失处写「待补充」，不要编造。
4. 不写来访者真实姓名、单位、住址等可识别信息；用「来访者」指代。
5. 语气专业克制，每段 2-5 条要点，总字数不超过 800 字。
6. 只输出记录正文，不要输出解释、道歉或额外说明。
TXT;

    /** 是否具备使用条件 */
    public static function available(): bool
    {
        if ((string)setting('ai.draft_enabled', '0') !== '1') return false;
        if (!AiService::enabled()) return false;
        return self::outboundScope() !== 'none';
    }

    public static function outboundScope(): string
    {
        return AiService::outboundScope();
    }

    /** 不可用时的原因（给界面用） */
    public static function blockReason(): string
    {
        if ((string)setting('ai.draft_enabled', '0') !== '1') return 'AI 草稿未启用（系统设置 → 咨询记录 AI 草稿）';
        if (!AiService::enabled()) return 'AI 接口未配置完整（需启用 + API Key + 模型名）';
        if (self::outboundScope() === 'none') return '当前设置为「零外发」，AI 草稿不可用（可在系统设置改为「仅对话」）';
        return '';
    }

    /**
     * 生成草稿：调用外部 API，结果只写 ai_tasks
     *
     * @return array{ok:bool,msg:string,id?:int,output?:string,redacted?:int}
     */
    public static function run(int $caseId, int $counselorId, string $input, int $sessionId = 0, int $editorId = 0): array
    {
        $case = Database::fetch('SELECT * FROM cases WHERE id=?', [$caseId]);
        if (!$case) return ['ok' => false, 'msg' => '个案不存在'];
        if (!CasePolicy::owns($case, $counselorId)) return ['ok' => false, 'msg' => '无权操作该个案'];
        if (!CasePolicy::canWrite($case, $counselorId)) return ['ok' => false, 'msg' => '个案已结案，不能生成草稿'];

        $input = trim($input);
        if (mb_strlen($input) < 20) return ['ok' => false, 'msg' => '内容太短，至少 20 字（可粘贴会谈要点或逐字稿）'];
        if (mb_strlen($input) > self::MAX_INPUT) $input = mb_substr($input, 0, self::MAX_INPUT);

        $reason = self::blockReason();
        if ($reason !== '') return ['ok' => false, 'msg' => $reason];

        $redacted = 0;
        $payloadText = $input;
        if ((string)setting('ai.redact', '1') === '1') {
            [$payloadText, $redacted] = AiService::redact($input);
        }

        $taskId = Database::insert('ai_tasks', [
            'case_id' => $caseId,
            'session_id' => $sessionId > 0 ? $sessionId : null,
            'counselor_id' => $counselorId,
            'kind' => 'draft',
            'input_chars' => mb_strlen($input),
            'redacted_count' => $redacted,
            'outbound' => 1,
            'input_text' => $payloadText,
            'status' => 'pending',
            'created_by' => $editorId ?: null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $model = (string)setting('ai.model');
            $out = self::chat($payloadText, $model);
            Database::run(
                "UPDATE ai_tasks SET output_text=?, model=?, status='done', updated_at=NOW() WHERE id=?",
                [$out, $model, $taskId]
            );
            return ['ok' => true, 'msg' => '草稿已生成（外发前脱敏 ' . $redacted . ' 处）', 'id' => $taskId, 'output' => $out, 'redacted' => $redacted];
        } catch (\Throwable $t) {
            $err = str_take($t->getMessage(), 480, '');
            Database::run("UPDATE ai_tasks SET status='failed', error=?, updated_at=NOW() WHERE id=?", [$err, $taskId]);
            Logger::error('ai', 'draft failed case=' . $caseId . ': ' . $err);
            return ['ok' => false, 'msg' => '生成失败：' . $err, 'id' => $taskId];
        }
    }

    /** 采用草稿 → 生成 case_records **草稿**（仍需人工核对与锁定） */
    public static function adopt(int $taskId, int $counselorId, int $editorId = 0): array
    {
        $task = Database::fetch('SELECT * FROM ai_tasks WHERE id=?', [$taskId]);
        if (!$task) return ['ok' => false, 'msg' => '任务不存在'];
        if ((int)$task['counselor_id'] !== $counselorId) return ['ok' => false, 'msg' => '无权操作该任务'];
        if ((string)$task['status'] !== 'done') return ['ok' => false, 'msg' => '只有已生成的草稿可以采用'];
        if (!empty($task['adopted_record_id'])) return ['ok' => false, 'msg' => '该草稿已被采用'];

        $html = nl2br(htmlspecialchars((string)$task['output_text'], ENT_QUOTES, 'UTF-8'));
        $r = CaseRecordService::save((int)$task['case_id'], $counselorId, [
            'title' => 'AI 草稿 · ' . date('m-d H:i') . '（待核对）',
            'content' => $html,
            'record_type' => 'soap',
            'session_id' => (int)($task['session_id'] ?? 0),
            'visible' => 0,
        ], 0, $editorId);
        if (empty($r['ok'])) return $r;

        Database::run("UPDATE ai_tasks SET status='adopted', adopted_record_id=?, updated_at=NOW() WHERE id=?", [(int)$r['id'], $taskId]);
        // 记录上留 AI 来源标记：谁都能看出"这条是机器起草、人来定稿"
        try {
            Database::run('UPDATE case_records SET ai_task_id=? WHERE id=?', [$taskId, (int)$r['id']]);
        } catch (\Throwable $t) {
            // 列不存在（未跑迁移）不阻断
        }
        return ['ok' => true, 'msg' => '已生成记录草稿，请核对后锁定', 'record_id' => (int)$r['id']];
    }

    /** 某个案下的 AI 任务 */
    public static function listFor(int $caseId, int $counselorId): array
    {
        return Database::fetchAll(
            'SELECT id, kind, input_chars, redacted_count, model, status, error, adopted_record_id, created_at
             FROM ai_tasks WHERE case_id=? AND counselor_id=? ORDER BY id DESC LIMIT 50',
            [$caseId, $counselorId]
        );
    }

    public static function find(int $taskId, int $counselorId): ?array
    {
        $row = Database::fetch('SELECT * FROM ai_tasks WHERE id=?', [$taskId]);
        if (!$row) return null;
        if ((int)$row['counselor_id'] !== $counselorId) return null;
        return $row;
    }

    // ==================== 内部 ====================

    private static function chat(string $text, string $model): string
    {
        $base = rtrim((string)setting('ai.base_url', 'https://api.deepseek.com'), '/');
        $url = $base . '/chat/completions';
        $body = json_encode([
            'model' => $model,
            'temperature' => 0.3,
            'max_tokens' => 1500,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => $text],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string)setting('ai.api_key'),
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new \RuntimeException('网络错误：' . $cerr);
        if ($code !== 200) throw new \RuntimeException('接口返回 ' . $code . '：' . str_take((string)$resp, 200, ''));
        $data = json_decode((string)$resp, true);
        $out = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($out === '') throw new \RuntimeException('模型返回为空');
        return $out;
    }
}
