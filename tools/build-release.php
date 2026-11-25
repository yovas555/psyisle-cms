<?php
/**
 * 一二三心理咨询网站系统（PsyIsle）发行包构建（发行方专用，需 PHP CLI + zip）
 *
 * 用法：php build-release.php <version> [输出目录]
 * 产出：psyisle-<version>-release.zip
 *   1) app/ 核心文件全部经 php -w 压缩（去注释/空白，可读性降低=轻度封装）
 *   2) public/config/database/install 完整（安装向导随包）
 *   3) integrity.json 基线预生成（压缩后哈希；客户站安装即得）
 *   4) 不含：tools/docs/dist/storage/config/config.php（客户凭据安装时生成）
 *
 * 商业级加密路线（可选进阶，见 docs/OPS.md 第八节）：
 *   正式商业发行可另购 ionCube Encoder 对 app/Services/LicenseService.php、
 *   app/Core/Totp.php、app/Core/PluginManager.php 编码，服务器装 ionCube loader；
 *   当前已实现的 Ed25519 远程授权 + 完整性校验 + 官方签名构成主要保护层。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only');
$version = trim($argv[1] ?? '');
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) { fwrite(STDERR, "用法: php build-release.php <x.y.z> [outdir]\n"); exit(1); }
$outDir = $argv[2] ?? (__DIR__ . '/../dist');
$src = dirname(__DIR__);
if (!class_exists('ZipArchive')) { fwrite(STDERR, "需要 zip 扩展\n"); exit(1); }

$outFile = "$outDir/psyisle-$version-release.zip";
@mkdir($outDir, 0755, true);
@unlink($outFile);

echo "① 压缩核心文件（php -w）…\n";
$tmp = sys_get_temp_dir() . '/psyisle-rel-' . bin2hex(random_bytes(4));
@mkdir("$tmp/app", 0755, true);
$phpBin = PHP_BINARY;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$src/app", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getFilename() === '.DS_Store') continue;
    $rel = 'app/' . substr($f->getPathname(), strlen("$src/app/") );
    $rel = str_replace('\\', '/', $rel);
    @mkdir(dirname("$tmp/$rel"), 0755, true);
    // php -w 压缩（语法不变、去注释空白）
    exec($phpBin . ' -w ' . escapeshellarg($f->getPathname()) . ' > ' . escapeshellarg("$tmp/$rel") . ' 2>/dev/null', $o, $code);
    if ($code !== 0 || !filesize("$tmp/$rel")) {
        copy($f->getPathname(), "$tmp/$rel"); // 压缩失败回退原文件
    }
}
echo "② 组装包结构…\n";
$zip = new ZipArchive();
$zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
// 压缩后的 app
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$tmp/app", FilesystemIterator::SKIP_DOTS));
foreach ($it2 as $f) {
    $rel = 'app/' . substr($f->getPathname(), strlen("$tmp/app/"));
    $zip->addFile($f->getPathname(), str_replace('\\', '/', $rel));
}
// 完整目录（原样）
foreach (['public', 'database', 'install', 'plugins'] as $t) {
    $base = "$src/$t";
    if (!is_dir($base)) continue;
    $it3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it3 as $f) {
        if ($f->getFilename() === '.DS_Store') continue;
        $rel = "$t/" . substr($f->getPathname(), strlen($base) + 1);
        $rel = str_replace('\\', '/', $rel);
        if (strpos($rel, 'public/uploads/') === 0) continue;
        $zip->addFile($f->getPathname(), $rel);
    }
}
// config 模板（内置、凭据留空；真实凭据由客户站安装向导生成——绝不能打包线上 config.php）
$configTpl = "<?php\n/** 一二三心理 CMS 主配置（安装向导会改写 db/cron_secret/license_secret） */\nreturn [\n"
    . "    'timezone' => 'Asia/Shanghai',\n    'debug' => false,\n"
    . "    'db' => ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'psyisle', 'user' => '', 'pass' => ''],\n"
    . "    'cron_secret' => '',\n"
    . "    'license_secret' => '',\n"
    . "    'stats_salt' => '',\n"
    . "    // 升级分发主站专用（客户站勿填）：\n"
    . "    // 'distribute' => ['enabled' => true, 'private_key' => '发行方 Ed25519 私钥(b64)'],\n"
    . "    // 客户站远程验签公钥（随授权开通时下发）：\n"
    . "    // 'issuer_public_key' => '',\n"
    . "];\n";
$zip->addFromString('config/config.php', $configTpl);
// integrity 基线（压缩后哈希；客户安装即得）
$integrity = ['baseline_at' => date('c'), 'files' => []];
foreach (['app/Services/LicenseService.php', 'app/Core/Totp.php', 'app/Core/PluginManager.php', 'app/Controllers/Api.php'] as $rel) {
    $full = "$tmp/$rel";
    $integrity['files'][$rel] = is_file($full) ? hash_file('sha256', $full) : null;
}
$zip->addFromString('install/integrity-baseline.json', json_encode($integrity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
// 版本与说明
$zip->addFromString('version.json', json_encode([
    'name' => '一二三心理咨询网站系统', 'version' => $version, 'built_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$zip->close();
@file_put_contents("$tmp/integrity.json", json_encode($integrity, JSON_PRETTY_PRINT));

echo "发行包已生成：$outFile\n";
echo "大小: " . round(filesize($outFile) / 1024) . "KB · app 核心已压缩封装 · integrity 基线随包\n";
echo "客户安装流程：解压到站点目录 → 访问 /install/ → 安装向导写库写配置 → 自动落锁\n";
$it4 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
foreach ($it4 as $f) @unlink($f->getPathname());
foreach ([$tmp, "$tmp/app"] as $d) @rmdir($d);
