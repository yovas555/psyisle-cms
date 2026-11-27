<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\PluginManager;
use ZipArchive;

class ReleasePackage
{
    public const CORE_TOOLS = [
        'tools/check-manifests.php',
        'tools/import-pbootcms.php',
    ];

    public static function inspect(string $zipPath): array
    {
        if (!is_file($zipPath)) return [null, '升级包不存在'];
        if (!class_exists('ZipArchive')) return [null, '服务器未安装 PHP zip 扩展'];
        if (filesize($zipPath) > 134217728) return [null, '升级包超过 128MB 上限'];

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return [null, '升级包无法解压'];
        try {
            $entries = self::entries($zip);
            $hasVersion = isset($entries['version.json']);
            $pluginManifests = [];
            foreach ($entries as $name => $entry) {
                if (preg_match('#^(?:plugin\.json|[a-z0-9\-]{1,60}/plugin\.json)$#', $name)) {
                    $pluginManifests[] = $name;
                }
            }
            if ($hasVersion && $pluginManifests) return [null, '核心包与插件清单不能同时存在'];
            if ($hasVersion) return self::inspectCore($zip, $entries, $zipPath);
            if (count($pluginManifests) !== 1) return [null, '包内必须且只能有一个插件清单'];
            return self::inspectPlugin($zip, $entries, $pluginManifests[0], $zipPath);
        } catch (\Throwable $t) {
            return [null, $t->getMessage()];
        } finally {
            $zip->close();
        }
    }

    public static function verifyPluginSignature(array $manifest): bool
    {
        $pub = base64_decode((string)(CONFIG['issuer_public_key'] ?? ''), true);
        $sig = base64_decode((string)($manifest['signature'] ?? ''), true);
        if ($pub === false || strlen($pub) !== 32 || $sig === false || strlen($sig) !== 64) return false;
        $msg = (string)($manifest['slug'] ?? '') . '|' . (string)($manifest['version'] ?? '') . '|' . (string)($manifest['author'] ?? '');
        return sodium_crypto_sign_verify_detached($sig, $msg, $pub);
    }

    public static function checkPluginVersions(array $requirements): array
    {
        foreach ($requirements as $slug => $minimum) {
            $meta = PluginManager::get((string)$slug);
            $current = (string)($meta['version'] ?? '0.0.0');
            if (version_compare($current, (string)$minimum, '<')) {
                return [false, "请先升级插件 {$slug} 至 {$minimum}（当前 {$current}），再升级核心"];
            }
        }
        return [true, ''];
    }

    private static function inspectCore(ZipArchive $zip, array $entries, string $zipPath): array
    {
        $raw = (string)$zip->getFromName('version.json');
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) return [null, 'version.json 解析失败'];
        $version = (string)($manifest['version'] ?? '');
        $type = (string)($manifest['package_type'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) return [null, '核心版本号非法'];
        if (!in_array($type, ['upgrade', 'release'], true)) return [null, 'version.json 缺少 package_type=upgrade/release'];
        $hasIssuerSignature = array_key_exists('issuer_signature', $manifest) || array_key_exists('files_sha256', $manifest)
            || array_key_exists('core_signature', $manifest);
        if ($hasIssuerSignature) {
            [$signed, $signatureError] = CorePackageSignature::verify($manifest, $entries);
            if (!$signed) return [null, $signatureError];
        } else {
            $signature = strtoupper((string)($manifest['signature'] ?? ''));
            if (!preg_match('/^[A-F0-9]{8}$/', $signature)) return [null, '核心包缺少合法 HMAC signature'];
            $secret = (string)(CONFIG['license_secret'] ?? '');
            if ($secret === '' || $secret === 'psyisle-default-sign-secret-2026') return [null, '本站未配置有效 license_secret，拒绝校验核心包'];
            $expected = strtoupper(substr(hash_hmac('sha256', 'UPGRADE-' . $version, $secret), 0, 8));
            if (!hash_equals($expected, $signature)) return [null, '核心包 HMAC 签名校验失败'];
        }

        $minFrom = (string)($manifest['min_from'] ?? '');
        if ($minFrom !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $minFrom)) return [null, 'min_from 版本号非法'];
        $tools = $manifest['delivered_tools'] ?? null;
        if (!is_array($tools)) return [null, 'version.json 缺少 delivered_tools'];
        $tools = array_map('strval', $tools);
        sort($tools);
        $requiredTools = self::CORE_TOOLS;
        sort($requiredTools);
        if ($tools !== $requiredTools) return [null, 'delivered_tools 必须恰好声明两个官方工具'];
        foreach (self::CORE_TOOLS as $tool) {
            if (!isset($entries[$tool])) return [null, '官方包缺少 ' . $tool];
            $canonical = 'app/Support/ReleaseTools/' . basename($tool);
            if (!isset($entries[$canonical])) return [null, '官方包缺少工具校验副本 ' . $canonical];
            if (!hash_equals($entries[$tool]['sha256'], $entries[$canonical]['sha256'])) {
                return [null, '工具文件与包内校验副本不一致：' . $tool];
            }
        }

