<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 敏感配置加密（AES-256-GCM）
 * 数据库中的 SMTP 密码、AI Key、API Token 以 `enc:` 前缀密文存储，读取时自动解密。
 * 密钥来源：config.secret_key（安装时生成）→ 派生 32 字节；缺失时回退到 stats_salt 派生（仍可解密旧数据）。
 */
class Crypto
{
    private const PREFIX = 'enc:';

    private static function key(): string
    {
        static $k = null;
        if ($k !== null) return $k;
        $raw = (string)(CONFIG['secret_key'] ?? '');
        if ($raw === '') {
            // 兼容：未配置 secret_key 时用 stats_salt + 固定盐派生（不理想但可解旧数据）
            $raw = 'fallback|' . (string)(CONFIG['stats_salt'] ?? '');
        }
        $k = hash('sha256', $raw, true);
        return $k;
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) return $plain; // 无 openssl 时降级（不加密）
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) return $plain;
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $value): string
    {
        if (strpos($value, self::PREFIX) !== 0) return $value;
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return '';
        $pt = self::decryptRaw($raw, self::key());
        if ($pt !== null) return $pt;
        $statsSalt = (string)(CONFIG['stats_salt'] ?? '');
        if ($statsSalt !== '') {
            $pt = self::decryptRaw($raw, hash('sha256', 'fallback|' . $statsSalt, true));
            if ($pt !== null) return $pt;
        }
        return '';
    }

    private static function decryptRaw(string $raw, string $key): ?string
    {
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }

    public static function isEncrypted($value): bool
    {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    /** 是否为「已保存」掩码（前端未修改密码时的提交值） */
    public static function isMask(string $value): bool
    {
        $v = trim($value);
        return $v === '' || preg_match('/^[•\*\x{2022}]{4,}$/u', $v) === 1;
    }

    /** 敏感设置键（保存时加密、展示时掩码） */
    public static function isSecretKey(string $key): bool
    {
        return in_array($key, ['mail.pass', 'ai.api_key', 'api.token'], true)
            || (bool)preg_match('/(?:^|[._])(?:pass|password|secret|secret_key|token|api_key|app_key|certificate)$/i', $key);
    }
}
