<?php
/** 一二三心理咨询网站系统 · 入口 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// 安装检查：未安装则跳转安装向导
if (!is_file(STORAGE . '/installed.json') && PHP_SAPI !== 'cli') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (strpos($path, '/install') !== 0) {
        header('Location: /install/');
        exit;
    }
}

header_remove('X-Powered-By');
\app\Core\Response::emitSecurityHeaders();
app\Core\Session::start();
if (is_file(STORAGE . '/installed.json')) {
    \app\Core\PluginManager::boot();
}
app\Core\App::getInstance()->run();
