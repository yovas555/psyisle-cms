<?php
declare(strict_types=1);

namespace app\Services;

class CorePackageSignature
{
    public const ALGORITHM = 'ed25519-sha256-v1';

    public static function sign(string $secretKey, array $manifest, array $entries): array
    {
        $rawSecret = base64_decode($secretKey, true);
        if ($rawSecret === false || strlen($rawSecret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('发行方 Ed25519 私钥格式无效');
        }
        $signed = $manifest;
        unset($signed['issuer_signature']);
        $signed['files_sha256'] = self::fileHashes($entries);
        self::normalize($signed);
        ksort($signed, SORT_STRING);
        $signed['issuer_signature'] = base64_encode(sodium_crypto_sign_detached(self::canonicalJson($signed), $rawSecret));
        return $signed;
    }

    public static function verify(array $manifest, array $entries): array
    {
        if (($manifest['core_signature'] ?? '') !== self::ALGORITHM) {
            return [false, '核心包 Ed25519 签名算法声明缺失或不支持'];
        }
        $public = base64_decode((string)(CONFIG['issuer_public_key'] ?? ''), true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return [false, '本站缺少有效 issuer_public_key，拒绝校验核心包'];
        }
        $files = $manifest['files_sha256'] ?? null;
        if (!is_array($files) || $files === []) return [false, '核心包缺少逐文件 SHA-256 清单'];
        $expectedFiles = self::fileHashes($entries);
        if (count($files) !== count($expectedFiles)) return [false, '核心包文件哈希清单与包内文件数量不符'];
        foreach ($expectedFiles as $name => $digest) {
            if (!isset($files[$name]) || !is_string($files[$name]) || !hash_equals($digest, $files[$name])) {
                return [false, '核心包文件 SHA-256 校验失败：' . $name];
            }
        }
        $signed = $manifest;
        unset($signed['issuer_signature']);
        self::normalize($signed);
        ksort($signed, SORT_STRING);
        $signature = base64_decode((string)($manifest['issuer_signature'] ?? ''), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, self::canonicalJson($signed), $public)) {
            return [false, '核心包 Ed25519 签名校验失败'];
        }
        return [true, ''];
    }

    public static function publicKey(string $secretKey): string
    {
        $rawSecret = base64_decode($secretKey, true);
        if ($rawSecret === false || strlen($rawSecret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('发行方 Ed25519 私钥格式无效');
        }
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($rawSecret));
    }

    private static function fileHashes(array $entries): array
    {
        $files = [];
        foreach ($entries as $name => $entry) {
            if ($name === 'version.json') continue;
            if (!is_string($entry['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $entry['sha256'])) {
                throw new \InvalidArgumentException('包内文件哈希格式无效：' . $name);
            }
            $files[$name] = $entry['sha256'];
        }
        if ($files === []) throw new \InvalidArgumentException('核心包文件哈希清单不得为空');
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function normalize(array &$manifest): void
    {
        $requirements = $manifest['plugin_policy']['require_plugins'] ?? null;
        if (is_array($requirements)) {
            ksort($requirements, SORT_STRING);
            $manifest['plugin_policy']['require_plugins'] = (object)$requirements;
        }
    }

    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
