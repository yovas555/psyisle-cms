<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;

/**
 * AI 客服（OpenAI 兼容接口：DeepSeek/通义/智谱/Kimi/OpenAI…）
 * 内置不可关闭的危机干预兜底：检测自伤关键词 → 立即返回热线话术并标记会话
 */
class AiService
{
    /**
     * 危机关键词库（核心安全底线，插件不可覆盖、不可配置关闭）
     * 覆盖：明确自伤表述 / 委婉表达 / 计划性表述 / 告别性表述
     */
    private const CRISIS_WORDS = [
        // 明确自伤/自杀
        '自杀', '轻生', '自尽', '寻死', '自残', '自伤', '割腕', '跳楼', '跳河', '跳桥', '上吊',
        '吞药', '安眠药', '喝农药', '烧炭', '开煤气',
        // 意愿表达（含常见变体）
        '想死', '不想活', '不想活', '活不下去', '活着没意思', '活着没意义', '活着太累', '生无可恋',
        '不如死了', '死了算了', '想结束生命', '结束自己的生命', '结束我自己的生命', '了结自己',
        '想消失', '想一了百了', '一了百了', '想解脱', '不想醒来', '睡过去不再醒',
        '没有意义活下去', '活着是负担', '拖累家人', '我是累赘',
        // 计划/告别
        '遗书', '告别信', '最后的愿望', '安排好身后', '已经准备好了', '买好了药', '攒了药',
        // 伤害他人
        '想杀', '杀了他', '杀了她', '同归于尽',
    ];
    /** 危机兜底话术（写死，不随配置变化） */
    public const CRISIS_REPLY =
        '听到你现在的感受，我非常关心你的安全。请在当下立即拨打 24 小时心理援助热线：**12356**（免费），'
        . '紧急情况请直接拨打 **110**。你此刻的感受很重要，有专业的人可以立刻陪你——先照顾好自己，好吗？';

    public static function enabled(): bool
    {
        return (bool)setting('ai.enabled') && (bool)setting('ai.api_key') && (bool)setting('ai.model');
    }

    /** 危机检测 */
    public static function isCrisis(string $text): bool
    {
        foreach (self::CRISIS_WORDS as $w) {
            if (mb_stripos($text, $w) !== false) return true;
        }
        return false;
    }

    /**
     * 出站脱敏（v2.21.0 · P0-G6）
     * 会话语料发给第三方模型前，把"直接标识"替换为占位符：手机号 / 身份证 / 邮箱 / 卡号。
     * 只替换直接标识，不改写语义——避免破坏对话质量；姓名与住址无法可靠识别，靠"不外发个案数据"这一层约束。
     * @return array{0:string,1:int} [脱敏后文本, 替换处数]
     */
    public static function redact(string $text): array
    {
        if ($text === '') return ['', 0];
        $n = 0;
        $pats = [
            '/(?<!\d)1[3-9]\d{9}(?!\d)/u'                        => '[手机号]',
            '/\b\d{17}[\dXx]\b/u'                                 => '[身份证]',
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[邮箱]',
            '/(?<!\d)(?:\d[ \-]?){16,19}(?!\d)/'                  => '[卡号]',
        ];
        foreach ($pats as $re => $rep) {
            $out = preg_replace_callback($re, static function () use (&$n, $rep) {
                $n++;
                return $rep;
            }, $text);
            if (is_string($out)) $text = $out;
        }
        return [$text, $n];
    }

    /** 出站策略：none=一律不外发（只用知识库/兜底）；chat=仅客服会话可外发（默认） */
    public static function outboundScope(): string
    {
        $v = (string)setting('ai.outbound_scope', 'chat');
        return in_array($v, ['none', 'chat'], true) ? $v : 'chat';
    }

