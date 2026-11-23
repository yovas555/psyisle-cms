<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Request;
use app\Services\DistributeService;

/**
 * 升级分发 API（主站 ww 专用端点，公开访问、靠授权三要素鉴权）
 *
 * POST /upgrade-api/activate  {license, domain, nonce}        → Ed25519 激活 token（兼容 LicenseService 远程激活协议）
 * POST /upgrade-api/check     {license, domain, nonce, ver}   → 最新核心/插件版本（签名应答，无新版 update=null）
 * POST /upgrade-api/token     {license, domain, nonce, kind, slug} → 一次性下载令牌
 * GET  /upgrade-api/download?t=<payload_b64>.<signature_b64>  → zip 流
 *
 * 未启用分发服务的主机（config.distribute.enabled!=true）一律 404，不暴露端点存在。
 */
class UpgradeApi
{
    public function activate(Request $req, array $p): void
    {
        if (!DistributeService::enabled()) { $this->notFound(); }
        $in = $this->input();
        $license = strtoupper(trim((string)($in['license'] ?? '')));
        $domain = strtolower(trim((string)($in['domain'] ?? '')));
        $nonce = (string)($in['nonce'] ?? '');
        [$lic, $why, $code] = DistributeService::guard($license, $domain, $nonce, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$lic) { $this->jsonFail($why, $code); }
        $payload = [
            'act' => 'activate',
            'license' => $license,
            'edition' => (string)$lic['edition'],
            'domain' => $domain,
            'features' => json_decode((string)$lic['features'], true) ?: null,
            'expires_at' => $lic['expires_at'] ?: null,
            'token_expires_at' => date('c', time() + 7 * 86400),
            'issued_at' => date('c'),
            'nonce' => $nonce,
        ];
        $signed = DistributeService::sign($payload);
        DistributeService::log('activate', $license, $domain, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'edition=' . $lic['edition']);
        json_out(['ok' => true, 'payload_b64' => $signed['payload_b64'], 'signature_b64' => $signed['signature_b64']]);
    }

