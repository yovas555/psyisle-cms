<?php
/**
 * 一二三心理咨询网站系统（PsyIsle）发行包构建（发行方专用，需 PHP CLI + zip）
 *
 * 用法：php build-release.php <version> [输出目录]
 * 产出：psyisle-<version>-release.zip
 *   1) app/ 核心 PHP 文件经 php -w 压缩；官方工具及其校验副本保持原文件哈希
 *   2) public/database/install/plugins 完整（安装向导随包）
 *   3) integrity.json 基线预生成（压缩后哈希；客户站安装即得）
 *   4) version.json 包含包类型、HMAC、工具清单与独立插件升级策略
 *   5) 不含：真实 config、tools 密钥、docs/dist/storage/uploads
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only');

$argc = count($argv);
if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "用法: php build-release.php <x.y.z> [outdir]\n");
    exit(64);
}
$version = trim((string)$argv[1]);
$outDir = (string)($argv[2] ?? (__DIR__ . '/../dist'));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || $outDir === '' || strpos($outDir, "\0") !== false) {
    fwrite(STDERR, "用法: php build-release.php <x.y.z> [outdir]\n");
    exit(64);
}
if (!class_exists('ZipArchive')) { fwrite(STDERR, "需要 zip 扩展\n"); exit(1); }

$src = dirname(__DIR__);
foreach (['app', 'public', 'database'] as $requiredDir) {
    if (!is_dir($src . '/' . $requiredDir)) {
        fwrite(STDERR, "缺少源码目录：{$requiredDir}\n");
        exit(1);
    }
}

$configFile = $src . '/config/config.php';
$config = is_file($configFile) ? require $configFile : [];
$secretFile = __DIR__ . '/license_secret.txt';
$secret = @is_file($secretFile) ? trim((string)@file_get_contents($secretFile)) : '';
if ($secret === '') {
    $secret = is_array($config) ? trim((string)($config['license_secret'] ?? '')) : '';
}
$signingKey = is_array($config) ? trim((string)($config['distribute']['private_key'] ?? '')) : '';
require __DIR__ . '/../app/Services/CorePackageSignature.php';
try {
    $issuerPublicKey = \app\Services\CorePackageSignature::publicKey($signingKey);
} catch (\Throwable $e) {
    fwrite(STDERR, "未配置有效发行方 Ed25519 私钥，拒绝构建\n");
    exit(1);
}
if ($secret === '' || $secret === 'psyisle-default-sign-secret-2026') {
    fwrite(STDERR, "未配置有效主密钥，拒绝构建\n");
    exit(1);
}

$coreTools = ['tools/check-manifests.php', 'tools/import-pbootcms.php'];
$toolCopies = [];
$rawAppTools = [];
foreach ($coreTools as $tool) {
    $source = $src . '/' . $tool;
    $canonical = $src . '/app/Support/ReleaseTools/' . basename($tool);
    if (!is_file($source) || is_link($source) || !is_file($canonical) || is_link($canonical)) {
        fwrite(STDERR, "缺少官方工具或校验副本：{$tool}\n");
        exit(1);
    }
    $sourceHash = hash_file('sha256', $source);
    $canonicalHash = hash_file('sha256', $canonical);
    if (!is_string($sourceHash) || !is_string($canonicalHash) || !hash_equals($canonicalHash, $sourceHash)) {
        fwrite(STDERR, "官方工具与校验副本哈希不一致：{$tool}\n");
        exit(1);
    }
    $toolCopies[$tool] = $canonical;
    $rawAppTools['app/Support/ReleaseTools/' . basename($tool)] = true;
}

$outFile = rtrim($outDir, '/') . '/psyisle-' . $version . '-release.zip';
if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "无法创建输出目录\n");
    exit(1);
}
if ((file_exists($outFile) || is_link($outFile)) && !@unlink($outFile)) {
    fwrite(STDERR, "无法替换旧发行包\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/psyisle-rel-' . bin2hex(random_bytes(8));
$removeTree = static function (string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) { @rmdir($item->getPathname()); }
        else { @unlink($item->getPathname()); }
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($tmp, $removeTree): void { $removeTree($tmp); });
if (!@mkdir($tmp . '/app', 0755, true)) {
    fwrite(STDERR, "无法创建临时构建目录\n");
    exit(1);
}

$forbiddenSegments = ['dist', 'storage', 'uploads', '.git', '.env', '.user.ini', 'license_secret.txt', 'private_key', '.DS_Store'];
$skipPackagePath = static function (string $rel) use ($forbiddenSegments): bool {
    $rel = str_replace('\\', '/', $rel);
    foreach (explode('/', $rel) as $segment) {
        if (in_array($segment, $forbiddenSegments, true)) return true;
    }
    return false;
};

$zip = null;
try {
    echo "① 压缩核心文件（php -w；官方工具保持原哈希）…\n";
    $phpBin = PHP_BINARY;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) continue;
        $rel = 'app/' . substr($f->getPathname(), strlen($src . '/app/'));
        $rel = str_replace('\\', '/', $rel);
        if ($skipPackagePath($rel)) continue;
        $target = $tmp . '/' . $rel;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            throw new RuntimeException('无法创建临时源码目录');
        }
        if (isset($rawAppTools[$rel]) || strtolower($f->getExtension()) !== 'php') {
            if (!@copy($f->getPathname(), $target)) throw new RuntimeException('无法复制核心文件');
            continue;
        }
        $output = [];
        exec($phpBin . ' -w ' . escapeshellarg($f->getPathname()) . ' > ' . escapeshellarg($target) . ' 2>/dev/null', $output, $code);
        if ($code !== 0 || !is_file($target) || filesize($target) === false) {
            if (!@copy($f->getPathname(), $target)) throw new RuntimeException('核心文件压缩失败');
        }
    }

    echo "② 组装发行包结构…\n";
    $zip = new ZipArchive();
    $entries = [];
    if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('无法创建 zip');
    }
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it2 as $f) {
        if (!$f->isFile() || $f->isLink()) continue;
        $rel = 'app/' . substr($f->getPathname(), strlen($tmp . '/app/'));
        $rel = str_replace('\\', '/', $rel);
        if (!$zip->addFile($f->getPathname(), $rel)) throw new RuntimeException('无法写入 app 文件');
        $entries[$rel] = ['sha256' => hash_file('sha256', $f->getPathname())];
    }

    foreach (['public', 'database', 'install', 'plugins'] as $top) {
        $base = $src . '/' . $top;
        if (!is_dir($base)) continue;
        $it3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it3 as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $rel = $top . '/' . substr($f->getPathname(), strlen($base) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($skipPackagePath($rel) || $rel === 'install/integrity-baseline.json') continue;
            if (!$zip->addFile($f->getPathname(), $rel)) throw new RuntimeException('无法写入发行包文件');
            $entries[$rel] = ['sha256' => hash_file('sha256', $f->getPathname())];
        }
    }

    // 仅内置空凭据模板；绝不读取或打包真实 config/config.php。
    $configTpl = "<?php\n/** 一二三心理 CMS 主配置（安装向导会改写 db/cron_secret/license_secret） */\nreturn [\n"
        . "    'timezone' => 'Asia/Shanghai',\n    'debug' => false,\n"
        . "    'db' => ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'psyisle', 'user' => '', 'pass' => ''],\n"
        . "    'cron_secret' => '',\n"
        . "    'license_secret' => '',\n"
        . "    'stats_salt' => '',\n"
        . "    // 升级分发主站专用（客户站勿填）：\n"
        . "    // 'distribute' => ['enabled' => true, 'private_key' => '发行方 Ed25519 私钥(b64)'],\n"
        . "    // 客户站远程验签公钥（安装向导从 tools/issuer_public_key.txt 写入）：\n"
        . "    // 'issuer_public_key' => '',\n"
        . "];\n";
    if (!$zip->addFromString('config/config.php', $configTpl)) throw new RuntimeException('无法写入配置模板');
    $entries['config/config.php'] = ['sha256' => hash('sha256', $configTpl)];
    if (!$zip->addFromString('tools/issuer_public_key.txt', $issuerPublicKey . "\n")) {
        throw new RuntimeException('无法写入发行方验签公钥');
    }
    $entries['tools/issuer_public_key.txt'] = ['sha256' => hash('sha256', $issuerPublicKey . "\n")];

    $integrity = ['baseline_at' => date('c'), 'files' => []];
    foreach (['app/Services/LicenseService.php', 'app/Core/Totp.php', 'app/Core/PluginManager.php', 'app/Controllers/Api.php'] as $rel) {
        $full = $tmp . '/' . $rel;
        $integrity['files'][$rel] = is_file($full) ? hash_file('sha256', $full) : null;
    }
    $integrityJson = json_encode($integrity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($integrityJson) || !$zip->addFromString('install/integrity-baseline.json', $integrityJson)) {
        throw new RuntimeException('无法写入 integrity 基线');
    }
    $entries['install/integrity-baseline.json'] = ['sha256' => hash('sha256', $integrityJson)];

    foreach ($coreTools as $tool) {
        if (!$zip->addFile($toolCopies[$tool], $tool)) throw new RuntimeException('无法写入官方工具');
        $entries[$tool] = ['sha256' => hash_file('sha256', $toolCopies[$tool])];
    }
    $manifest = [
        'name' => '一二三心理咨询网站系统',
        'package_type' => 'release',
        'version' => $version,
        'min_from' => '1.0.0',
        'signature' => strtoupper(substr(hash_hmac('sha256', 'UPGRADE-' . $version, $secret), 0, 8)),
        'delivered_tools' => $coreTools,
        'plugin_policy' => ['mode' => 'separate-plugins', 'require_plugins' => (object)[]],
        'built_at' => date('c'),
    ];
    $manifest['core_signature'] = \app\Services\CorePackageSignature::ALGORITHM;
    $manifest = \app\Services\CorePackageSignature::sign($signingKey, $manifest, $entries);
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($manifestJson) || !$zip->addFromString('version.json', $manifestJson)) {
        throw new RuntimeException('无法写入 version.json');
    }
    if (!$zip->close()) throw new RuntimeException('发行包写入失败');
    $zip = null;

    $checker = $src . '/tools/check-release-package.php';
    $realOutFile = realpath($outFile);
    if (!is_file($checker) || $realOutFile === false) throw new RuntimeException('包门禁检查器不可用');
    $gateOutput = [];
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($checker) . ' ' . escapeshellarg($realOutFile) . ' 2>&1', $gateOutput, $gateCode);
    if ($gateCode !== 0) {
        @unlink($outFile);
        fwrite(STDERR, "RELEASE_PACKAGE_GATE_FAILED：" . implode("\n", $gateOutput) . "\n");
        exit(1);
    }
    if ($gateOutput) echo implode("\n", $gateOutput) . "\n";

    echo "发行包已生成：{$outFile}\n";
    echo "大小: " . round(filesize($outFile) / 1024) . "KB · 官方工具已入包 · 包门禁通过\n";
    echo "客户安装流程：解压到站点目录 → 访问 /install/ → 安装向导写库写配置 → 自动落锁\n";
} catch (Throwable $e) {
    if ($zip instanceof ZipArchive) { @$zip->close(); }
    @unlink($outFile);
    fwrite(STDERR, '构建失败：' . $e->getMessage() . "\n");
    exit(1);
}
