<?php
/** 一二三 PsyIsle CMS 安装向导（安装完成后自动落锁） */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$ROOT = dirname(dirname(__DIR__));
$locked = is_file($ROOT . '/storage/installed.json');

function ienv(string $k): string
{
    $v = $_POST[$k] ?? '';
    return trim((string)$v);
}

// 已安装：显示锁定页
if ($locked) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>已安装</title><body style="font-family:sans-serif;text-align:center;padding:80px">';
    echo '<h2>系统已安装</h2><p>如需重新安装，请删除 storage/installed.json（数据将清空，请先备份）。</p></body>';
    exit;
}

$msg = '';
$done = false;
$check = [];

if ($_SERVER['REQUEST_METHOD'] ?? '' === 'POST') {
    // 环境检查
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $pdoOk = extension_loaded('pdo_mysql');
    $gdOk = extension_loaded('gd');
    $mbOk = extension_loaded('mbstring');
    $zipOk = extension_loaded('zip');
    if ($phpOk && $pdoOk && $gdOk && $mbOk) {
        $db = [
            'host' => ienv('db_host') ?: '127.0.0.1',
            'port' => ienv('db_port') ?: '3306',
            'name' => ienv('db_name') ?: 'psyisle',
            'user' => ienv('db_user'),
            'pass' => ienv('db_pass'),
        ];
        $admin = [
            'username' => ienv('admin_user') ?: 'admin',
            'password' => ienv('admin_pass'),
            'email' => ienv('admin_email'),
        ];
        $siteName = ienv('site_name') ?: '心理咨询中心';
        if ($admin['password'] === '' || mb_strlen($admin['password']) < 8) {
            $msg = '管理员密码至少 8 位';
        } elseif ($db['user'] === '') {
            $msg = '请填写数据库用户名';
        } else {
            try {
                $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                // 建库
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$db['name']}`");
                // 导入 schema
                $sql = file_get_contents($ROOT . '/database/schema.sql');
                $pdo->exec($sql);
                // 初始数据
                require __DIR__ . '/seed.php';
                seed($pdo, $siteName, $admin);
                // 写 config
                $secret = bin2hex(random_bytes(16));
                $licSecret = 'psyisle-sign-' . bin2hex(random_bytes(8));
                $statsSalt = bin2hex(random_bytes(8));
                $config = "<?php\nreturn [\n"
                    . "    'timezone' => 'Asia/Shanghai',\n    'debug' => false,\n"
                    . "    'db' => [\n"
                    . "        'host' => " . var_export($db['host'], true) . ",\n"
                    . "        'port' => " . var_export($db['port'], true) . ",\n"
                    . "        'name' => " . var_export($db['name'], true) . ",\n"
                    . "        'user' => " . var_export($db['user'], true) . ",\n"
                    . "        'pass' => " . var_export($db['pass'], true) . ",\n"
                    . "    ],\n"
                    . "    'cron_secret' => " . var_export($secret, true) . ",\n"
                    . "    'license_secret' => " . var_export($licSecret, true) . ",\n"
                    . "    'stats_salt' => " . var_export($statsSalt, true) . ",\n"
                    . "];\n";
                file_put_contents($ROOT . '/config/config.php', $config);
                file_put_contents($ROOT . '/storage/installed.json', json_encode([
                    'installed_at' => date('c'),
                    'version' => '1.0.0',
                    'domain' => $_SERVER['HTTP_HOST'] ?? '',
                ], JSON_UNESCAPED_UNICODE));
                $done = true;
            } catch (Throwable $e) {
                $msg = '安装失败：' . $e->getMessage();
            }
        }
    } else {
        $msg = '环境不满足要求，请先解决检查项';
    }
}

$check = [
    'PHP ≥ 7.4（当前 ' . PHP_VERSION . '）' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL 扩展' => extension_loaded('pdo_mysql'),
    'GD 图形库（验证码）' => extension_loaded('gd'),
    'mbstring 扩展' => extension_loaded('mbstring'),
    'zip 扩展（升级功能）' => extension_loaded('zip'),
    'config/ 可写' => is_writable($ROOT . '/config'),
    'storage/ 可写' => is_writable($ROOT . '/storage'),
    'public/uploads/ 可写' => is_writable($ROOT . '/public/uploads') || @mkdir($ROOT . '/public/uploads', 0755, true),
];
$allOk = !in_array(false, $check, true);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>安装一二三 PsyIsle CMS</title>
<style>
*{box-sizing:border-box}body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f1f5f9;color:#1e293b;margin:0;padding:40px 16px}
.box{max-width:640px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(15,23,42,.1);padding:32px}
h1{font-size:20px;margin:0 0 6px}p.lead{color:#64748b;margin:0 0 22px;font-size:13.5px}
.check{list-style:none;padding:0;margin:0 0 20px;font-size:13.5px}
.check li{padding:6px 0;border-bottom:1px dashed #e2e8f0}
.ok{color:#16a34a;font-weight:700}.bad{color:#dc2626;font-weight:700}
label{display:block;font-size:12.5px;color:#64748b;margin:14px 0 5px}
input{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
button{margin-top:22px;width:100%;padding:12px;background:#2563eb;color:#fff;border:0;border-radius:9px;font-size:15px;cursor:pointer}
button:hover{background:#1d4ed8}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13.5px}
.alert-error{background:#fef2f2;color:#b91c1c}.alert-ok{background:#f0fdf4;color:#15803d}
h2{font-size:15px;margin:26px 0 4px}
.hint{font-size:12px;color:#94a3b8;margin-top:14px;text-align:center}
</style>
</head>
<body>
<div class="box">
  <h1>一二三 PsyIsle CMS · 安装向导</h1>
  <p class="lead">面向心理咨询机构的商业建站系统 v1.0.0</p>

  <?php if ($done): ?>
    <div class="alert alert-ok">安装完成！</div>
    <p>站点：<b><?= htmlspecialchars($siteName) ?></b></p>
    <p>后台地址：<a href="/admin/login">/admin/login</a>（管理员：<?= htmlspecialchars($admin['username']) ?>）</p>
    <p style="color:#dc2626"><b>安全提示：本向导已自动锁定（storage/installed.json）。请立即登录后台修改默认设置并配置 SMTP / AI。</b></p>
    <p class="hint"><a href="/admin/login">前往后台 →</a> · <a href="/">查看前台</a></p>
  <?php else: ?>

  <?php if ($msg): ?><div class="alert alert-error"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <h2>1. 环境检查</h2>
  <ul class="check">
    <?php foreach ($check as $name => $ok): ?>
    <li><span class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?></span> <?= htmlspecialchars($name) ?></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($allOk): ?>
  <h2>2. 数据库</h2>
  <form method="post">
    <div class="grid">
      <div><label>数据库地址</label><input name="db_host" value="127.0.0.1"></div>
      <div><label>端口</label><input name="db_port" value="3306"></div>
      <div><label>数据库名（不存在会自动创建）</label><input name="db_name" value="psyisle"></div>
      <div><label>数据库用户名</label><input name="db_user" value="psyisle"></div>
      <div><label>数据库密码</label><input name="db_pass" type="password"></div>
    </div>
    <h2>3. 管理员账号</h2>
    <div class="grid">
      <div><label>用户名</label><input name="admin_user" value="admin"></div>
      <div><label>邮箱</label><input name="admin_email" type="email"></div>
      <div><label>密码（≥8位）</label><input name="admin_pass" type="password"></div>
    </div>
    <h2>4. 站点名称</h2>
    <label>机构 / 站点名称</label><input name="site_name" value="" placeholder="如：XX心理咨询中心">
    <button type="submit">开始安装</button>
    <p class="hint">安装会执行 database/schema.sql 建表并写入初始数据 · 完成后自动锁定</p>
  </form>
  <?php else: ?>
  <div class="alert alert-error">存在未满足的环境项，解决后刷新本页。</div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
