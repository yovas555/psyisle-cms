<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Cache;
use app\Core\Crypto;
use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;

/**
 * 内置反馈客户端（v2.16.0 #67）：站点→CMS 管理台 反馈通道
 * 契约：cms-site/api/API-DOCS.txt v1.2.0
 * 凭据：feedback.site_key / feedback.site_secret（secret 加密存储）
 */
class FeedbackClient
{
    private const API_BASE = 'https://cms.123xl.cn/api';

    public static function configured(): bool
    {
        return trim((string)setting('feedback.site_key', '')) !== ''
            && trim(Crypto::decrypt((string)setting('feedback.site_secret', ''))) !== ''
            && trim((string)setting('feedback.api_url', self::API_BASE)) !== '';
    }

    /**
     * 轮换 CMS 反馈密钥。
     * 新密钥仅通过可选引用参数交给本地保存流程；返回消息绝不携带密钥。
     *
     * @return array{0:bool,1:string}
     */
    public static function rotateSecret(?string &$secret = null): array
    {
        $secret = '';
        if (!self::configured()) return [false, '反馈通道未配置（需 site_key/site_secret）'];
        $oldSecret = (string)Crypto::decrypt((string)setting('feedback.site_secret', ''));
        $payload = '{}';
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(12));
        $sig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $payload, $oldSecret);
        $apiUrl = rtrim((string)setting('feedback.api_url', self::API_BASE), '/');
        try {
            [$sent, $status, $resp] = Http::post($apiUrl . '/v1/feedback/rotate-secret', $payload, [
                'Content-Type: application/json',
                'X-Site-Key: ' . (string)setting('feedback.site_key', ''),
                'X-Timestamp: ' . $ts,
                'X-Nonce: ' . $nonce,
                'X-Signature: ' . $sig,
            ], 15);
        } catch (\Throwable $t) {
            return [false, '轮换失败：无法连接 CMS，未保存本地配置'];
        }
        $r = json_decode((string)$resp, true);
        $newSecret = is_array($r) && isset($r['secret']) && is_string($r['secret']) ? $r['secret'] : '';
        if (!$sent || (int)$status !== 200 || !is_array($r) || ($r['ok'] ?? false) !== true
            || preg_match('/^[a-f0-9]{48}$/D', $newSecret) !== 1
            || hash_equals($oldSecret, $newSecret)) {
            if (is_array($r) && (string)($r['error'] ?? '') === 'secret_changed') {
                return [false, '轮换失败：反馈密钥已被其他进程轮换，请重新配置后重试'];
            }
            return [false, '轮换失败：CMS 未确认轮换，未保存本地配置'];
        }
        $secret = $newSecret;
        return [true, 'CMS 已轮换反馈密钥'];
    }

    /** 提交反馈 */
    public static function submit(string $type, string $title, string $body, string $severity = 'P3', string $area = 'general'): array
    {
        if (!self::configured()) return [false, '反馈通道未配置（需 site_key/site_secret）'];
        $site = (string)setting('site.name', '');
        $ver = CMS_VERSION;
        $php = PHP_VERSION;
        $plugins = [];
        foreach (\app\Core\PluginManager::all() as $slug => $meta) {
            $plugins[] = ['slug' => $slug, 'version' => $meta['version'] ?? '', 'enabled' => \app\Core\PluginManager::isEnabled($slug)];
        }
        // 最近 10 条错误日志摘要（脱敏）——契约无 meta 字段，附在 body 尾部（body 上限 20000 字）
        $errLog = self::recentErrors();
        $ctx = "【环境摘要】站点：{$site} · PHP {$php}"
            . "\n插件：" . implode('，', array_map(
                fn ($p) => $p['slug'] . ($p['enabled'] ? '' : '（停用）'),
                $plugins
            ));
        if ($errLog) {
            $ctx .= "\n近期错误：\n- " . implode("\n- ", $errLog);
        }
        $fullBody = str_take($body . "\n\n" . $ctx, 20000, '…');
        $payload = json_encode([
            'type' => $type,
            'title' => $title,
            'body' => $fullBody,
            'cms_version' => $ver,
            'severity' => $severity,
            'area' => $area,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $apiUrl = rtrim((string)setting('feedback.api_url', self::API_BASE), '/');
        // 契约 v1.1.0 认证：X-Site-Key + X-Timestamp + X-Nonce + X-Signature
        // signature = hex(hmac_sha256(timestamp + "\n" + nonce + "\n" + raw_body, site_secret))
        $secret = (string)Crypto::decrypt((string)setting('feedback.site_secret', ''));
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(12));
        $sig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $payload, $secret);
        [$ok, $st, $resp] = Http::post($apiUrl . '/v1/feedback/submit', $payload, [
            'Content-Type: application/json',
            'X-Site-Key: ' . setting('feedback.site_key', ''),
            'X-Timestamp: ' . $ts,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $sig,
        ], 15);
        $r = json_decode((string)$resp, true);
        if ($ok && ($r['ok'] ?? false)) {
            Cache::set('feedback_last_result', ['ok' => true, 'msg' => '已提交', 'at' => date('c')], 86400);
            return [true, '反馈已提交到 CMS 管理台'];
        }
        $err = str_take((string)($r['message'] ?? ('HTTP ' . $st)), 140, '');
        Cache::set('feedback_last_result', ['ok' => false, 'msg' => $err, 'at' => date('c')], 86400);
        return [false, '提交失败：' . $err . '（已本地留痕，可重试）'];
    }

    /** 最近错误日志摘要（脱敏：路径→站点名/手机号→掩码） */
    private static function recentErrors(): array
    {
        $out = [];
        $logDir = ROOT . '/storage/logs';
        if (!is_dir($logDir)) return $out;
        $files = glob($logDir . '/*error.log') ?: [];
        $latest = end($files) ?: '';
        if (!is_file($latest)) return $out;
        $site = (string)setting('site.name', '');
        $lines = file($latest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $errors = array_filter($lines, fn ($l) => stripos($l, '.error:') !== false);
        foreach (array_slice($errors, -10) as $line) {
            // 脱敏
            $line = preg_replace('/\/www\/wwwroot\/[\w\.]+/', '[SITE]', $line);
            $line = preg_replace('/1[3-9]\d{9}/', '1**********', $line);
            $out[] = str_take($line, 200, '…');
        }
        return $out;
    }
}