    public function check(Request $req, array $p): void
    {
        if (!DistributeService::enabled()) { $this->notFound(); }
        $in = $this->input();
        $license = strtoupper(trim((string)($in['license'] ?? '')));
        $domain = strtolower(trim((string)($in['domain'] ?? '')));
        $nonce = (string)($in['nonce'] ?? '');
        $clientVer = (string)($in['ver'] ?? '');
        [$lic, $why, $code] = DistributeService::guard($license, $domain, $nonce, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$lic) { $this->jsonFail($why, $code); }

        // 核心包：仅当远端 active 版本比客户站新时给 update；按客户版本选可直达的最高阶梯包
        $core = DistributeService::latestCore($clientVer);
        $update = null;
        if ($core && preg_match('/^\d+\.\d+\.\d+$/', $clientVer) && version_compare($core['version'], $clientVer, '>')) {
            $update = [
                'kind' => 'core', 'version' => $core['version'], 'md5' => $core['md5'],
                'size' => (int)$core['size'], 'min_from' => $core['min_from'],
                'released_at' => $core['released_at'], 'changelog' => str_take((string)($core['changelog'] ?? ''), 400, ''),
            ];
        }
        // 无可直升包 = 客户端版本低于所有可分发包的 min_from，需人工介入（审计里能看到具体版本）
        $noPath = (!$core && preg_match('/^\d+\.\d+\.\d+$/', $clientVer))
            ? ('无可直升包：客户端 ' . $clientVer . ' 低于全部 active/ladder 包的 min_from') : '';
        // 插件包清单（客户站自行比对本地版本）
        $plugins = [];
        foreach (DistributeService::activePlugins() as $pkg) {
            $plugins[] = ['slug' => $pkg['slug'], 'version' => $pkg['version'], 'md5' => $pkg['md5'], 'size' => (int)$pkg['size']];
        }
        $signed = DistributeService::sign([
            'act' => 'check', 'license' => $license, 'domain' => $domain, 'nonce' => $nonce,
            'update' => $update, 'plugins' => $plugins,
            'expires_at' => time() + 60, 'issued_at' => date('c'),
        ]);
        DistributeService::log('check', $license, $domain, (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            $noPath !== '' ? $noPath : ($update ? ('有新版 ' . $update['version']) : '已是最新'));
        json_out(['ok' => true, 'payload_b64' => $signed['payload_b64'], 'signature_b64' => $signed['signature_b64']]);
    }

    public function token(Request $req, array $p): void
    {
        if (!DistributeService::enabled()) { $this->notFound(); }
        $in = $this->input();
        $license = strtoupper(trim((string)($in['license'] ?? '')));
        $domain = strtolower(trim((string)($in['domain'] ?? '')));
        $nonce = (string)($in['nonce'] ?? '');
        $kind = (string)($in['kind'] ?? 'core');
        $slug = trim((string)($in['slug'] ?? ''));
        [$lic, $why, $code] = DistributeService::guard($license, $domain, $nonce, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$lic) { $this->jsonFail($why, $code); }

        // 客户站必须先 check 再 token：token 携带客户端当前版本，主站复核升级合法性
        $clientVer = (string)($in['ver'] ?? '');
        if ($kind === 'core') {
            $pkg = DistributeService::latestCore($clientVer);
            if (!$pkg) $this->jsonFail('暂无可用核心升级包', 404);
            if (preg_match('/^\d+\.\d+\.\d+$/', $clientVer)) {
                if (version_compare($pkg['version'], $clientVer, '<=')) $this->jsonFail('已是最新版本，无需升级', 400);
                if ($pkg['min_from'] && version_compare($clientVer, (string)$pkg['min_from'], '<')) {
                    $this->jsonFail('当前版本过低（最低直升版本 ' . $pkg['min_from'] . '），请逐级升级', 400);
                }
            } else {
                $this->jsonFail('缺少客户站版本号', 400);
            }
        } elseif ($kind === 'plugin') {
            if ($slug === '' || !preg_match('/^[a-z0-9\-]{1,60}$/', $slug)) $this->jsonFail('插件 slug 非法', 400);
            $pkg = null;
            foreach (DistributeService::activePlugins() as $cand) {
                if ($cand['slug'] === $slug) { $pkg = $cand; break; }
            }
            if (!$pkg) $this->jsonFail('插件包不存在或未发布', 404);
        } else {
            $this->jsonFail('kind 非法', 400);
        }
        $t = DistributeService::issueToken($lic, $pkg, $nonce);
        json_out(['ok' => true] + $t);
    }

    public function download(Request $req, array $p): void
    {
        if (!DistributeService::enabled()) { $this->notFound(); }
        $t = (string)$req->get('t', '');
        $dot = strrpos($t, '.');
        if ($t === '' || $dot === false) { http_response_code(400); exit('bad token'); }
        [$payload, $why] = DistributeService::consumeToken(substr($t, 0, $dot), substr($t, $dot + 1));
        if (!$payload) {
            DistributeService::log('deny', '', '', (string)($_SERVER['REMOTE_ADDR'] ?? ''), $why);
            http_response_code(403);
            exit($why);
        }
        $path = DistributeService::packageFile([
            'kind' => $payload['kind'], 'file_path' => 'storage/releases/' . (string)$payload['file'], 'md5' => $payload['md5'],
        ]);
        if (!$path) {
            DistributeService::log('deny', (string)$payload['license'], (string)$payload['domain'], (string)($_SERVER['REMOTE_ADDR'] ?? ''), '包文件缺失或 md5 不符');
            http_response_code(404);
            exit('package unavailable');
        }
        DistributeService::log('download', (string)$payload['license'], (string)$payload['domain'], (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ($payload['kind'] === 'core' ? 'core' : 'plugin:' . $payload['slug']) . ' v' . $payload['version']);
        if ($payload['kind'] === 'core') {
            \app\Core\Database::run("UPDATE upgrade_packages SET downloads=downloads+1 WHERE kind='core' AND file_path=?", ['storage/releases/' . $payload['file']]);
        } else {
            \app\Core\Database::run('UPDATE upgrade_packages SET downloads=downloads+1 WHERE kind=? AND slug=?', ['plugin', (string)$payload['slug']]);
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('X-Pkg-MD5: ' . $payload['md5']);
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    private function input(): array
    {
        $raw = (string)file_get_contents('php://input');
        $in = json_decode($raw, true);
        return is_array($in) ? $in : [];
    }

    private function jsonFail(string $why, int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'not found']);
        exit;
    }
}
