<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;

/**
 * 商业授权 v2（防破解升级）
 *
 * 信任模型：
 *  1) 远程激活（首选）：activate_url 指向发行方激活服务器，返回 Ed25519 签名 token；
 *     客户站持公钥（config.issuer_public_key）验签——无私钥无法伪造，改文件/改库均无效。
 *     token 有效期 7 天，cron 每日心跳续期；最后一次有效心跳 +7 天为离线宽限期。
 *  2) 离线应急（次选）：发行方手工签发的 HMAC 授权码（license_secret 本地验签），
 *     仅在远程不可达时使用，features 固定、不可续期。
 *  3) 完整性自校验：核心文件 sha256 基线（storage/integrity.json），被篡改即告警。
 *
 * 可用性优先：宽限超期不锁站点，仅降级高级功能（ai_chat）+ 后台横幅 + 邮件提醒。
 */
class LicenseService
{
    /** 四档商业授权（v2.1）：个人版 180 天 / 工作室年费 / 专业年费（多门店）/ 永久 */
    private const EDITION_FEATURES = [
        // 未激活时的兜底矩阵（功能等同个人试用的全功能）
        'trial'    => ['ai_chat' => true, 'booking' => true, 'max_counselors' => 1,  'remove_brand' => false, 'multisite' => false],
        'personal' => ['ai_chat' => true, 'booking' => true, 'max_counselors' => 1,  'remove_brand' => false, 'multisite' => false],
        'studio'   => ['ai_chat' => true, 'booking' => true, 'max_counselors' => 5,  'remove_brand' => false, 'multisite' => false],
        'pro'      => ['ai_chat' => true, 'booking' => true, 'max_counselors' => 20, 'remove_brand' => false, 'multisite' => true],
        'lifetime' => ['ai_chat' => true, 'booking' => true, 'max_counselors' => 99, 'remove_brand' => true,  'multisite' => true, 'commercial_plugins' => true],
    ];
    private const EDITION_NAMES = [
        'trial' => '试用', 'personal' => '个人版（180天）', 'studio' => '工作室版',
        'pro' => '专业版（多门店）', 'lifetime' => '永久授权版',
    ];
    /** 各版本默认有效期（天），null = 永久 */
    private const EDITION_DAYS = [
        'trial' => 15, 'personal' => 180, 'studio' => 365, 'pro' => 365, 'lifetime' => null,
    ];
    private const GRACE_DAYS = 7;

    public static function current(): ?array
    {
        static $lic = null;
        if ($lic === null) { $lic = Database::fetch('SELECT * FROM licenses ORDER BY id DESC LIMIT 1'); }
        return $lic;
    }

    public static function editionName(string $ed): string
    {
        return self::EDITION_NAMES[$ed] ?? '未知版本';
    }

    /** 签名（离线应急码 / 升级包校验共用） */
    public static function sign(string $body): string
    {
        $secret = (string)(CONFIG['license_secret'] ?? 'psyisle-default-sign-secret-2026');
        return strtoupper(substr(hash_hmac('sha256', $body, $secret), 0, 8));
    }

    /** 激活：远程优先 → 离线应急兜底 */
    public static function activate(string $key): array
    {
        $key = strtoupper(trim(preg_replace('/[\s\-]{2,}/', '-', $key)));
        $segments = explode('-', $key);
        if (count($segments) < 3 || $segments[0] !== 'PSY') {
            return [false, '授权码格式不正确（应为 PSY-版本-XXXX-XXXX-XXXX-XXXX）'];
        }
        $edition = strtolower($segments[1]);
        if (!isset(self::EDITION_FEATURES[$edition])) {
            return [false, '未知授权版本：' . $edition];
        }
        $domain = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        $activateUrl = trim((string)setting('license.activate_url', ''));

        // 远程激活
        if ($activateUrl !== '') {
            [$ok, $msg, $token] = self::activateRemote($activateUrl, $key, $edition, $domain);
            if ($ok) {
                $effectiveEdition = self::editionFromToken($edition, $token);
                self::persistLicense($key, $effectiveEdition, $domain, $token);
                NotifyService::push('license', '远程激活成功：' . self::EDITION_NAMES[$effectiveEdition],
                    '域名：' . $domain . '，token 有效期 7 天（cron 心跳自动续期）');
                return [true, '远程激活成功：' . self::EDITION_NAMES[$effectiveEdition]];
            }
            // 远程失败 → 允许离线 HMAC 码兜底
            return [false, '远程激活失败：' . $msg . '（离线应急授权码可兜底激活）'];
        }

        // 离线 HMAC 模式（未配置远程）
        $sig = end($segments);
        $body = implode('-', array_slice($segments, 0, -1));
        if (!hash_equals(self::sign($body), strtoupper($sig))) {
            return [false, '授权码签名校验失败'];
        }
        $exists = Database::fetch('SELECT * FROM licenses WHERE license_key=?', [$key]);
        $now = date('Y-m-d H:i:s');
        $days = self::EDITION_DAYS[$edition] ?? null;
        $expires = $days === null ? null : date('Y-m-d H:i:s', strtotime("+{$days} days"));
        if ($exists) {
            if ($exists['domain'] && $exists['domain'] !== $domain) {
                return [false, '该授权码已绑定其他域名（' . $exists['domain'] . '）'];
            }
            Database::update('licenses', [
                'activated_at' => $exists['activated_at'] ?: $now, 'last_check_at' => $now,
                'status' => 'active', 'edition' => $edition, 'domain' => $domain, 'expires_at' => $expires,
                'features' => json_encode(self::EDITION_FEATURES[$edition], JSON_UNESCAPED_UNICODE),
            ], 'id=?', [$exists['id']]);
        } else {
            self::persistLicense($key, $edition, $domain, null, $expires);
        }
        NotifyService::push('license', '授权已激活（离线模式）：' . self::EDITION_NAMES[$edition], '域名：' . $domain);
        return [true, '激活成功（离线模式）：' . self::EDITION_NAMES[$edition]];
    }

