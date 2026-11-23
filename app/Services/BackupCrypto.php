<?php
declare(strict_types=1);

namespace app\Services;

/**
 * 备份文件加密（v2.21.0 · P0-G4）
 *
 * 背景：此前备份是明文 gz/zip，含会员手机号、姓名、咨询记录、知情同意正文；
 *       文件一旦被拷走或经任何路径下载，等于一次性泄露全部来访者隐私。
 * 方案：AES-256-CBC + 随机 IV，密钥由 config.secret_key 派生（与 Crypto 同源但加域前缀）。
 * 文件格式：`PSYENC1` + 16 字节 IV + 密文（PKCS7）。二进制直接落盘，base64 只在需要时手工转。
 * 边界：密钥与备份同机（config.php 0600）——防的是"文件被拷走"，不防"整机被控"；
 *       异地副本与恢复演练见 docs/OPS.md「备份与恢复」。
 */
class BackupCrypto
{
    private const MAGIC = 'PSYENC1';
    /** 单文件上限：超过则拒绝（避免整文件读入打爆内存） */
    private const MAX_BYTES = 268435456; // 256MB

    public static function isEncrypted(string $file): bool
    {
        $fp = @fopen($file, 'rb');
        if (!$fp) return false;
        $head = (string)fread($fp, 7);
        fclose($fp);
        return $head === self::MAGIC;
    }

    public static function encryptFile(string $src, string $dest): bool
    {
        $size = @filesize($src);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) return false;
        $plain = @file_get_contents($src);
        if ($plain === false) return false;
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        unset($plain);
        if ($ct === false) return false;
        $ok = @file_put_contents($dest, self::MAGIC . $iv . $ct, LOCK_EX) !== false;
        if ($ok) @chmod($dest, 0600);
        return $ok;
    }

    public static function decryptFile(string $src, string $dest): bool
    {
        $size = @filesize($src);
        if ($size === false || $size <= 23 || $size > self::MAX_BYTES) return false;
        $raw = @file_get_contents($src);
        if ($raw === false || strncmp($raw, self::MAGIC, 7) !== 0) return false;
        $iv = substr($raw, 7, 16);
        $ct = substr($raw, 23);
        $pt = openssl_decrypt($ct, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($pt === false) return false;
        return @file_put_contents($dest, $pt, LOCK_EX) !== false;
    }

    private static function key(): string
    {
        $raw = (string)(CONFIG['secret_key'] ?? '');
        if ($raw === '') {
            $raw = 'fallback|' . (string)(CONFIG['stats_salt'] ?? '');
        }
        return hash('sha256', 'backup|' . $raw, true);
    }
}
