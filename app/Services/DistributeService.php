<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Cache;
use app\Core\Database;
use app\Core\Logger;
use app\Core\RateLimiter;

/**
 * 升级分发主站服务（ww 对标主站专用）
 *
 * 角色开关：config.distribute.enabled=true 且持有 Ed25519 私钥（config.distribute.private_key）。
 * 客户站凭「授权码 + 绑定域名 + 绑定 IP」三重校验通过后，才能：
 *   check   —— 查询最新核心包与插件包版本（Ed25519 签名应答）
 *   token   —— 换取一次性下载令牌（10 分钟、单次使用）
 *   download—— 凭令牌拉取升级包 zip 流
 * 永不下发/覆盖：客户站业务数据（文章、站点名等 settings）、config/config.php 凭据、uploads。
 * 升级包本身只含代码（build-release.php 白名单构建），客户站 Updater 再做目录白名单二次防护。
 */
class DistributeService
{
    private const TOKEN_TTL = 600;           // 下载令牌有效期（秒）
    private const NONCE_TTL = 600;           // nonce 防重放窗口
    private const RATE_MAX = 30;             // 每 IP 15 分钟最大请求数
    private const RATE_WINDOW = 900;

    public static function enabled(): bool
    {
        $cfg = (array)(CONFIG['distribute'] ?? []);
        return !empty($cfg['enabled']) && self::privKey() !== '';
    }

    private static function privKey(): string
    {
        static $k = null;
        if ($k !== null) return $k;
        $raw = base64_decode((string)(CONFIG['distribute']['private_key'] ?? ''), true);
        if ($raw === false || strlen($raw) !== 64) return '';
        return $raw;
    }

    /** Ed25519 签发（payload → b64 + sig） */
    public static function sign(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = sodium_crypto_sign_detached($json, self::privKey());
        return ['payload_b64' => base64_encode($json), 'signature_b64' => base64_encode($sig)];
    }

