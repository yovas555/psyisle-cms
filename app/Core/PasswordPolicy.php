<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 统一密码策略：至少 8 位，大写字母、小写字母、数字、特殊字符任选两类或以上。
 */
final class PasswordPolicy
{
    public const MIN_LEN = 8;
    public const RULE_TEXT = '密码至少 8 位，大写字母、小写字母、数字、特殊字符任选两类或以上；不超过 72 字节';

    /** 校验：合法返回 null，不合法返回错误文案 */
    public static function check(string $pwd): ?string
    {
        if (strlen($pwd) > 72 || strpos($pwd, "\0") !== false || preg_match('//u', $pwd) !== 1) {
            return self::RULE_TEXT;
        }
        if (mb_strlen($pwd) < self::MIN_LEN) {
            return self::RULE_TEXT;
        }
        $classes = 0;
        if (preg_match('/[A-Z]/', $pwd)) $classes++;
        if (preg_match('/[a-z]/', $pwd)) $classes++;
        if (preg_match('/\d/', $pwd)) $classes++;
        if (preg_match('/[^\p{L}\p{N}\s]/u', $pwd)) $classes++;
        return $classes < 2 ? self::RULE_TEXT : null;
    }
}
