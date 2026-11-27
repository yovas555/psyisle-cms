<?php
/**
 * PbootCMS → PsyIsle CMS 导入器（CLI，v2.16.0 #74）
 *
 * 用法：php import-pbootcms.php <pbootcms.sqlite路径> [--dry-run] [--run]
 * 流程：直读源 SQLite → 预览统计 → 干跑（默认）→ 单事务导入 → 301 映射表导出 → 缺失图片报告
 * 不做后台入口。
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
$ROOT = dirname(__DIR__);
require $ROOT . '/app/bootstrap.php';

$src = $argv[1] ?? '';
$run = in_array('--run', $argv, true);
$dryRun = !$run;
if ($src === '' || !file_exists($src)) { fwrite(STDERR, "用法: php import-pbootcms.php <pbootcms.sqlite> [--dry-run|--run]\n"); exit(1); }
if (!file_exists(ROOT . '/config/config.php')) { fwrite(STDERR, "请在 PsyIsle CMS 站点目录下运行\n"); exit(1); }

$sdb = new PDO('sqlite:' . $src);
$sdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== PbootCMS 导入器 ===\n";
echo "模式: ", $dryRun ? "干跑（--run 才写入）" : "实际导入", "\n\n";

// 预览源数据
echo "== 源统计 ==\n";
$tables = ['ay_content', 'ay_content_sort', 'ay_type', 'ay_member', 'ay_links'];
foreach ($tables as $t) {
    try { echo "  $t: ", $sdb->query("SELECT COUNT(*) FROM $t")->fetchColumn(), " 行\n"; }
    catch (\Throwable $e) { echo "  $t: N/A\n"; }
}

// 读取文章
echo "\n== 文章映射 ==\n";
$articles = [];
try {
    $rows = $sdb->query("SELECT a.id, a.scode, a.title, a.content, a.create_time, a.status, s.name AS sort_name
        FROM ay_content a LEFT JOIN ay_content_sort s ON s.id = a.scode
        WHERE a.status = 1 ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // 尝试其他表结构
    try {
        $rows = $sdb->query("SELECT id, scode, title, content, create_time, status FROM ay_content WHERE status=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $sortMap = [];
        try {
            foreach ($sdb->query("SELECT id, name FROM ay_content_sort") as $r) $sortMap[$r['id']] = $r['name'];
        } catch (\Throwable $e2) {}
        foreach ($rows as &$r) $r['sort_name'] = $sortMap[$r['scode'] ?? ''] ?? '';
        unset($r);
    } catch (\Throwable $e3) { $rows = []; }
}
foreach ($rows as $r) {
    $slug = 'pboot-' . $r['id'];
    $articles[] = [
        'slug' => $slug,
        'title' => mb_substr((string)$r['title'], 0, 80),
        'content' => (string)($r['content'] ?? ''),
        'created_at' => date('Y-m-d H:i:s', (int)($r['create_time'] ?? time())),
        'category' => (string)($r['sort_name'] ?? 'PbootCMS 导入'),
    ];
}
echo "待导入文章: ", count($articles), " 篇\n";

// 301 映射
$redirects = [];
foreach ($articles as $a) $redirects['/pboot-' . $a['slug']] = '/news/' . $a['slug'];

// 图片检测
$imgMissing = [];
foreach ($articles as $a) {
    preg_match_all('/src="([^"]+)"/', $a['content'], $m);
    foreach ($m[1] as $src) {
        if (str_starts_with($src, '/')) {
            $fsPath = dirname($src) . '/'; // PbootCMS 图片路径
            if (!is_file(dirname($src))) $imgMissing[] = $src;
        }
    }
}
echo "缺失图片引用: ", count($imgMissing), " 处\n";
if ($imgMissing) foreach (array_slice($imgMissing, 0, 5) as $img) echo "  - $img\n";

// 导入
if ($dryRun) {
    echo "\n=== 干跑结束（加 --run 执行实际导入）===\n";
    foreach (array_slice($articles, 0, 3) as $a) {
        echo "  预览: [{$a['slug']}] {$a['title']} ({$a['created_at']})\n";
    }
    exit(0);
}

// 实际导入（单事务）
echo "\n=== 开始导入 ===\n";
$imported = 0; $skipped = 0;
$pdo = \app\Core\Database::pdo();
$pdo->beginTransaction();
try {
    // 栏目映射
    $catMap = [];
    foreach ($articles as $a) {
        $catName = $a['category'] ?: 'PbootCMS 导入';
        if (!isset($catMap[$catName])) {
            $exists = \app\Core\Database::value('SELECT id FROM categories WHERE name=?', [$catName]);
            if ($exists) { $catMap[$catName] = (int)$exists; continue; }
            $cid = \app\Core\Database::insert('categories', ['name' => $catName, 'type' => 'article', 'status' => 1]);
            $catMap[$catName] = $cid;
        }
    }
    // 文章
    foreach ($articles as $a) {
        if (\app\Core\Database::value('SELECT id FROM articles WHERE slug=?', [$a['slug']])) { $skipped++; continue; }
        $catId = $catMap[$a['category']] ?? null;
        \app\Core\Database::insert('articles', [
            'slug' => $a['slug'], 'title' => $a['title'],
            'content' => \app\Core\Purifier::clean($a['content']),
            'category_id' => $catId, 'status' => 'published',
            'source' => 'pbootcms_import',
            'created_at' => $a['created_at'],
        ]);
        $imported++;
    }
    $pdo->commit();
    echo "导入完成: 成功 $imported 篇, 跳过 $skipped 篇（已存在）\n";
    // 301 映射导出
    $mapFile = ROOT . '/storage/pbootcms-301.json';
    file_put_contents($mapFile, json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "301 映射表: $mapFile (", count($redirects), " 条)\n";
    if ($imgMissing) {
        echo "缺失图片: ", count($imgMissing), " 处（详见上方列表）——请手动从旧站拷贝图片文件\n";
    }
} catch (\Throwable $t) {
    $pdo->rollBack();
    fwrite(STDERR, "导入失败: " . $t->getMessage() . "\n");
    exit(1);
}
echo "完成\n";
