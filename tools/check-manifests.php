<?php
/**
 * 插件清单门禁：校验 plugins/{slug}/plugin.json 全部可解析、必备字段齐全、版本号规范。
 *
 * 用法（服务器或本地）：
 *   php tools/check-manifests.php
 *   php tools/check-manifests.php --verify-signatures   # 额外校验官方 Ed25519 签名
 *
 * 退出码：0=全部通过；1=存在不合格清单。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$verify = in_array('--verify-signatures', $argv ?? [], true);
$cfg = [];
if ($verify) {
    $cfgFile = $root . '/config/config.php';
    if (is_file($cfgFile)) { $cfg = require $cfgFile; }
}
$pub = $verify ? base64_decode((string)($cfg['issuer_public_key'] ?? ''), true) : false;
if ($verify && ($pub === false || strlen($pub) !== 32)) {
    fwrite(STDERR, "FAIL: config.issuer_public_key 缺失或长度不为 32 字节，无法验签\n");
    exit(1);
}

$files = glob($root . '/plugins/*/plugin.json') ?: [];
if (!$files) { fwrite(STDERR, "FAIL: 未找到任何 plugins/*/plugin.json\n"); exit(1); }

$bad = 0;
foreach ($files as $file) {
    $dirSlug = basename(dirname($file));
    $raw = (string)file_get_contents($file);
    $meta = json_decode($raw, true);
    $why = [];
    if (!is_array($meta)) {
        $why[] = 'JSON 解析失败：' . json_last_error_msg();
    } else {
        foreach (['slug', 'name', 'version', 'author'] as $k) {
            if (!isset($meta[$k]) || !is_string($meta[$k]) || trim($meta[$k]) === '') { $why[] = "缺少字段 $k"; }
        }
        if (isset($meta['slug']) && (string)$meta['slug'] !== $dirSlug) { $why[] = "slug({$meta['slug']}) 与目录名($dirSlug) 不一致"; }
        if (isset($meta['version']) && !preg_match('/^\d+\.\d+\.\d+$/', (string)$meta['version'])) {
            $why[] = 'version 不是 x.y.z 格式：' . (string)$meta['version'];
        }
        if ($verify) {
            $sig = base64_decode((string)($meta['signature'] ?? ''), true);
            if ($sig === false || strlen($sig) !== 64) {
                $why[] = 'signature 缺失或长度不为 64 字节';
            } else {
                $msg = $meta['slug'] . '|' . $meta['version'] . '|' . $meta['author'];
                if (!sodium_crypto_sign_verify_detached($sig, $msg, $pub)) { $why[] = '官方签名验证失败'; }
            }
        }
    }
    if ($why) {
        $bad++;
        fwrite(STDERR, 'BAD ' . $dirSlug . ': ' . implode('；', $why) . "\n");
    }
}

if ($bad) {
    fwrite(STDERR, "MANIFESTS_FAILED ($bad/" . count($files) . ")\n");
    exit(1);
}
echo 'MANIFESTS_OK (' . count($files) . ")\n";
