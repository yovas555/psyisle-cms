<?php
declare(strict_types=1);

namespace app\Services;

final class CounselorProfile
{
    public const MODES = ['面对面', '视频', '电话'];

    public static function modes($value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[,，、\/|\s]+/u', (string)$value);
        $out = [];
        foreach ($parts ?: [] as $mode) {
            $mode = trim((string)$mode);
            if (in_array($mode, self::MODES, true) && !in_array($mode, $out, true)) $out[] = $mode;
        }
        return $out;
    }

    public static function allows(array $counselor, string $method): bool
    {
        $modes = self::modes($counselor['service_modes'] ?? '');
        return in_array($method, self::MODES, true) && (!$modes || in_array($method, $modes, true));
    }
}
