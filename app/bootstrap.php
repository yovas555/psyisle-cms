<?php
/**
 * 一二三 PsyIsle CMS 引导文件
 * 自动加载 + 常量 + 安全基线
 */
declare(strict_types=1);

// PHP 7.4 polyfill（保持 PHP 8.x 前向兼容）
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; }
}
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('ROOT', dirname(__DIR__));
define('APP', ROOT . '/app');
define('PUBLIC_PATH', ROOT . '/public');
define('CONFIG_PATH', ROOT . '/config');
define('STORAGE', ROOT . '/storage');
define('UPLOAD_PATH', PUBLIC_PATH . '/uploads');
define('THEME_PATH', APP . '/views/frontend');
define('CMS_VERSION', '2.18.0');
define('CMS_NAME', '一二三心理咨询网站系统');
define('CMS_SHORT', '一二三心理');
define('CMS_VENDOR_URL', 'https://cms.123xl.cn');

// storage 目录结构
foreach (['cache', 'logs', 'backups'] as $d) {
    if (!is_dir(STORAGE . '/' . $d)) { @mkdir(STORAGE . '/' . $d, 0755, true); }
}

// 载入配置
$_configFile = CONFIG_PATH . '/config.php';
$_config = is_file($_configFile) ? require $_configFile : [];
define('CONFIG', $_config);

// 自动加载（psr-4: app\ => app/）
spl_autoload_register(function ($class) {
    $prefix = 'app\\';
    if (strpos($class, $prefix) === 0) {
        $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = APP . '/' . $rel . '.php';
        if (is_file($file)) { require $file; }
        return;
    }
    // 插件命名空间：plugins\{slugcamel}\…
    $pprefix = 'plugins\\';
    if (strpos($class, $pprefix) === 0) {
        $rel = str_replace('\\', '/', substr($class, strlen($pprefix)));
        foreach (glob(ROOT . '/plugins/*/Plugin.php') ?: [] as $pf) {
            // 已由 PluginManager require_once，避免重复；此处兜底
        }
        $direct = ROOT . '/plugins/' . strtolower(preg_replace('/([A-Z])/', '-$1', strtok($rel, '\\'))) . '/' . str_replace('\\', '/', $rel) . '.php';
        $alt = ROOT . '/plugins/booking-weekly-report/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($direct)) { require $direct; }
    }
});

// 全局助手函数
require APP . '/Core/helpers.php';

// 时区
$tz = CONFIG['timezone'] ?? 'Asia/Shanghai';
date_default_timezone_set($tz);

// 会话安全基线（GC 生命周期须 ≥ 后台空闲超时，否则 PHP 先行回收 session）
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.gc_maxlifetime', '86400');   // 24h（≥ admin_idle_timeout）
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');
    session_name('PSYISLE_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// 未捕获异常兜底（记录日志，前台显示友好错误）
set_exception_handler(function ($e) {
    app\Core\Logger::error('UNCAUGHT', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
    if (PHP_SAPI === 'cli') { echo 'ERR: ' . $e->getMessage() . PHP_EOL; exit(1); }
    if (is_file(THEME_PATH . '/error.php')) {
        include THEME_PATH . '/error.php';
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>系统错误</title><p style="text-align:center;padding:60px">系统繁忙，请稍后再试。</p>';
    }
});

set_error_handler(function ($no, $msg, $file, $line) {
    // 尊重 @ 抑制与 error_reporting 级别：@unlink 等竞态警告不应进日志
    if (!(error_reporting() & $no)) { return false; }
    if (!($no & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED))) { return false; }
    app\Core\Logger::error('PHP', $msg, ['file' => $file, 'line' => $line]);
    return true;
});
