<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class CounselorTrust
{
    public const ITEMS = [
        'credentials' => '资质与注册信息',
        'supervision' => '督导与专业支持',
        'boundaries' => '服务对象与专业边界',
        'annual_review' => '年度专业审阅',
    ];

    public const STATUSES = ['pending', 'verified', 'rejected', 'revoked'];

    private const STATUS_LABELS = [
        'unverified' => '尚未核验',
        'pending' => '待核验',
        'verified' => '平台已核验',
        'rejected' => '未通过核验',
        'revoked' => '核验已撤回',
        'expired' => '核验已过期',
        'stale' => '资料已变更，待重新核验',
    ];

    public static function item(string $key): array
    {
        if (!isset(self::ITEMS[$key])) throw new \InvalidArgumentException('不支持的核验项目');
        return ['key' => $key, 'label' => self::ITEMS[$key]];
    }

    private static function text($value): string
    {
        return trim((string)($value ?? ''));
    }

    public static function subjectHash(array $counselor, string $key): string
    {
        self::item($key);
        switch ($key) {
            case 'credentials': $parts = [$counselor['credentials'] ?? '']; break;
            case 'supervision': $parts = [$counselor['supervision'] ?? '']; break;
            case 'boundaries': $parts = [$counselor['audiences'] ?? '', $counselor['unsuitable_topics'] ?? '']; break;
            default:
                $parts = [
                    $counselor['credentials'] ?? '', $counselor['supervision'] ?? '',
                    $counselor['audiences'] ?? '', $counselor['unsuitable_topics'] ?? '',
                    $counselor['personal_therapy'] ?? '',
                ];
        }
        return hash('sha256', json_encode(array_map([self::class, 'text'], $parts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function record(int $counselorId, array $counselor, array $input, int $actorId, string $actorName, ?string $now = null): array
    {
        $key = self::text($input['item_key'] ?? '');
        self::item($key);
        $status = self::text($input['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('核验状态不合法');
        $now = $now ?: date('Y-m-d H:i:s');
        $today = date('Y-m-d', strtotime($now));
        $expiresAt = self::text($input['expires_at'] ?? '') ?: null;
        $summary = self::text($input['verified_summary'] ?? '');
        $evidence = self::text($input['evidence_reference'] ?? '');
        if ($expiresAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            throw new \InvalidArgumentException('有效期格式不正确');
        }
        if ($expiresAt !== null) {
            $date = \DateTime::createFromFormat('!Y-m-d', $expiresAt);
            if (!$date || $date->format('Y-m-d') !== $expiresAt) throw new \InvalidArgumentException('有效期不正确');
        }
        if ($status === 'verified') {
            if ($evidence === '' || $summary === '') throw new \InvalidArgumentException('通过核验时必须填写核验依据与公开摘要');
            if ($expiresAt !== null && $expiresAt < $today) throw new \InvalidArgumentException('有效期不能早于核验日期');
            if ($key === 'annual_review' && $expiresAt === null) throw new \InvalidArgumentException('年度专业审阅必须设置有效期');
        }
        if ($counselorId < 1 || $actorId < 1 || $actorName === '') throw new \InvalidArgumentException('核验操作者信息不完整');
        return [
            'counselor_id' => $counselorId,
            'item_key' => $key,
            'status' => $status,
            'subject_hash' => self::subjectHash($counselor, $key),
            'evidence_reference' => mb_substr($evidence, 0, 1000),
            'verified_summary' => mb_substr($summary, 0, 1000),
            'reviewed_at' => $now,
            'expires_at' => $expiresAt,
            'actor_id' => $actorId,
            'verifier_name' => mb_substr($actorName, 0, 80),
            'created_at' => $now,
        ];
    }

    public static function evaluate(array $counselor, ?array $record, ?string $today = null, ?string $itemKey = null): array
    {
        $today = $today ?: date('Y-m-d');
        $key = (string)($itemKey ?: ($record['item_key'] ?? 'credentials'));
        if (!isset(self::ITEMS[$key])) $key = 'credentials';
        $status = (string)($record['status'] ?? 'unverified');
        if (!in_array($status, ['unverified', 'pending', 'verified', 'rejected', 'revoked'], true)) $status = 'unverified';
        $subjectChanged = $record !== null && !hash_equals((string)($record['subject_hash'] ?? ''), self::subjectHash($counselor, $key));
        if ($status === 'verified' && $subjectChanged) $status = 'stale';
        $expiresAt = (string)($record['expires_at'] ?? '');
        if ($status === 'verified' && $expiresAt !== '' && $expiresAt < $today) $status = 'expired';
        return [
            'item_key' => $key,
            'item_label' => self::ITEMS[$key],
            'status' => $status,
            'label' => self::STATUS_LABELS[$status],
            'verified' => $status === 'verified',
            'subject_changed' => $subjectChanged,
            'reviewed_at' => (string)($record['reviewed_at'] ?? ''),
            'expires_at' => $expiresAt,
            'verified_summary' => (string)($record['verified_summary'] ?? ''),
        ];
    }

    public static function publicState(?array $record, array $counselor, ?string $today = null, ?string $itemKey = null): array
    {
        $state = self::evaluate($counselor, $record, $today, $itemKey);
        return [
            'item_key' => $state['item_key'],
            'status' => $state['status'],
            'label' => $state['label'],
            'verified' => $state['verified'],
            'reviewed_at' => $state['reviewed_at'] ?: null,
            'expires_at' => $state['expires_at'] ?: null,
            'verified_summary' => in_array($state['status'], ['verified', 'expired', 'stale'], true) && $state['verified_summary'] !== ''
                ? $state['verified_summary'] : null,
        ];
    }

    private static function tableAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            try {
                $available = Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='counselor_verifications'") > 0;
            } catch (\Throwable $e) {
                return false;
            }
        }
        return $available;
    }

    public static function latestFor(int $counselorId): array
    {
        if (!self::tableAvailable()) return [];
        $rows = Database::fetchAll(
            'SELECT v.* FROM counselor_verifications v
             INNER JOIN (SELECT item_key, MAX(id) AS id FROM counselor_verifications WHERE counselor_id=? GROUP BY item_key) latest
             ON latest.id=v.id WHERE v.counselor_id=? ORDER BY v.id',
            [$counselorId, $counselorId]
        );
        return array_column($rows, null, 'item_key');
    }

    public static function latestForMany(array $counselors): array
    {
        if (!$counselors || !self::tableAvailable()) return [];
        $ids = array_values(array_unique(array_map(fn($c) => (int)$c['id'], $counselors)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT v.* FROM counselor_verifications v
             INNER JOIN (SELECT item_key, MAX(id) AS id FROM counselor_verifications WHERE counselor_id IN ($placeholders) GROUP BY counselor_id,item_key) latest
             ON latest.id=v.id ORDER BY v.id",
            $ids
        );
        $out = [];
        foreach ($rows as $row) $out[(int)$row['counselor_id']][$row['item_key']] = $row;
        return $out;
    }

    public static function history(int $counselorId, int $limit = 100): array
    {
        if (!self::tableAvailable()) return [];
        $limit = max(1, min(500, $limit));
        return Database::fetchAll(
            "SELECT v.*, u.username FROM counselor_verifications v
             LEFT JOIN users u ON u.id=v.actor_id WHERE v.counselor_id=? ORDER BY v.id DESC LIMIT $limit",
            [$counselorId]
        );
    }

    public static function publicProfile(array $counselor, array $records = [], ?string $today = null): array
    {
        $states = [];
        foreach (array_keys(self::ITEMS) as $key) {
            $states[$key] = self::publicState($records[$key] ?? null, $counselor, $today, $key);
        }
        return [
            'audiences' => self::text($counselor['audiences'] ?? ''),
            'unsuitable_topics' => self::text($counselor['unsuitable_topics'] ?? ''),
            'personal_therapy' => self::text($counselor['personal_therapy'] ?? ''),
            'self_credentials' => self::text($counselor['credentials'] ?? ''),
            'self_supervision' => self::text($counselor['supervision'] ?? ''),
            'verifications' => $states,
        ];
    }
}