        $policy = $manifest['plugin_policy'] ?? null;
        if (!is_array($policy) || ($policy['mode'] ?? '') !== 'separate-plugins' || !is_array($policy['require_plugins'] ?? null)) {
            return [null, 'version.json 缺少独立插件升级策略 plugin_policy'];
        }
        foreach ($policy['require_plugins'] as $slug => $minimum) {
            if (!is_string($slug) || !preg_match('/^[a-z0-9\-]{1,60}$/', $slug)
                || !is_string($minimum) || !preg_match('/^\d+\.\d+\.\d+$/', $minimum)) {
                return [null, 'plugin_policy.require_plugins 格式非法'];
            }
        }

        foreach ($entries as $name => $entry) {
            if ($name === 'version.json' || in_array($name, self::CORE_TOOLS, true)) continue;
            if ($type === 'upgrade') {
                if (!preg_match('#^(app|database|public)/#', $name)) return [null, '升级包含白名单外文件：' . $name];
            } else {
                if ($name === 'config/config.php' || $name === 'tools/issuer_public_key.txt' || preg_match('#^(install|plugins)/#', $name)) continue;
                if (!preg_match('#^(app|database|public)/#', $name)) return [null, '发行包含白名单外文件：' . $name];
            }
            if (strpos($name, 'public/uploads/') === 0) return [null, '官方包不得包含 public/uploads'];
            if (self::sensitiveName($name)) return [null, '官方包包含敏感路径：' . $name];
        }
        foreach (['app/bootstrap.php', 'public/index.php'] as $required) {
            if (!isset($entries[$required])) return [null, '核心包缺少 ' . $required];
        }

        if ($type === 'release') {
            foreach ($entries as $name => $entry) {
                if (!preg_match('#^plugins/([a-z0-9\-]{1,60})/plugin\.json$#', $name, $m)) continue;
                $plugin = json_decode((string)$zip->getFromName($name), true);
                if (!is_array($plugin) || ($plugin['slug'] ?? '') !== $m[1]
                    || !preg_match('/^\d+\.\d+\.\d+$/', (string)($plugin['version'] ?? ''))
                    || trim((string)($plugin['author'] ?? '')) === ''
                    || !self::verifyPluginSignature($plugin)) {
                    return [null, '发行包内插件签名或清单无效：' . $m[1]];
                }
            }
        }

