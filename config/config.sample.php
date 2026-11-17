<?php
/**
 * PsyIsle 主配置样例。
 * 正常安装无需手改：访问 /install/ 由向导自动生成 config/config.php（含随机密钥）。
 * 本文件仅作参考 / 手动部署模板；复制为 config.php 并填入真实值。
 * 切勿把含真实凭据的 config.php 提交到版本库。
 */
return [
    'timezone' => 'Asia/Shanghai',
    'debug' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'psyisle',
        'user' => '',
        'pass' => '',
    ],
    // 以下三项安装向导会随机生成；手动部署时请自行填入足够随机的值
    'cron_secret' => '',      // 计划任务端点鉴权（X-Cron-Secret）
    'license_secret' => '',   // 授权 / 升级包本地签名密钥
    'stats_salt' => '',       // 统计脱敏盐
];