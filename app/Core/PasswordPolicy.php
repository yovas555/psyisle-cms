<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 统一密码策略（v2.17.0，2026-11-19 用户拍板）
 * ------------------------------------------------------------------
 * 管理员与会员同规：**至少 8 位，须同时包含大写字母、小写字母与数字**。
 * （此前管理员 10 位三类过严不便记忆；会员 8 位仅字母+数字偏弱，现统一。）
 * 符号可选。历史密码禁重用逻辑仍在 Admin\User（password_history）。
 */
final class PasswordPolicy
{
    public const MIN_LEN = 8;
    public const RULE_TEXT = '密码至少 8 位，须包含大写字母、小写字母和数字';

    /** 校验：合法返回 true，不合法返回错误文案 */
    public static function check(string $pwd): ?string
    {
        if (mb_strlen($pwd) < self::MIN_LEN) {
            return self::RULE_TEXT;
        }
        if (!preg_match('/[A-Z]/', $pwd) || !preg_match('/[a-z]/', $pwd) || !preg_match('/\d/', $pwd)) {
            return self::RULE_TEXT;
        }
        return null;
    }
}
