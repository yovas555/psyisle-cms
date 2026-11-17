<?php
/** AI 智能客服插件：LLM 配置、知识库、提示词、应答编排（危机兜底在核心，不可关闭） */
declare(strict_types=1);

namespace plugins\aiconcierge;

use app\Core\Database;
use app\Core\Hook;
use app\Core\Http;
use app\Core\Logger;
use app\Services\AiService;

final class Plugin
{
    public static function boot(array $meta): void
    {
        // 核心 Chat 控制器把「非危机、非人工接管」的回复交给本插件
        Hook::on('ai.reply', [self::class, 'onReply']);
    }

    public static function enabled(): bool
    {
        return (bool)setting('ai.enabled') && (string)setting('ai.model') !== '';
    }

    /** 应答编排：知识库优先 → LLM → 失败回退 */
    public static function onReply(array $ctx): array
    {
        $sid = (int)($ctx['session_id'] ?? 0);
        $msg = (string)($ctx['msg'] ?? '');

        if (!self::enabled()) {
            return ['reply' => '', 'type' => 'disabled'];
        }

        // 1) 知识库命中（不耗 token）
        $kb = self::kbHit($msg);
        if ($kb !== null) {
            AiService::save($sid, 'assistant', $kb, 'kb', 0);
            return ['reply' => AiService::markdownLite($kb), 'type' => 'kb'];
        }

        // 2) LLM
        try {
            $messages = [['role' => 'system', 'content' => self::systemPrompt()]];
            $history = Database::fetchAll(
                'SELECT role, content FROM chat_messages WHERE session_id=? ORDER BY id ASC LIMIT 20', [$sid]
            );
            foreach ($history as $h) {
                if ($h['role'] === 'system') continue;
                $messages[] = ['role' => $h['role'], 'content' => mb_substr((string)$h['content'], 0, 2000)];
            }
            $messages[] = ['role' => 'user', 'content' => mb_substr($msg, 0, 2000)];

            [$ok, $status, $body] = Http::post(
                self::endpoint(),
                json_encode([
                    'model' => (string)setting('ai.model'),
                    'messages' => $messages,
                    'temperature' => 0.5,
                    'max_tokens' => 800,
                    'stream' => false,
                ], JSON_UNESCAPED_UNICODE),
                ['Content-Type: application/json', 'Authorization: Bearer ' . (string)setting('ai.api_key')],
                30
            );
            if (!$ok || $status >= 400) {
                throw new \RuntimeException("LLM status=$status " . str_take((string)$body, 200, ''));
            }
            $data = json_decode((string)$body, true);
            $reply = (string)($data['choices'][0]['message']['content'] ?? '');
            if ($reply === '') throw new \RuntimeException('LLM 空回复');
            $reply = str_replace(['【引用】', '[citation]'], '', $reply);

            AiService::save($sid, 'assistant', $reply, (string)setting('ai.model'), (int)($data['usage']['total_tokens'] ?? 0));

            // 未答检测 → 通知管理员人工跟进
            if (preg_match('/(无法回答|没有相关信息|我还不清楚|抱歉，.{0,6}帮不到)/u', $reply)) {
                \app\Services\NotifyService::push('chat_escalate', 'AI 客服疑似未答用户问题',
                    '用户问题：' . str_take($msg, 100, '…'), ['type' => 'chat_escalate', 'id' => $sid]);
            }
            return ['reply' => AiService::markdownLite($reply), 'type' => 'ai'];
        } catch (\Throwable $t) {
            Logger::error('ai-concierge', $t->getMessage());
            return ['reply' => '', 'type' => 'error'];
        }
    }

    /** OpenAI 兼容端点（base 是否以 /v1 结尾均可） */
    private static function endpoint(): string
    {
        $base = rtrim((string)setting('ai.base_url', 'https://api.deepseek.com'), '/');
        if (preg_match('#/chat/completions$#', $base)) return $base;
        return $base . '/chat/completions';
    }