    /** 客户端验签下载令牌（客户站使用：公钥 = config.issuer_public_key） */
    public static function clientVerify(string $payloadB64, string $sigB64): ?array
    {
        $pub = base64_decode((string)(CONFIG['issuer_public_key'] ?? ''), true);
        if ($pub === false || strlen($pub) !== 32) return null;
        $payloadJson = base64_decode($payloadB64, true);
        $sig = base64_decode($sigB64, true);
        if ($payloadJson === false || $sig === false) return null;
        if (!sodium_crypto_sign_verify_detached($sig, $payloadJson, $pub)) return null;
        $payload = json_decode($payloadJson, true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * 分发三重校验（授权码 + 域名 + IP）+ nonce 防重放 + 限流
     * @return array [0]=>?array 授权行（null=拒绝），[1]=>string 失败原因，[2]=>int HTTP 状态码
     */
    public static function guard(string $license, string $domain, string $nonce, string $ip): array
    {
        $license = strtoupper(trim($license));
        $domain = strtolower(trim($domain));
        $ip = trim($ip);
        $fail = function (string $why, int $code = 403) use ($license, $domain, $ip, $nonce) {
            self::log('deny', $license, $domain, $ip, $why);
            // 失败也计入限流，防止暴力枚举授权码
            RateLimiter::hit('upgrade-api', $ip . '|' . $license, self::RATE_MAX, self::RATE_WINDOW);
            return [null, $why, $code];
        };
        if ($license === '' || $domain === '' || $nonce === '') return $fail('参数缺失', 400);
        if (!preg_match('/^PSY-[A-Z]+-/', $license)) return $fail('授权码格式不正确', 400);
        if (!RateLimiter::hit('upgrade-api', $ip . '|' . $license, self::RATE_MAX, self::RATE_WINDOW)) {
            return $fail('请求过于频繁，请稍后再试', 429);
        }

        $lic = Database::fetch('SELECT * FROM licenses WHERE license_key=?', [$license]);
        if (!$lic) return $fail('授权码不存在');
        if ($lic['status'] !== 'active') return $fail('授权状态异常：' . $lic['status']);
        if ($lic['expires_at'] && strtotime((string)$lic['expires_at']) < time()) return $fail('授权已过期');

        // 域名绑定是远程升级的基本要求（trial/personal 未绑域名 → 只能手动上传包升级）
        if (empty($lic['domain'])) return $fail('该授权未绑定域名，不支持远程升级');
        if (!hash_equals((string)$lic['domain'], $domain)) return $fail('域名与授权绑定不符');
        if (isset($lic['update_enabled']) && !(int)$lic['update_enabled']) return $fail('该授权已被关闭远程升级');

        // IP 绑定：首次请求自动锁定，之后严格匹配
        if (empty($lic['client_ip'])) {
            Database::run('UPDATE licenses SET client_ip=?, last_ip=?, last_check_at=? WHERE id=?',
                [$ip, $ip, date('Y-m-d H:i:s'), (int)$lic['id']]);
            self::log('bind', $license, $domain, $ip, '首次分发请求，IP 已绑定');
        } else {
            $bound = array_map('trim', explode(',', (string)$lic['client_ip']));
            if (!in_array($ip, $bound, true)) return $fail('IP 与绑定不符（绑定：' . $lic['client_ip'] . '）');
            Database::run('UPDATE licenses SET last_ip=?, last_check_at=? WHERE id=?',
                [$ip, date('Y-m-d H:i:s'), (int)$lic['id']]);
        }

        // nonce 防重放
        $nk = 'upnonce:' . hash('sha256', $license . '|' . $nonce);
        if (Cache::get($nk)) return $fail('nonce 已使用（疑似重放）');
        Cache::set($nk, 1, self::NONCE_TTL);

        return [$lic, 'ok', 200];
    }

    /** 最新 active 核心包 */
    public static function latestCore(): ?array
    {
        return Database::fetch("SELECT * FROM upgrade_packages WHERE kind='core' AND status='active' ORDER BY version DESC LIMIT 1");
    }

    /** 全部 active 插件包 */
    public static function activePlugins(): array
    {
        return Database::fetchAll("SELECT * FROM upgrade_packages WHERE kind='plugin' AND status='active' ORDER BY slug");
    }

    /** 校验 check 应答（客户站用）：有可用更新则返回包信息，否则 null */
    public static function evaluateCheck(?array $payload, string $nonce, string $license, string $domain): ?array
    {
        if (!$payload) return null;
        if (($payload['act'] ?? '') !== 'check') return null;
        if (($payload['nonce'] ?? '') !== $nonce) return null;
        if (strtolower((string)($payload['domain'] ?? '')) !== $domain) return null;
        if (($payload['license'] ?? '') !== $license) return null;
        if ((int)($payload['expires_at'] ?? 0) < time()) return null;
        return $payload['update'] ?? null;
    }

    /** 签发一次性下载令牌（主站） */
    public static function issueToken(array $lic, array $pkg, string $nonce): array
    {
        $payload = [
            'act' => 'download',
            'license' => (string)$lic['license_key'],
            'domain' => (string)$lic['domain'],
            'kind' => (string)$pkg['kind'],
            'slug' => $pkg['slug'] !== null ? (string)$pkg['slug'] : null,
            'version' => (string)$pkg['version'],
            'file' => basename((string)$pkg['file_path']),   // 只允许文件名，防路径穿越
            'md5' => (string)$pkg['md5'],
            'nonce' => $nonce,
            'expires_at' => time() + self::TOKEN_TTL,
            'issued_at' => date('c'),
        ];
        $t = self::sign($payload);
        self::log('token', (string)$lic['license_key'], (string)$lic['domain'], (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'kind=' . $pkg['kind'] . ' v=' . $pkg['version']);
        return $t;
    }

    /** 下载令牌验签 + 一次性消费（主站） */
    public static function consumeToken(string $payloadB64, string $sigB64): array
    {
        $deny = fn (string $why) => [null, $why];
        // 用自身私钥派生公钥验签（令牌必须出自本站）
        $pair = self::privKey();
        $pub = sodium_crypto_sign_publickey_from_secretkey($pair);
        $payloadJson = base64_decode($payloadB64, true);
        $sig = base64_decode($sigB64, true);
        if ($payloadJson === false || $sig === false || !sodium_crypto_sign_verify_detached($sig, $payloadJson, $pub)) {
            return $deny('令牌签名无效');
        }
        $p = json_decode($payloadJson, true);
        if (!is_array($p) || ($p['act'] ?? '') !== 'download') return $deny('令牌内容异常');
        if ((int)($p['expires_at'] ?? 0) < time()) return $deny('令牌已过期');
        $nk = 'dltoken:' . hash('sha256', (string)($p['nonce'] ?? '') . '|' . (string)($p['file'] ?? ''));
        if (Cache::get($nk)) return $deny('令牌已使用');
        Cache::set($nk, 1, self::TOKEN_TTL + 60);
        return [$p, 'ok'];
    }

    /** 包登记（上传后调用）；返回 [ok, msg] */
    public static function register(string $kind, string $file, string $md5, int $size, string $version, string $slug = '', string $minFrom = '', string $changelog = ''): array
    {
        if (!in_array($kind, ['core', 'plugin'], true)) return [false, 'kind 非法'];
        if ($kind === 'core' && !preg_match('/^\d+\.\d+\.\d+$/', $version)) return [false, '核心版本号非法'];
        // slug 用 NULL 安全等值比较（核心包 slug=NULL，`slug=NULL` 永不匹配会导致同版本重登记插重复行）
        $exists = Database::fetch("SELECT id FROM upgrade_packages WHERE kind=? AND slug<=>? AND version=?", [$kind, $slug ?: null, $version]);
        $row = [
            'kind' => $kind, 'slug' => $slug ?: null, 'version' => $version,
            'file_path' => 'storage/releases/' . $file, 'md5' => $md5, 'size' => $size,
            'min_from' => $minFrom ?: null, 'changelog' => $changelog ?: null,
            'status' => 'active', 'released_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ];
        if ($exists) {
            Database::run("UPDATE upgrade_packages SET file_path=?, md5=?, size=?, status='active', released_at=? WHERE id=?",
                [$row['file_path'], $md5, $size, $row['released_at'], (int)$exists['id']]);
            // 新版登记后旧版本自动退役（core）
            if ($kind === 'core') {
                Database::run("UPDATE upgrade_packages SET status='retired' WHERE kind='core' AND id<>? AND status='active'", [(int)$exists['id']]);
            }
        } else {
            Database::insert('upgrade_packages', $row);
            // 新版登记后同 kind 同 slug 的旧版本自动退役（core 与 plugin 一致）
            if ($kind === 'core') {
                Database::run("UPDATE upgrade_packages SET status='retired' WHERE kind='core' AND version<>? AND status='active'", [$version]);
            } else {
                Database::run("UPDATE upgrade_packages SET status='retired' WHERE kind='plugin' AND slug=? AND version<>? AND status='active'", [$slug, $version]);
            }
        }
        return [true, '已登记 ' . ($kind === 'core' ? '核心' : '插件 ' . $slug) . ' ' . $version];
    }

    /** 包磁盘完整性（登记 md5 与当前文件比对） */
    public static function packageFile(?array $pkg): ?string
    {
        if (!$pkg) return null;
        $path = ROOT . '/' . $pkg['file_path'];
        if (!is_file($path)) return null;
        if (!hash_equals((string)$pkg['md5'], (string)md5_file($path))) return null; // 磁盘文件被篡改即拒发
        return $path;
    }

    public static function log(string $action, string $license, string $domain, string $ip, string $detail = '', string $result = 'ok'): void
    {
        try {
            Database::insert('upgrade_api_logs', [
                'action' => $action, 'license' => $license ?: null, 'domain' => $domain ?: null,
                'ip' => $ip ?: null, 'result' => $result, 'detail' => str_take($detail, 240, ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('upgrade', 'upgrade_api_log: ' . $t->getMessage());
        }
    }
}
