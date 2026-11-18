<?php
declare(strict_types=1);
require "/www/wwwroot/demo.123xl.cn/app/bootstrap.php";
use app\Core\Database;
use app\Core\Cache;
// 1. 禁用全部插件（demo 不启用任何插件，让用户看到"从主站安装"的体验）
Database::run('INSERT INTO `settings` (`skey`,`svalue`,`sgroup`,`updated_at`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `svalue`=VALUES(`svalue`), `updated_at`=VALUES(`updated_at`)',
    ["plugins.enabled", "[]", "system", date("Y-m-d H:i:s")]);
// 2. 确保演示凭据
Database::run('INSERT INTO `settings` (`skey`,`svalue`,`sgroup`,`updated_at`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `svalue`=VALUES(`svalue`), `updated_at`=VALUES(`updated_at`)',
    ["site.demo_credentials", "demo / demo123", "general", date("Y-m-d H:i:s")]);
// 3. 确保 demo admin 角色正确
$u = Database::fetch("SELECT id, username, role_id, status FROM users WHERE username=?", ["demo"]);
if ($u && (int)$u["role_id"] !== 6) {
    Database::run("UPDATE users SET role_id=6 WHERE id=?", [(int)$u["id"]]);
    echo "ROLE_FIXED\n";
}
if (!$u) {
    Database::insert("users", [
        "username" => "demo", "password_hash" => password_hash("demo123", PASSWORD_BCRYPT),
        "display_name" => "演示管理员", "role_id" => 6, "status" => 1,
        "email" => "demo@demo.local",
    ]);
    echo "USER_CREATED\n";
}
// 4. 确保密码正确
Database::run("UPDATE users SET password_hash=?, status=1, failed_count=0, locked_until=NULL WHERE username=?",
    [password_hash("demo123", PASSWORD_BCRYPT), "demo"]);
// 5. 确保演示管理员角色 permissions=["*"]
Database::run("UPDATE roles SET permissions='[\"*\"]' WHERE id=6");
// 6. 咨询师账号
if (!Database::fetch("SELECT id FROM users WHERE username=?", ["csl_demo"])) {
    Database::insert("users", ["username" => "csl_demo", "password_hash" => password_hash("csl123", PASSWORD_BCRYPT), "display_name" => "演示咨询师", "role_id" => 5, "status" => 1]);
}
$uid = (int)Database::value("SELECT id FROM users WHERE username=?", ["csl_demo"]);
$c1 = Database::fetch("SELECT id FROM counselors ORDER BY id LIMIT 1");
if ($c1 && $uid) Database::run("UPDATE counselors SET user_id=? WHERE id=?", [$uid, (int)$c1["id"]]);
// 7. 演示会员
if (!Database::fetch("SELECT id FROM members WHERE phone=?", ["13800138000"])) {
    Database::insert("members", ["phone" => "13800138000", "name" => "演示会员", "email" => "demo@demo.local", "password_hash" => password_hash("demo123", PASSWORD_BCRYPT), "status" => "active"]);
}
// 8. 模板和套餐
if (!Database::fetch("SELECT id FROM consent_templates LIMIT 1")) {
    Database::insert("consent_templates", ["title" => "咨询知情同意书", "version" => 1, "status" => 1, "content" => "<h2>咨询知情同意书</h2><p>1. 咨询关系：遵循保密原则。</p><p>2. 保密范围：严格保密，法律要求或危机情况除外。</p><p>3. 紧急情况：请拨打 12356 或 110。</p>"]);
}
if (!Database::fetch("SELECT id FROM packages LIMIT 1")) {
    Database::insert("packages", ["name" => "首次体验咨询 1 次", "sessions" => 1, "price_cents" => 30000, "valid_days" => 30, "status" => 1]);
    Database::insert("packages", ["name" => "常规咨询套餐 10 次", "sessions" => 10, "price_cents" => 270000, "valid_days" => 365, "bonus_text" => "买 10 送 1", "status" => 1]);
}
// 9. 验证
$u2 = Database::fetch("SELECT id, username, role_id, status FROM users WHERE username=?", ["demo"]);
echo "DEMO_USER: " . json_encode($u2, JSON_UNESCAPED_UNICODE) . "\n";
echo "PLUGINS: " . setting("plugins.enabled") . "\n";
echo "CRED: " . setting("site.demo_credentials") . "\n";
$pw = Database::value("SELECT password_hash FROM users WHERE username=?", ["demo"]);
echo "PWD_CHECK: " . (password_verify("demo123", (string)$pw) ? "OK" : "FAIL") . "\n";
Cache::forget("settings_all");
echo "DONE\n";
