<?php
declare(strict_types=1);

namespace app\Core;

/** TOTP 双因素（RFC 6238，SHA1/6位/30s，兼容 Google Authenticator 等）零依赖实现 */
class Totp
{
    /** RFC 4648 Base32（无填充） */
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 16): string
    {
        return self::b32encode(random_bytes($bytes));
    }

    public static function b32encode(string $raw): string
    {
        $out = '';
        $bits = '';
        foreach (str_split($raw) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) { $chunk = str_pad($chunk, 5, '0'); }
            $out .= self::B32[bindec($chunk)];
        }
        return $out;
    }

    public static function b32decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::B32, $c);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $raw = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) { $raw .= chr(bindec($byte)); }
        }
        return $raw;
    }

    /** 当前 6 位码 */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $counter = intdiv($ts, 30);
        $binary = pack('N', 0) . pack('N', $counter); // 8 字节大端
        $hash = hash_hmac('sha1', $binary, self::b32decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** 校验（±1 窗口防时钟漂移） */
    public static function verify(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $ts = time();
        foreach ([$ts, $ts - 30, $ts + 30] as $t) {
            if (hash_equals(self::code($secret, $t), $code)) return true;
        }
        return false;
    }

    /** otpauth URI（验证器 App 手动录入用） */
    public static function uri(string $secret, string $site, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode($site . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($site) . '&algorithm=SHA1&digits=6&period=30';
    }
}
