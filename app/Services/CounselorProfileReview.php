<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class CounselorProfileReview
{
    public const FIELDS = ['credentials', 'supervision', 'personal_therapy'];
    public const LABELS = [
        'credentials' => '资质与注册信息',
        'supervision' => '督导与专业支持',
        'personal_therapy' => '个人体验与自我觉察',
    ];
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public static function normalizeFields(array $input): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value) && !is_numeric($value)) throw new \InvalidArgumentException(self::LABELS[$field] . '格式不正确');
            $out[$field] = mb_substr(trim((string)$value), 0, 2000);
        }
        return $out;
    }

    public static function publicFields(array $counselor): array
    {
        $out = [];
        foreach (self::FIELDS as $field) $out[$field] = trim((string)($counselor[$field] ?? ''));
        return $out;
    }

    public static function baseHash(array $counselor): string
    {
        return hash('sha256', json_encode(self::publicFields($counselor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function decodeDraft($json, array $counselor): array
    {
        $draft = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($draft)) $draft = self::publicFields($counselor);
        $fields = [];
        foreach (self::FIELDS as $field) $fields[$field] = mb_substr(trim((string)($draft[$field] ?? '')), 0, 2000);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($draft['attachment_ids'] ?? null) ? $draft['attachment_ids'] : []), fn($id) => $id > 0)));
        return $fields + ['attachment_ids' => $ids];
    }

    public static function encodeDraft(array $draft): string
    {
        return json_encode(self::normalizeDraft($draft), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalizeDraft(array $draft): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) $fields[$field] = mb_substr(trim((string)($draft[$field] ?? '')), 0, 2000);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($draft['attachment_ids'] ?? null) ? $draft['attachment_ids'] : []), fn($id) => $id > 0)));
        return $fields + ['attachment_ids' => $ids];
    }

    public static function pendingFor(int $counselorId, bool $lock = false): ?array
    {
        return Database::fetch(
            'SELECT * FROM counselor_profile_submissions WHERE counselor_id=? AND status=\'pending\' ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$counselorId]
        ) ?: null;
    }

    public static function latestFor(int $counselorId): ?array
    {
        return Database::fetch('SELECT * FROM counselor_profile_submissions WHERE counselor_id=? ORDER BY id DESC LIMIT 1', [$counselorId]) ?: null;
    }

    public static function saveDraft(int $counselorId, int $actorUserId, array $input): array
    {
        return Database::transaction(function () use ($counselorId, $actorUserId, $input): array {
            $counselor = Database::fetch('SELECT * FROM counselors WHERE id=? AND user_id=? FOR UPDATE', [$counselorId, $actorUserId]);
            if (!$counselor) throw new \InvalidArgumentException('无权修改该咨询师资料');
            if (self::pendingFor($counselorId, true)) throw new \InvalidArgumentException('资质资料正在审核中，暂不可修改');
            $fields = self::normalizeFields($input);
            $rawIds = $input['attachment_ids'] ?? [];
            if (!is_array($rawIds)) throw new \InvalidArgumentException('附件列表格式不正确');
            $ids = self::cleanIds($rawIds);
            self::assertAttachments($counselorId, $ids);
            $draft = $fields + ['attachment_ids' => $ids];
            Database::update('counselors', [
                'profile_draft' => self::encodeDraft($draft),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=? AND user_id=?', [$counselorId, $actorUserId]);
            self::pruneUnassigned($counselorId, $ids);
            return $draft;
        });
    }

    public static function submit(int $counselorId, int $actorUserId): array
    {
        return Database::transaction(function () use ($counselorId, $actorUserId): array {
            $counselor = Database::fetch('SELECT * FROM counselors WHERE id=? AND user_id=? FOR UPDATE', [$counselorId, $actorUserId]);
            if (!$counselor) throw new \InvalidArgumentException('无权提交该咨询师资料');
            if (self::pendingFor($counselorId, true)) throw new \InvalidArgumentException('已有资质资料正在审核中');
            $draft = self::decodeDraft($counselor['profile_draft'] ?? null, $counselor);
            if (trim(implode('', self::publicFields($draft))) === '') throw new \InvalidArgumentException('请至少填写一项资质、注册或督导信息');
            self::assertAttachments($counselorId, $draft['attachment_ids']);
            $now = date('Y-m-d H:i:s');
            $id = Database::insert('counselor_profile_submissions', [
                'counselor_id' => $counselorId,
                'payload' => self::encodeDraft($draft),
                'base_hash' => self::baseHash($counselor),
                'status' => 'pending',
                'submitted_by' => $actorUserId,
                'submitted_at' => $now,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
                'created_at' => $now,
            ]);
            if ($id < 1) throw new \RuntimeException('资质审核提交失败');
            if ($draft['attachment_ids']) {
                $placeholders = implode(',', array_fill(0, count($draft['attachment_ids']), '?'));
                Database::run(
                    "UPDATE counselor_profile_attachments SET submission_id=? WHERE counselor_id=? AND submission_id IS NULL AND id IN ($placeholders)",
                    array_merge([$id, $counselorId], $draft['attachment_ids'])
                );
            }
            $submission = Database::fetch('SELECT * FROM counselor_profile_submissions WHERE id=?', [$id]);
            if (!$submission) throw new \RuntimeException('资质审核提交读取失败');
            return $submission;
        });
    }

    public static function review(int $submissionId, string $action, int $actorId, string $note = ''): array
    {
        if (!in_array($action, ['approve', 'reject'], true)) throw new \InvalidArgumentException('审核动作不合法');
        $note = mb_substr(trim($note), 0, 500);
        if ($action === 'reject' && $note === '') throw new \InvalidArgumentException('驳回时必须填写原因');
        return Database::transaction(function () use ($submissionId, $action, $actorId, $note): array {
            $submission = Database::fetch('SELECT * FROM counselor_profile_submissions WHERE id=? FOR UPDATE', [$submissionId]);
            if (!$submission) throw new \InvalidArgumentException('资质审核单不存在');
            if ((string)$submission['status'] !== 'pending') throw new \InvalidArgumentException('该资质审核单已处理');
            $counselor = Database::fetch('SELECT * FROM counselors WHERE id=? FOR UPDATE', [(int)$submission['counselor_id']]);
            if (!$counselor) throw new \InvalidArgumentException('咨询师不存在');
            $draft = self::decodeDraft((string)$submission['payload'], $counselor);
            $now = date('Y-m-d H:i:s');
            if ($action === 'approve') {
                if (!hash_equals((string)$submission['base_hash'], self::baseHash($counselor))) {
                    throw new \InvalidArgumentException('公开资料已被管理员重新编辑，请驳回并让咨询师基于最新内容重新提交');
                }
                $update = self::publicFields($draft);
                $update['profile_draft'] = null;
                $update['updated_at'] = $now;
                Database::update('counselors', $update, 'id=?', [(int)$submission['counselor_id']]);
                Database::update('counselor_profile_submissions', [
                    'status' => 'approved', 'reviewed_by' => $actorId, 'reviewed_at' => $now, 'review_note' => $note ?: null,
                ], 'id=?', [$submissionId]);
                self::notify((int)$submission['counselor_id'], '资质资料审核已通过', '您提交的资质、注册与督导资料已批准发布。', '/admin/counselor-home/profile');
            } else {
                Database::update('counselor_profile_submissions', [
                    'status' => 'rejected', 'reviewed_by' => $actorId, 'reviewed_at' => $now, 'review_note' => $note,
                ], 'id=?', [$submissionId]);
                $draft['attachment_ids'] = [];
                Database::update('counselors', [
                    'profile_draft' => self::encodeDraft($draft), 'updated_at' => $now,
                ], 'id=?', [(int)$submission['counselor_id']]);
                self::notify((int)$submission['counselor_id'], '资质资料审核未通过', '请查看驳回原因并修改后重新提交。', '/admin/counselor-home/profile');
            }
            $result = Database::fetch('SELECT * FROM counselor_profile_submissions WHERE id=?', [$submissionId]);
            if (!$result) throw new \RuntimeException('资质审核结果读取失败');
            return $result;
        });
    }

    public static function attachments(int $counselorId, ?int $submissionId = null): array
    {
        if ($submissionId === null) {
            return Database::fetchAll('SELECT * FROM counselor_profile_attachments WHERE counselor_id=? AND submission_id IS NULL ORDER BY id', [$counselorId]);
        }
        return Database::fetchAll('SELECT * FROM counselor_profile_attachments WHERE counselor_id=? AND submission_id=? ORDER BY id', [$counselorId, $submissionId]);
    }

    public static function uploadEvidence(int $counselorId, int $actorUserId, array $file): array
    {
        $counselor = Database::fetch('SELECT * FROM counselors WHERE id=? AND user_id=?', [$counselorId, $actorUserId]);
        if (!$counselor) throw new \InvalidArgumentException('无权上传该咨询师证明');
        if (self::pendingFor($counselorId)) throw new \InvalidArgumentException('资质资料正在审核中，暂不可上传');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('上传失败');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new \InvalidArgumentException('证明文件大小不能超过 10MB');
        $original = basename((string)($file['name'] ?? 'proof'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mimes = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg', 'image/pjpeg'], 'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png' => ['image/png'],
        ];
        if (!isset($mimes[$ext])) throw new \InvalidArgumentException('仅支持 PDF、JPG、PNG 证明文件');
        $content = @file_get_contents((string)($file['tmp_name'] ?? ''));
        if ($content === false || strlen($content) !== $size) throw new \InvalidArgumentException('读取证明文件失败');
        if (preg_match('/<\?php|<\?=|\<script/i', $content)) throw new \InvalidArgumentException('证明文件内容不安全，已拒绝');
        $mime = \app\Core\Uploader::detectMime((string)$file['tmp_name'], $content, $ext);
        if (!in_array($mime, $mimes[$ext], true)) throw new \InvalidArgumentException('证明文件类型与扩展名不符');
        $image = in_array($ext, ['jpg', 'jpeg', 'png'], true);
        if ($image && !@getimagesize((string)$file['tmp_name'])) throw new \InvalidArgumentException('不是有效的图片证明文件');

        $relativeDir = 'private/counselor-evidence/' . date('Ym');
        $dir = STORAGE . '/' . $relativeDir;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new \RuntimeException('私有证明目录创建失败');
        @chmod($dir, 0700);
        $name = date('d') . bin2hex(random_bytes(12)) . '.' . $ext;
        $destination = $dir . '/' . $name;
        if ($image) {
            $source = $ext === 'png' ? @imagecreatefrompng((string)$file['tmp_name']) : @imagecreatefromjpeg((string)$file['tmp_name']);
            if (!$source) throw new \InvalidArgumentException('图片证明处理失败');
            $saved = $ext === 'png' ? imagepng($source, $destination, 6) : imagejpeg($source, $destination, 90);
            imagedestroy($source);
            if (!$saved) { @unlink($destination); throw new \RuntimeException('证明文件保存失败'); }
        } elseif (!@move_uploaded_file((string)$file['tmp_name'], $destination)) {
            throw new \RuntimeException('证明文件保存失败');
        }
        @chmod($destination, 0600);
        $final = @file_get_contents($destination);
        if ($final === false) { @unlink($destination); throw new \RuntimeException('证明文件校验失败'); }
        try {
            $id = Database::insert('counselor_profile_attachments', [
                'counselor_id' => $counselorId, 'submission_id' => null,
                'original_name' => mb_substr($original, 0, 190), 'stored_path' => $relativeDir . '/' . $name,
                'mime' => $mime, 'size' => strlen($final), 'sha256' => hash('sha256', $final),
                'created_by' => $actorUserId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            @unlink($destination);
            throw $e;
        }
        if ($id < 1) { @unlink($destination); throw new \RuntimeException('证明文件登记失败'); }
        return Database::fetch('SELECT * FROM counselor_profile_attachments WHERE id=?', [$id]) ?: [];
    }

    public static function evidenceForViewer(int $attachmentId, int $counselorId, bool $admin): ?array
    {
        $row = Database::fetch('SELECT * FROM counselor_profile_attachments WHERE id=?', [$attachmentId]);
        if (!$row || (!$admin && (int)$row['counselor_id'] !== $counselorId)) return null;
        return $row;
    }

    public static function streamEvidence(array $attachment): void
    {
        $base = realpath(STORAGE . '/private/counselor-evidence');
        $path = realpath(STORAGE . '/' . (string)$attachment['stored_path']);
        if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            http_response_code(404); echo 'not found'; exit;
        }
        $mime = (string)$attachment['mime'];
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/pjpeg', 'image/png'], true)) $mime = 'application/octet-stream';
        $original = (string)$attachment['original_name'];
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($original)) ?: 'proof';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($original));
        header('Content-Length: ' . (int)$attachment['size']);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    }

    private static function assertAttachments(int $counselorId, array $ids): void
    {
        if (!$ids) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $count = (int)Database::value(
            "SELECT COUNT(*) FROM counselor_profile_attachments WHERE counselor_id=? AND submission_id IS NULL AND id IN ($placeholders)",
            array_merge([$counselorId], $ids)
        );
        if ($count !== count($ids)) throw new \InvalidArgumentException('附件不存在、无权使用或已提交');
    }

    private static function pruneUnassigned(int $counselorId, array $keepIds): void
    {
        $rows = Database::fetchAll('SELECT * FROM counselor_profile_attachments WHERE counselor_id=? AND submission_id IS NULL', [$counselorId]);
        foreach ($rows as $row) {
            if (in_array((int)$row['id'], $keepIds, true)) continue;
            $base = realpath(STORAGE . '/private/counselor-evidence');
            $path = realpath(STORAGE . '/' . (string)$row['stored_path']);
            if ($base && $path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) @unlink($path);
            Database::delete('counselor_profile_attachments', 'id=? AND counselor_id=? AND submission_id IS NULL', [(int)$row['id'], $counselorId]);
        }
    }

    private static function notify(int $counselorId, string $title, string $body, string $url): void
    {
        Database::insert('counselor_notifications', [
            'counselor_id' => $counselorId, 'kind' => 'system',
            'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 500),
            'url' => $url, 'booking_id' => null, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