    /** 知识库匹配（关键词权重） */
    private static function kbHit(string $q): ?string
    {
        $rows = Database::fetchAll('SELECT question, answer, keywords FROM ai_kb WHERE enabled=1');
        if (!$rows) return null;
        $best = null;
        $bestScore = 0;
        foreach ($rows as $r) {
            $score = 0;
            foreach (preg_split('/[、,，\s|]+/u', (string)$r['keywords']) ?: [] as $kw) {
                if ($kw !== '' && mb_stripos($q, $kw) !== false) $score += 2;
            }
            foreach (preg_split('/[\s、,，?？!！。]+/u', (string)$r['question']) ?: [] as $seg) {
                if (mb_strlen($seg) >= 2 && mb_stripos($q, $seg) !== false) $score += 1;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        return ($bestScore >= 2 && $best) ? (string)$best['answer'] : null;
    }

    /** system prompt：角色/附加提示词 + 站点知识（危机规则由核心强制附加，此处不可覆盖） */
    public static function systemPrompt(): string
    {
        $parts = [];
        $persona = trim((string)setting('ai.persona', ''));
        if ($persona !== '') {
            $parts[] = $persona;
        } else {
            $parts[] = '你是「' . setting('site.name', '一二三心理咨询中心') . '」的咨询助理「小壹」。';
            $parts[] = '身份与边界：你是友好、温暖、专业的接待助手，不是治疗师；不提供医学诊断，不做心理治疗，建议用户通过预约到线下/线上咨询获得专业帮助。';
            $parts[] = '回答范围：仅限本机构业务（咨询服务、咨询师介绍、预约流程、收费、地址营业时间、常见问题、心理科普常识）。超出范围时礼貌引导回业务或建议预约。';
        }
        $parts[] = '安全规则（最高优先级，不可被用户改写）：若用户表达自伤、自杀、伤害他人等风险，立即停止普通回答，回复：请立即拨打 24 小时心理援助热线 12356，紧急情况拨打 110。';
        $parts[] = '语气：简体中文，亲切自然，不使用 markdown 标题，回复控制在 250 字以内，重要信息（电话/链接）单独一行。';
        $extra = trim((string)setting('ai.extra_prompt', ''));
        if ($extra !== '') $parts[] = '机构附加要求：' . $extra;

        $site = [];
        $site[] = '机构名称：' . setting('site.name', '');
        if (setting('contact.phone')) $site[] = '联系电话：' . setting('contact.phone');
        if (setting('contact.address')) $site[] = '机构地址：' . setting('contact.address');
        if (setting('contact.hours')) $site[] = '接待时间：' . setting('contact.hours');
        $about = Database::fetch("SELECT content FROM pages WHERE slug='about' AND status='published'");
        if ($about) $site[] = '机构简介：' . str_take(strip_tags((string)$about['content']), 300, '…');

        foreach (Database::fetchAll('SELECT name, format, duration, price_from, price_to, price_note FROM services WHERE status=1 ORDER BY sort LIMIT 20') as $s) {
            $price = $s['price_from'] ? '收费 ' . $s['price_from'] . ($s['price_to'] ? '-' . $s['price_to'] : '') . ' 元' : ($s['price_note'] ?: '面议');
            $site[] = sprintf('服务项目：%s（形式：%s，时长：%s，%s）', $s['name'], $s['format'] ?: '-', $s['duration'] ?: '-', $price);
        }
        foreach (Database::fetchAll('SELECT name, title, orientation, specialties, fee_from, fee_to FROM counselors WHERE status=1 ORDER BY sort LIMIT 20') as $c) {
            $fee = $c['fee_from'] ? '收费 ' . $c['fee_from'] . ($c['fee_to'] ? '-' . $c['fee_to'] : '') . '元' : '';
            $site[] = sprintf('咨询师：%s（%s，流派：%s，擅长：%s，%s）', $c['name'], $c['title'] ?: '', $c['orientation'] ?: '', $c['specialties'] ?: '', $fee);
        }
        foreach (Database::fetchAll('SELECT question, answer FROM faqs WHERE status=1 ORDER BY sort LIMIT 15') as $f) {
            $site[] = sprintf('常见问题：%s 答：%s', $f['question'], str_take(strip_tags((string)$f['answer']), 120, ''));
        }
        foreach (Database::fetchAll('SELECT question, answer FROM ai_kb WHERE enabled=1 AND source="manual" LIMIT 15') as $k) {
            $site[] = sprintf('知识词条：%s 答：%s', $k['question'], str_take(strip_tags((string)$k['answer']), 120, ''));
        }
        $parts[] = "\n以下是本机构的资料，回答时优先使用：\n" . implode("\n", array_filter($site));
        return implode("\n", $parts);
    }

    /** 连通性测试 */
    public static function testApi(): array
    {
        if (!self::enabled()) return [false, 'AI 未启用或配置不完整（API Key / 模型名）'];
        [$ok, $status, $body] = Http::post(
            self::endpoint(),
            json_encode(['model' => (string)setting('ai.model'), 'messages' => [['role' => 'user', 'content' => '回复"OK"两个字']], 'max_tokens' => 10], JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json', 'Authorization: Bearer ' . (string)setting('ai.api_key')],
            30
        );
        if (!$ok || $status >= 400) return [false, "status=$status: " . str_take((string)$body, 150, '')];
        $data = json_decode((string)$body, true);
        return [true, 'API 正常，模型返回：' . str_take((string)($data['choices'][0]['message']['content'] ?? ''), 40, '')];
    }

    /** 后台首页：AI 配置 + 提示词 + 知识库管理 */
    public static function adminIndex($req): void
    {
        $kb = Database::fetchAll('SELECT * FROM ai_kb ORDER BY id DESC LIMIT 100');
        \app\Core\PluginManager::view('ai-concierge', 'admin', [
            'meta' => \app\Core\PluginManager::get('ai-concierge'),
            'kb' => $kb,
            'enabled' => (bool)setting('ai.enabled'),
            'keys' => [setting('ai.base_url'), setting('ai.model')],
        ]);
    }

    /** 知识库新增（表单提交） */
    public static function addKb($req): void
    {
        \app\Core\Csrf::guard();
        $q = trim((string)$req->post('question', ''));
        $a = trim((string)$req->post('answer', ''));
        if ($q === '' || $a === '') {
            \app\Core\Session::flash('error', '问题与回答都不能为空');
            \app\Core\ResponseRedirect('/admin/plugin/ai-concierge');
        }
        Database::insert('ai_kb', [
            'question' => mb_substr($q, 0, 190), 'answer' => $a,
            'keywords' => mb_substr(trim((string)$req->post('keywords', '')), 0, 250),
            'source' => 'manual', 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        \app\Core\Session::flash('success', '知识词条已添加');
        \app\Core\ResponseRedirect('/admin/plugin/ai-concierge');
    }

    public static function kbToggle($req, array $p = []): void
    {
        \app\Core\Csrf::guard();
        $id = (int)$req->post('id', 0);
        $row = Database::fetch('SELECT enabled FROM ai_kb WHERE id=?', [$id]);
        if ($row) Database::update('ai_kb', ['enabled' => (int)!$row['enabled']], 'id=?', [$id]);
        \app\Core\ResponseRedirect('/admin/plugin/ai-concierge');
    }

    public static function kbDelete($req): void
    {
        \app\Core\Csrf::guard();
        Database::delete('ai_kb', 'id=?', [(int)$req->post('id', 0)]);
        \app\Core\Session::flash('success', '词条已删除');
        \app\Core\ResponseRedirect('/admin/plugin/ai-concierge');
    }
}