        return [[
            'kind' => 'core',
            'package_type' => $type,
            'slug' => '',
            'version' => $version,
            'manifest' => $manifest,
            'min_from' => $minFrom,
            'delivered_tools' => self::CORE_TOOLS,
            'plugin_policy' => $policy,
            'entries' => $entries,
            'md5' => md5_file($zipPath),
            'sha256' => hash_file('sha256', $zipPath),
            'size' => filesize($zipPath),
        ], ''];
    }

    private static function inspectPlugin(ZipArchive $zip, array $entries, string $manifestPath, string $zipPath): array
    {
        if (!preg_match('#^([a-z0-9\-]{1,60})/plugin\.json$#', $manifestPath, $m)) {
            return [null, '插件清单必须位于 {slug}/plugin.json'];
        }
        $slug = $m[1];
        $manifest = json_decode((string)$zip->getFromName($manifestPath), true);
        if (!is_array($manifest) || ($manifest['slug'] ?? '') !== $slug
            || !preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? ''))
            || trim((string)($manifest['author'] ?? '')) === '') {
            return [null, '插件清单字段无效'];
        }
        if (!self::verifyPluginSignature($manifest)) return [null, '插件官方签名校验失败'];
        $required = $manifest['required_files'] ?? [];
        if (!is_array($required) || array_values($required) !== $required) return [null, '插件 required_files 必须是列表'];
        $seenRequired = [];
        foreach ($required as $rel) {
            if (!is_string($rel) || $rel === '' || isset($seenRequired[$rel])) return [null, '插件 required_files 包含非法或重复路径'];
            $seenRequired[$rel] = true;
            try {
                self::assertSafePath($slug . '/' . $rel);
            } catch (\Throwable $t) {
                return [null, '插件 required_files 路径非法'];
            }
            if (!isset($entries[$slug . '/' . $rel])) return [null, '插件包缺少 required_files 指定文件：' . $rel];
        }
        foreach ($entries as $name => $entry) {
            if (strpos($name, $slug . '/') !== 0) return [null, '插件包包含白名单外文件：' . $name];
            if (self::sensitiveName($name)) return [null, '插件包包含敏感路径：' . $name];
        }
        return [[
            'kind' => 'plugin',
            'package_type' => 'plugin',
            'slug' => $slug,
            'version' => (string)$manifest['version'],
            'manifest' => $manifest,
            'min_from' => (string)($manifest['min_cms_version'] ?? ''),
            'delivered_tools' => [],
            'plugin_policy' => ['mode' => 'separate-plugins', 'require_plugins' => []],
            'entries' => $entries,
            'md5' => md5_file($zipPath),
            'sha256' => hash_file('sha256', $zipPath),
            'size' => filesize($zipPath),
        ], ''];
    }

    private static function entries(ZipArchive $zip): array
    {
        $entries = [];
        $total = 0;
        if ($zip->numFiles > 20000) throw new \RuntimeException('包内文件数量超过上限');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            self::assertSafePath($name);
            if (isset($entries[$name])) throw new \RuntimeException('包内存在重复路径：' . $name);
            $stat = $zip->statIndex($i);
            $size = (int)($stat['size'] ?? 0);
            $total += $size;
            if ($size > 67108864 || $total > 134217728) throw new \RuntimeException('包内解压体积超过上限');
            $opsys = 0;
            $attributes = 0;
            if (!$zip->getExternalAttributesName($name, $opsys, $attributes)) {
                throw new \RuntimeException('无法读取包内文件属性：' . $name);
            }
            $mode = ($attributes >> 16) & 0xF000;
            if ($opsys === ZipArchive::OPSYS_UNIX && $mode === 0xA000) {
                throw new \RuntimeException('包内不得包含符号链接：' . $name);
            }
            if ($name === '' || substr($name, -1) === '/') continue;
            $stream = $zip->getStream($name);
            if ($stream === false) throw new \RuntimeException('无法读取包内文件：' . $name);
            $hash = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) throw new \RuntimeException('读取包内文件失败：' . $name);
                hash_update($hash, $chunk);
            }
            fclose($stream);
            $entries[$name] = ['sha256' => hash_final($hash), 'size' => $size];
        }
        return $entries;
    }

    private static function assertSafePath(string $name): void
    {
        if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || strpos($name, "\0") !== false
            || strpos($name, '//') !== false || preg_match('#^[A-Za-z]:#', $name)) {
            throw new \RuntimeException('包内含非法路径');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.') throw new \RuntimeException('包内含路径穿越：' . $name);
        }
    }

    private static function sensitiveName(string $name): bool
    {
        return (bool)preg_match('#(^|/)(\.git|\.env|\.user\.ini|license_secret\.txt|private_key)(/|$)#', $name);
    }
}