    /** 远程激活：挑战 → 验签 → 缓存 token */
    public static function activateRemote(string $url, string $key, string $edition, string $domain): array
    {
        $pub = base64_decode((string)(CONFIG['issuer_public_key'] ?? ''), true);
        if ($pub === false || strlen($pub) !== 32) {
            return [false, 'config.php 缺少 issuer_public_key', null];
        }
        $nonce = bin2hex(random_bytes(8));
        [$ok, $status, $body] = Http::post(
            rtrim($url, '/') . '/activate',
            json_encode(['license' => $key, 'domain' => $domain, 'nonce' => $nonce], JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json'], 15
        );
        if (!$ok || $status >= 400) {
            return [false, "status=$status " . str_take($body, 100, ''), null];
        }
        $resp = json_decode((string)$body, true);
        if (!is_array($resp) || empty($resp['ok']) || empty($resp['payload_b64']) || empty($resp['signature_b64'])) {
            return [false, '发行方响应格式异常', null];
        }
        $payloadB64 = (string)$resp['payload_b64'];
        $sigB64 = (string)$resp['signature_b64'];
        $payloadJson = base64_decode($payloadB64, true);
        $sig = base64_decode($sigB64, true);
        if ($payloadJson === false || $sig === false) return [false, 'token 编解码失败', null];
        if (!sodium_crypto_sign_verify_detached($sig, $payloadJson, $pub)) {
            return [false, 'token 签名验证失败（疑似伪造）', null];
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) return [false, 'token 内容异常', null];
        if (($payload['license'] ?? '') !== $key || strtolower((string)($payload['domain'] ?? '')) !== $domain) {
            return [false, 'token 与本站不匹配', null];
        }
        if (($payload['nonce'] ?? '') !== $nonce) {
            return [false, 'nonce 不匹配（疑似重放）', null];
        }
        // edition/features 以发行方 payload 为准
        $edition = (string)($payload['edition'] ?? $edition);
        if (!isset(self::EDITION_FEATURES[$edition])) return [false, '授权 token 版本无效', null];
        if ($edition === 'lifetime') {
            if (!empty($payload['expires_at'])) return [false, '永久授权 token 不应包含到期时间', null];
        } else {
            $expires = (string)($payload['expires_at'] ?? '');
            if ($expires === '' || strtotime($expires) === false || strtotime($expires) <= time()) {
                return [false, '授权 token 缺少有效到期时间', null];
            }
        }
        $token = [
            'payload' => $payload,
            'token_expires_at' => (string)($payload['token_expires_at'] ?? date('c', time() + 7 * 86400)),
            'verified_at' => date('c'),
        ];
        return [true, 'token 已验证', $token];
    }

    private static function editionFromToken(string $fallback, ?array $token): string
    {
        $edition = (string)($token['payload']['edition'] ?? $fallback);
        if (!isset(self::EDITION_FEATURES[$edition])) {
            throw new \InvalidArgumentException('授权 token 版本无效');
        }
        return $edition;
    }

    private static function persistLicense(string $key, string $edition, string $domain, ?array $token, ?string $expires = null): void
    {
        $edition = self::editionFromToken($edition, $token);
        $features = self::EDITION_FEATURES[$edition] ?? [];
        if ($token && isset($token['payload']['features']) && is_array($token['payload']['features'])) {
            $features = array_merge($features, $token['payload']['features']);
        }
        if ($edition === 'lifetime') {
            $features['commercial_plugins'] = true;
        }
        if ($token) {
            $rawExpires = $token['payload']['expires_at'] ?? null;
            $expires = $rawExpires ? date('Y-m-d H:i:s', (int)strtotime((string)$rawExpires)) : null;
        }
        $now = date('Y-m-d H:i:s');
        $row = [
            'license_key' => $key, 'domain' => $domain,
            'edition' => $edition, 'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
            'last_check_at' => $now, 'status' => 'active',
        ];
        $exists = Database::fetch('SELECT id FROM licenses WHERE license_key=?', [$key]);
        if ($exists) {
            Database::run('UPDATE licenses SET last_check_at=?, status=?, edition=?, features=?, domain=?, expires_at=? WHERE id=?',
                [$now, 'active', $edition, $row['features'], $domain, $expires, $exists['id']]);
        } else {
            Database::insert('licenses', $row + [
                'issued_at' => $now, 'activated_at' => $now,
                'expires_at' => $expires, 'last_check_at' => $now, 'status' => 'active',
            ]);
        }
        if ($token) {
            self::saveToken($token);
        }
    }

    private static function saveToken(array $token): void
    {
        \app\Core\Model::saveSetting('license.remote_token', json_encode($token, JSON_UNESCAPED_UNICODE), 'system');
        \app\Core\Cache::forget('settings_all');
    }

    private static function token(): ?array
    {
        $raw = (string)setting('license.remote_token', '');
        if ($raw === '') return null;
        $t = json_decode($raw, true);
        return is_array($t) ? $t : null;
    }

    /** 心跳：token 临期自动续期（cron 调用） */
    public static function heartbeat(): array
    {
        $lic = self::current();
        $url = trim((string)setting('license.activate_url', ''));
        if (!$lic || $url === '') return ['mode' => 'local'];
        $t = self::token();
        $exp = $t ? strtotime((string)$t['token_expires_at']) : 0;
        if ($exp - time() > 3 * 86400) return ['mode' => 'remote', 'skipped' => 'token fresh'];
        [, , $token] = self::activateRemote($url, (string)$lic['license_key'], (string)$lic['edition'], (string)$lic['domain']);
        if ($token) {
            self::persistLicense((string)$lic['license_key'], (string)$token['payload']['edition'], (string)$lic['domain'], $token);
            return ['mode' => 'remote', 'renewed' => true];
        }
        Database::update('licenses', ['last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$lic['id']]);
        return ['mode' => 'remote', 'renewed' => false];
    }

    /** 功能判定（远程宽限超期 → 高级功能降级） */
    public static function feature(string $name)
    {
        $lic = self::current();
        $feats = $lic ? (json_decode((string)$lic['features'], true) ?: []) : self::EDITION_FEATURES['trial'];
        // 远程模式：宽限超期时降级 ai_chat
        $url = trim((string)setting('license.activate_url', ''));
        if ($url !== '' && ($name === 'ai_chat')) {
            $t = self::token();
            $lastVerified = $t ? strtotime((string)($t['verified_at'] ?? '')) : 0;
            if ($lastVerified && $lastVerified + self::GRACE_DAYS * 86400 < time()) {
                return false;
            }
        }
        return $feats[$name] ?? null;
    }

    /**
     * 授权用量面板（v2.2.0 授权自助）：剩余天数、咨询师用量、功能矩阵
     * @return array{days_left:?int,days_total:?int,counselors:['used','max'],multisite:bool,brand:bool,percent:int}
     */
    public static function usage(): array
    {
        $lic = self::current();
        $feats = $lic ? (json_decode((string)$lic['features'], true) ?: []) : self::EDITION_FEATURES['trial'];
        $daysLeft = null; $daysTotal = null;
        if ($lic && $lic['expires_at']) {
            $daysLeft = max(0, (int)ceil((strtotime((string)$lic['expires_at']) - time()) / 86400));
            $start = $lic['activated_at'] ? strtotime((string)$lic['activated_at']) : strtotime((string)$lic['issued_at']);
            $daysTotal = $lic['activated_at'] ? max(1, (int)ceil((strtotime((string)$lic['expires_at']) - $start) / 86400)) : null;
        }
        $maxC = (int)($feats['max_counselors'] ?? 1);
        $usedC = 0;
        try { $usedC = (int)Database::value('SELECT COUNT(*) FROM counselors WHERE status=1'); } catch (\Throwable $t) {}
        $percent = $daysTotal ? min(100, (int)round(($daysTotal - $daysLeft) / $daysTotal * 100)) : 0;
        return [
            'edition' => $lic['edition'] ?? 'trial',
            'days_left' => $daysLeft, 'days_total' => $daysTotal,
            'counselors' => ['used' => $usedC, 'max' => $maxC, 'over' => $usedC > $maxC],
            'multisite' => (bool)($feats['multisite'] ?? false),
            'remove_brand' => (bool)($feats['remove_brand'] ?? false),
            'commercial_plugins' => (bool)($feats['commercial_plugins'] ?? false),
            'ai_chat' => (bool)($feats['ai_chat'] ?? true),
            'percent' => $percent,
        ];
    }

    /** 授权状态（供后台横幅） */
    public static function status(): array
    {
        $lic = self::current();
        if (!$lic) {
            return ['state' => 'none', 'label' => '未激活', 'warn' => '尚未激活授权，功能以试用版运行'];
        }
        $now = time();
        if ($lic['expires_at'] && strtotime((string)$lic['expires_at']) < $now) {
            return ['state' => 'expired', 'label' => '已过期（' . $lic['edition'] . '）', 'warn' => '授权已过期，请续费后重新激活'];
        }
        $url = trim((string)setting('license.activate_url', ''));
        if ($url !== '') {
            $t = self::token();
            $verified = $t ? strtotime((string)($t['verified_at'] ?? '')) : 0;
            if (!$t) {
                return ['state' => 'active', 'label' => self::EDITION_NAMES[$lic['edition']], 'warn' => '远程 token 缺失，请重新激活'];
            }
            if ($verified + self::GRACE_DAYS * 86400 < $now) {
                return ['state' => 'grace_over', 'label' => self::EDITION_NAMES[$lic['edition']] . '（宽限超期）',
                    'warn' => '远程校验超期（' . self::GRACE_DAYS . ' 天宽限已过），AI 客服等高级功能已暂停。请检查外网或联系供应商'];
            }
            if ($verified + 3 * 86400 < $now) {
                return ['state' => 'active', 'label' => self::EDITION_NAMES[$lic['edition']], 'warn' => '远程 token 即将到期，等待 cron 心跳续期'];
            }
        }
        return ['state' => 'active', 'label' => self::EDITION_NAMES[$lic['edition']], 'warn' => ''];
    }

    /** ===== 完整性自校验 ===== */
    private const INTEGRITY_FILES = [
        'app/Services/LicenseService.php',
        'app/Core/Totp.php',
        'app/Core/PluginManager.php',
        'app/Controllers/Api.php',
        'config/config.php',
    ];

    public static function rebuildIntegrityBaseline(): void
    {
        if (is_link(STORAGE) || !is_dir(STORAGE)) throw new \RuntimeException('完整性基线目录不可用');
        $target = STORAGE . '/integrity.json';
        if (is_link($target)) throw new \RuntimeException('完整性基线文件不得为符号链接');
        $files = [];
        foreach (self::INTEGRITY_FILES as $rel) {
            $full = ROOT . '/' . $rel;
            if (!is_file($full)) { $files[$rel] = null; continue; }
            $hash = hash_file('sha256', $full);
            if ($hash === false) throw new \RuntimeException('无法读取核心文件：' . $rel);
            $files[$rel] = $hash;
        }
        $json = json_encode([
            'baseline_at' => date('c'), 'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('完整性基线编码失败');
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(8));
        try {
            $written = file_put_contents($tmp, $json, LOCK_EX);
            if ($written === false || $written !== strlen($json)) throw new \RuntimeException('完整性基线写入失败');
            if (!chmod($tmp, 0644)) throw new \RuntimeException('完整性基线权限设置失败');
            if (hash_file('sha256', $tmp) !== hash('sha256', $json)) throw new \RuntimeException('完整性基线临时文件回读不一致');
            if (!rename($tmp, $target)) throw new \RuntimeException('完整性基线原子替换失败');
            if (file_get_contents($target) !== $json) throw new \RuntimeException('完整性基线写入后回读不一致');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    /** 返回 [state, bad[]]：ok / missing / tampered */
    public static function integrityStatus(): array
    {
        $f = STORAGE . '/integrity.json';
        if (!is_file($f)) return ['missing', []];
        $data = json_decode((string)file_get_contents($f), true);
        if (!is_array($data) || empty($data['files'])) return ['missing', []];
        $bad = [];
        foreach ($data['files'] as $rel => $hash) {
            $full = ROOT . '/' . $rel;
            if (!is_file($full)) { $bad[] = $rel . '（文件缺失）'; continue; }
            if ($hash && !hash_equals((string)$hash, (string)hash_file('sha256', $full))) {
                $bad[] = $rel . '（内容被修改）';
            }
        }
        return [$bad ? 'tampered' : 'ok', $bad];
    }

    /** cron 每日校验 + 告警 */
    public static function integrityCronCheck(): void
    {
        [$state, $bad] = self::integrityStatus();
        if ($state !== 'ok') {
            NotifyService::push('system', '⚠ 核心文件完整性告警',
                '状态：' . $state . '；明细：' . implode('；', array_slice($bad, 0, 5)),
                [], true);
        }
    }
}
