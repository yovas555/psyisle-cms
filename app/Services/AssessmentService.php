<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Hook;

/**
 * 量表测评服务（v2.20.0 从 Assessment 控制器抽取，WEB 与小程序 BFF 共用）
 * 计分规则：Σ 每题分值（未答按 0）；分级取 scoring JSON 中首个 score<=max 的 band
 */
class AssessmentService
{
    /** 活跃量表列表（小程序/接口用，不含题干） */
    public static function list(): array
    {
        return Database::fetchAll(
            'SELECT slug, name, description, disclaimer,
                    JSON_LENGTH(items) AS item_count
             FROM assessments WHERE status=1 ORDER BY sort, id') ?: [];
    }

    /** 选项分值归一化：内置量表 options=[label,score] 数组对 / 专业量表={label,score} / 纯文本按序计分 */
    private static function optScore($opt, int $idx): int
    {
        if (is_array($opt)) {
            if (array_key_exists('score', $opt)) return (int)$opt['score'];
            if (isset($opt[1])) return (int)$opt[1];
        }
        if (is_string($opt)) return $idx;
        return 0;
    }

    /** 选项文本归一化（同上两种结构 + 纯文本） */
    private static function optLabel($opt): string
    {
        if (is_array($opt)) {
            if (isset($opt['label'])) return (string)$opt['label'];
            if (isset($opt[0])) return (string)$opt[0];
        }
        return is_string($opt) ? $opt : '';
    }

    /** 按 slug 取量表（含题干 items，不含 scoring 防客户端作弊） */
    public static function bySlug(string $slug): ?array
    {
        $a = Database::fetch('SELECT id, slug, name, description, items, disclaimer FROM assessments WHERE slug=? AND status=1', [$slug]);
        if ($a) {
            $items = json_decode((string)$a['items'], true) ?: [];
            // 小程序只需要题干与选项文本，剥离分值
            $clean = [];
            foreach ($items as $it) {
                $opts = [];
                foreach (($it['options'] ?? []) as $op) $opts[] = self::optLabel($op);
                $clean[] = ['q' => (string)($it['q'] ?? ''), 'options' => $opts];
            }
            $a['items_clean'] = $clean;
            $a['item_count'] = count($clean);
            unset($a['items']);
        }
        return $a;
    }

    /** 计分：answers 为题号=>选项序号数组；返回 [score, level, advice, answered, total] */
    public static function score(array $a, array $answers): array
    {
        $items = json_decode((string)$a['items'], true) ?: [];
        $scoring = json_decode((string)$a['scoring'], true) ?: [];
        $score = 0;
        $answered = 0;
        foreach ($items as $i => $item) {
            $optIdx = (int)($answers[$i] ?? -1);
            if ($optIdx >= 0 && isset($item['options'][$optIdx])) {
                $answered++;
                $score += self::optScore($item['options'][$optIdx], $optIdx);
            }
        }
        $level = '';
        $advice = '';
        foreach ($scoring as $band) {
            if ($score <= (int)$band['max']) { $level = (string)$band['level']; $advice = (string)$band['advice']; break; }
        }
        if ($level === '') { $level = '参考结果'; $advice = '建议与专业咨询师聊聊你的情况。'; }
        return [$score, $level, $advice, $answered, count($items)];
    }

    /** 提交并落库：返回 [ok, result数组{report_token,score,level,advice,dimensions}, err] */
    public static function submit(array $a, array $answers, ?int $memberId, string $ip): array
    {
        [$score, $level, $advice, $answered, $total] = self::score($a, $answers);
        if ($answered < $total) {
            return [false, null, '还有 ' . ($total - $answered) . ' 题未作答，请完成全部题目后提交'];
        }
        // answers 存储口径与 WEB 端一致=每题分值（维度计分 Hook 与历史报告页同源）
        $answersJson = [];
        foreach (json_decode((string)$a['items'], true) ?: [] as $i => $item) {
            $optIdx = (int)($answers[$i] ?? -1);
            $answersJson[$i] = ($optIdx >= 0 && isset($item['options'][$optIdx])) ? self::optScore($item['options'][$optIdx], $optIdx) : 0;
        }
        $reportToken = bin2hex(random_bytes(16));
        Database::insert('assessment_results', [
            'assessment_id' => (int)$a['id'],
            'score' => $score,
            'level' => mb_substr($level, 0, 29),
            'ip_hash' => md5($ip . (CONFIG['stats_salt'] ?? 'psyisle-salt')),
            'member_id' => $memberId,
            'answers' => json_encode($answersJson, JSON_UNESCAPED_UNICODE),
            'report_token' => $reportToken,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($memberId) {
            MemberNotifyService::push($memberId, 'assess_report', [
                'title' => '测评已完成：' . (string)$a['name'],
                'body' => '得分 ' . $score . ' · ' . $level,
                'lines' => ['量表' => (string)$a['name'], '得分' => (string)$score, '分级' => $level],
                'inapp_url' => '/member/assessments', 'ref_type' => 'assessment', 'ref_id' => (int)$a['id'],
            ]);
        }
        $dimensions = Hook::fire('report.dimensions', ['slug' => (string)$a['slug'], 'answers' => $answersJson, 'dimensions' => null])['dimensions'] ?? null;
        return [true, ['report_token' => $reportToken, 'score' => $score, 'level' => $level, 'advice' => $advice, 'dimensions' => $dimensions], ''];
    }

    /** 按 report_token 取结果（含量表名与维度分） */
    public static function resultByToken(string $token): ?array
    {
        $token = strtolower(preg_replace('/[^a-f0-9]/i', '', $token));
        if (strlen($token) !== 32) return null;
        $r = Database::fetch(
            'SELECT r.score, r.level, r.answers, r.report_token, r.created_at, a.name AS a_name, a.slug AS a_slug, a.disclaimer, a.scoring
             FROM assessment_results r JOIN assessments a ON a.id=r.assessment_id WHERE r.report_token=?', [$token]);
        if (!$r) return null;
        $answers = json_decode((string)$r['answers'], true) ?: [];
        $r['dimensions'] = Hook::fire('report.dimensions', ['slug' => $r['a_slug'], 'answers' => $answers, 'dimensions' => null])['dimensions'] ?? null;
        unset($r['scoring'], $r['answers']);
        return $r;
    }

    /** 会员历史测评 */
    public static function memberResults(int $memberId, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT r.score, r.level, r.report_token, r.created_at, a.name AS a_name
             FROM assessment_results r JOIN assessments a ON a.id=r.assessment_id
             WHERE r.member_id=? ORDER BY r.created_at DESC LIMIT ' . max(1, min(50, $limit)), [$memberId]) ?: [];
    }
}