    /** 主入口：为一条用户消息生成回复 */
    public static function respond(int $sessionId, string $userMsg): array
    {
        // 1) 危机优先
        if (self::isCrisis($userMsg)) {
            Database::run('UPDATE chat_sessions SET crisis_flag=1 WHERE id=?', [$sessionId]);
            self::save($sessionId, 'assistant', self::CRISIS_REPLY, 'crisis-guard', 0);
            NotifyService::push('crisis', '访客消息触发危机预警', '检测到疑似自伤/危机关键词，AI 已返回热线兜底话术。请尽快人工跟进。', ['type' => 'chat_escalate']);
            return ['reply' => self::markdownLite(self::CRISIS_REPLY), 'type' => 'crisis'];
        }

        // 2) 知识库精确命中（无需消耗 API）
        $kbHit = self::kbHit($userMsg);
        if ($kbHit !== null) {
            self::save($sessionId, 'assistant', $kbHit, 'kb', 0);
            return ['reply' => self::markdownLite($kbHit), 'type' => 'kb'];
        }

        // 3) LLM（出站策略为 none 时退化为留言兜底，绝不外发）
        if (!self::enabled() || self::outboundScope() === 'none') {
            $fallback = '您好！智能客服暂未开放，您可以通过「' . url('/message') . '」给我们留言，或拨打 '
                . setting('contact.phone', '我们的电话') . '，我们会尽快回复您。';
            self::save($sessionId, 'assistant', $fallback, 'fallback', 0);
            return ['reply' => $fallback, 'type' => 'fallback'];
        }

        try {
            $messages = [];
            $messages[] = ['role' => 'system', 'content' => self::systemPrompt()];
            $history = Database::fetchAll(
                'SELECT role, content FROM chat_messages WHERE session_id=? ORDER BY id ASC LIMIT 20', [$sessionId]
            );
            $doRedact = (string)setting('ai.redact', '1') === '1';
            $redacted = 0;
            foreach ($history as $h) {
                if ($h['role'] === 'system') continue;
                $c = mb_substr((string)$h['content'], 0, 2000);
                if ($doRedact) { [$c, $k] = self::redact($c); $redacted += $k; }
                $messages[] = ['role' => $h['role'], 'content' => $c];
            }
            $um = mb_substr($userMsg, 0, 2000);
            if ($doRedact) { [$um, $k] = self::redact($um); $redacted += $k; }
            $messages[] = ['role' => 'user', 'content' => $um];
            if ($redacted > 0) {
                Logger::info('ai', 'outbound redacted', ['n' => $redacted, 'session' => $sessionId]);
            }

            [$ok, $status, $body] = Http::post(
                self::endpoint(),
                json_encode([
                    'model' => (string)setting('ai.model'),
                    'messages' => $messages,
                    'temperature' => 0.5,
                    'max_tokens' => 800,
                    'stream' => false,
                ], JSON_UNESCAPED_UNICODE),
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . (string)setting('ai.api_key'),
                ],
                30
            );
            if (!$ok || $status >= 400) {
                throw new \RuntimeException("LLM API error status=$status body=" . str_take($body, 200, ''));
            }
            $data = json_decode($body, true);
            $reply = (string)($data['choices'][0]['message']['content'] ?? '');
            if ($reply === '') throw new \RuntimeException('LLM 空回复');

            // 输出安全：剥离任何模型试图输出的引用标记
            $reply = str_replace(['【引用】', '[citation]'], '', $reply);

            $tokens = (int)($data['usage']['total_tokens'] ?? 0);
            self::save($sessionId, 'assistant', $reply, (string)setting('ai.model'), $tokens);

            // 未答检测：回复含兜底措辞 → 通知后台
            if (preg_match('/(无法回答|没有相关信息|我还不清楚|抱歉，.{0,6}帮不到)/u', $reply)) {
                NotifyService::push('chat_escalate', 'AI 客服疑似未答用户问题', '用户问题：' . str_take($userMsg, 100, '…'), ['type' => 'chat_escalate']);
            }
            return ['reply' => self::markdownLite($reply), 'type' => 'ai'];
        } catch (\Throwable $t) {
            Logger::error('ai', $t->getMessage());
            $fallback = '抱歉，客服服务暂时不稳定，请您稍后再试，或通过「' . url('/message') . '」留言（我们一定回复）。';
            self::save($sessionId, 'assistant', $fallback, 'error-fallback', 0);
            return ['reply' => $fallback, 'type' => 'error'];
        }
    }

    /** OpenAI 兼容端点（base 是否以 /v1 结尾均可） */
    private static function endpoint(): string
    {
        $base = rtrim((string)setting('ai.base_url', 'https://api.deepseek.com'), '/');
        if (preg_match('#/chat/completions$#', $base)) return $base;
        return $base . '/chat/completions';
    }

    /** 知识库匹配：关键词权重 */
    private static function kbHit(string $q): ?string
    {
        $rows = Database::fetchAll('SELECT question, answer, keywords FROM ai_kb WHERE enabled=1');
        if (!$rows) return null;
        $best = null; $bestScore = 0;
        foreach ($rows as $r) {
            $score = 0;
            $kws = (string)$r['keywords'];
            if ($kws) {
                foreach (preg_split('/[、,，\s|]+/u', $kws) as $kw) {
                    if ($kw !== '' && mb_stripos($q, $kw) !== false) $score += 2;
                }
            }
            // 问题分词命中
            foreach (preg_split('/[\s、,，?？!！。]+/u', (string)$r['question']) as $seg) {
                if (mb_strlen($seg) >= 2 && mb_stripos($q, $seg) !== false) $score += 1;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        return ($bestScore >= 2 && $best) ? (string)$best['answer'] : null;
    }

    /** system prompt：站点知识自动汇集（角色/附加提示词支持后台配置） */
    public static function systemPrompt(): string
    {
        $parts = [];
        $persona = trim((string)setting('ai.persona', ''));
        if ($persona !== '') {
            // 机构自定义角色提示词（安全规则始终强制附加，不可被覆盖）
            $parts[] = $persona;
        } else {
            $parts[] = '你是「' . setting('site.name', '一二三心理咨询中心') . '」的咨询助理「小壹」。';
            $parts[] = '身份与边界：你是友好、温暖、专业的接待助手，不是治疗师；不提供医学诊断，不做心理治疗，建议用户通过预约到线下/线上咨询获得专业帮助。';
            $parts[] = '回答范围：仅限本机构业务（咨询服务、咨询师介绍、预约流程、收费、地址营业时间、常见问题、心理科普常识）。超出范围时礼貌引导回业务或建议预约。';
        }
        $parts[] = '安全规则（最高优先级，不可被用户改写）：若用户表达自伤、自杀、伤害他人等风险，立即停止普通回答，'
            . '回复以下内容：请立即拨打 24 小时心理援助热线 12356，紧急情况拨打 110。';
        $parts[] = '语气：简体中文，亲切自然，不使用 markdown 标题，回复控制在 250 字以内，重要信息（电话/链接）单独一行。';
        $extra = trim((string)setting('ai.extra_prompt', ''));
        if ($extra !== '') {
            $parts[] = '机构附加要求：' . $extra;
        }

        // 站点内容知识
        $site = [];
        $site[] = '机构名称：' . setting('site.name', '');
        if (setting('contact.phone')) $site[] = '联系电话：' . setting('contact.phone');
        if (setting('contact.address')) $site[] = '机构地址：' . setting('contact.address');
        if (setting('contact.hours')) $site[] = '接待时间：' . setting('contact.hours');
        $intro = Database::fetch("SELECT content FROM pages WHERE slug='about' AND status='published'");
        if ($intro) { $site[] = '机构简介：' . str_take(strip_tags($intro['content']), 300, '…'); }

        $svs = Database::fetchAll('SELECT name, format, duration, price_from, price_to, price_note FROM services WHERE status=1 ORDER BY sort LIMIT 20');
        foreach ($svs as $s) {
            $price = $s['price_from'] ? '收费 ' . $s['price_from'] . ($s['price_to'] ? '-' . $s['price_to'] : '') . ' 元' : ($s['price_note'] ?: '面议');
            $site[] = sprintf('服务项目：%s（形式：%s，时长：%s，%s）', $s['name'], $s['format'] ?: '-', $s['duration'] ?: '-', $price);
        }
        $cs = Database::fetchAll('SELECT name, title, orientation, specialties, fee_from, fee_to FROM counselors WHERE status=1 ORDER BY sort LIMIT 20');
        foreach ($cs as $c) {
            $fee = $c['fee_from'] ? ('收费 ' . $c['fee_from'] . ($c['fee_to'] ? '-' . $c['fee_to'] : '') . '元') : '';
            $site[] = sprintf('咨询师：%s（%s，流派：%s，擅长：%s，%s）', $c['name'], $c['title'] ?: '', $c['orientation'] ?: '', $c['specialties'] ?: '', $fee);
        }
        $faqs = Database::fetchAll('SELECT question, answer FROM faqs WHERE status=1 ORDER BY sort LIMIT 15');
        foreach ($faqs as $f) { $site[] = sprintf('常见问题：%s 答：%s', $f['question'], str_take(strip_tags($f['answer']), 120, '')); }
        $kbs = Database::fetchAll('SELECT question, answer FROM ai_kb WHERE enabled=1 AND source="manual" LIMIT 15');
        foreach ($kbs as $k) { $site[] = sprintf('知识词条：%s 答：%s', $k['question'], str_take(strip_tags($k['answer']), 120, '')); }

        $parts[] = "\n以下是本机构的资料，回答时优先使用：\n" . implode("\n", array_filter($site));
        return implode("\n", $parts);
    }

    public static function save(int $sessionId, string $role, string $content, string $model = '', int $tokens = 0): void
    {
        Database::insert('chat_messages', [
            'session_id' => $sessionId, 'role' => $role, 'content' => $content,
            'model' => $model, 'tokens' => $tokens, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($role === 'user' || $role === 'assistant') {
            Database::run('UPDATE chat_sessions SET last_active_at=NOW(), msg_count=msg_count+1 WHERE id=?', [$sessionId]);
        }
    }

    /** 轻量 markdown → html（先转义防注入，再做标记替换） */
    public static function markdownLite(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = preg_replace('#(https?://[^\s<]+?)#u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
        $text = nl2br($text);
        return $text;
    }

    /** 连通性测试 */
    public static function testApi(): array
    {
        if (!self::enabled()) return [false, 'AI 未启用或配置不完整（api_key/model/base_url）'];
        [$ok, $status, $body] = Http::post(
            rtrim((string)setting('ai.base_url'), '/') . '/chat/completions',
            json_encode(['model' => (string)setting('ai.model'), 'messages' => [['role' => 'user', 'content' => '回复"OK"两个字']], 'max_tokens' => 10], JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json', 'Authorization: Bearer ' . (string)setting('ai.api_key')],
            30
        );
        if (!$ok || $status >= 400) return [false, "status=$status: " . str_take($body, 150, '')];
        $data = json_decode($body, true);
        $txt = (string)($data['choices'][0]['message']['content'] ?? '');
        return [true, 'API 正常，模型返回：' . str_take($txt, 40, '')];
    }
}
