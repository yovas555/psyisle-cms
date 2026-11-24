<?php
/** 后台布局（v2.8.3：EduSoho 式两级导航——顶部一级功能域 + 左侧二级目录） */
$u = admin_user();
$flashes = app\Core\Session::takeFlashes();
$title = $title ?? '后台';
$unread = 0;
try { $unread = (int)app\Core\Database::value('SELECT COUNT(*) FROM notifications WHERE is_read=0'); } catch (\Throwable $t) {}
$pending = ['bookings' => 0, 'messages' => 0, 'chats' => 0];
try {
    $pending['bookings'] = (int)app\Core\Database::value("SELECT COUNT(*) FROM bookings WHERE status='pending'");
    $pending['messages'] = (int)app\Core\Database::value("SELECT COUNT(*) FROM messages WHERE status='pending'");
    $pending['chats'] = (int)app\Core\Database::value("SELECT COUNT(*) FROM chat_sessions WHERE escalated=1 AND status<>'closed'");
} catch (\Throwable $t) {}
$lic = app\Services\LicenseService::status();
$seg = ($_SERVER['REQUEST_URI'] ?? '');
$active = fn($k) => strpos($seg, $k) !== false ? ' class="active"' : '';

// ===== 一级功能域与二级目录定义（v2.8.3）=====
$pluginMenus = \app\Core\PluginManager::adminMenus();
/** 插件 slug → 归属域（EduSoho 式：官方插件按职能进入对应一级域） */
$pluginDomain = [
    'consent-pro' => 'jiaowu', 'eap-pro' => 'member', 'stores-pro' => 'org',
    'ai-concierge' => 'ops', 'mail-notify' => 'ops', 'sms-notify' => 'ops',
    'booking-weekly-report' => 'ops', 'assessment-pro' => 'ops', 'schedule-pro' => 'ops', 'packages-pro' => 'ops',
    'wechat-login' => 'wechat', 'wechat-pay' => 'wechat', 'wechat-notify' => 'wechat',
];
$pluginIn = function (string $domain) use ($pluginMenus, $pluginDomain): array {
    $out = [];
    foreach ($pluginMenus as $pm) {
        if (($pluginDomain[$pm['slug']] ?? 'ops') === $domain) {
            $out[] = ['label' => ($pm['icon'] ? $pm['icon'] . ' ' : '') . $pm['title'], 'path' => $pm['path']];
        }
    }
    return $out;
};
$navDef = [
    'home' => ['label' => '首页', 'items' => [
        ['label' => '首页总览', 'path' => '/admin/area/home'],
        ['label' => '仪表盘', 'path' => '/admin'],
        ['label' => '通知中心', 'path' => '/admin/notifications', 'badge' => $unread, 'warn' => false],
        ['label' => '使用手册', 'path' => '/admin/help'],
    ]],
    'jiaowu' => ['label' => '事务', 'items' => [
        ['label' => '事务总览', 'path' => '/admin/area/jiaowu'],
        ['label' => '预约管理', 'path' => '/admin/bookings', 'badge' => $pending['bookings']],
        ['label' => '咨询留言', 'path' => '/admin/messages', 'badge' => $pending['messages']],
        ['label' => 'AI 会话', 'path' => '/admin/chat', 'badge' => $pending['chats'], 'warn' => true],
        ['label' => '心理量表', 'path' => '/admin/assessments'],
        ['label' => '活动管理', 'path' => '/admin/events'],
    ], 'plugins' => 'jiaowu'],
    'member' => ['label' => '会员', 'items' => [
        ['label' => '会员总览', 'path' => '/admin/area/member'],
        ['label' => '会员管理', 'path' => '/admin/members'],
    ], 'plugins' => 'member'],
    'org' => ['label' => '机构', 'items' => [
        ['label' => '机构总览', 'path' => '/admin/area/org'],
        ['label' => '咨询师', 'path' => '/admin/counselors'],
        ['label' => '服务项目', 'path' => '/admin/services'],
    ], 'plugins' => 'org'],
    'content' => ['label' => '内容', 'items' => [
        ['label' => '内容总览', 'path' => '/admin/area/content'],
        ['section' => '内容管理'],
        ['label' => '文章管理', 'path' => '/admin/articles'],
        ['label' => '栏目管理', 'path' => '/admin/categories'],
        ['label' => '单页管理', 'path' => '/admin/pages'],
        ['label' => '常见问题', 'path' => '/admin/faqs'],
        ['label' => '会员心声', 'path' => '/admin/testimonials'],
        ['label' => '媒体库', 'path' => '/admin/media'],
        ['section' => '站点呈现'],
        ['label' => '轮播图', 'path' => '/admin/banners'],
        ['label' => '导航菜单', 'path' => '/admin/navs'],
        ['label' => '友情链接', 'path' => '/admin/links'],
    ]],
    'ops' => ['label' => '运营', 'items' => [
        ['label' => '运营总览', 'path' => '/admin/area/ops'],
        ['label' => '经营驾驶舱', 'path' => '/admin/report'],
        ['section' => '渠道与自动化'],
        ['label' => 'AI 客服', 'path' => '/admin/plugin/ai-concierge'],
        ['label' => '邮件通知', 'path' => '/admin/plugin/mail-notify'],
        ['label' => '短信通知', 'path' => '/admin/plugin/sms-notify'],
        ['label' => '预约周报', 'path' => '/admin/plugin/booking-weekly-report'],
        ['section' => '专业能力'],
        ['label' => '量表扩展包', 'path' => '/admin/plugin/assessment-pro'],
        ['label' => '排班与考勤', 'path' => '/admin/plugin/schedule-pro'],
        ['label' => '咨询套餐', 'path' => '/admin/plugin/packages-pro'],
    ]],
    'wechat' => ['label' => '微信', 'items' => [
        ['label' => '微信总览', 'path' => '/admin/area/wechat'],
        ['label' => '服务号通知', 'path' => '/admin/plugin/wechat-notify'],
        ['label' => '微信登录', 'path' => '/admin/plugin/wechat-login'],
        ['label' => '微信支付', 'path' => '/admin/plugin/wechat-pay'],
    ]],
    'system' => ['label' => '系统', 'items' => [
        ['label' => '系统总览', 'path' => '/admin/area/system'],
        ['label' => '用户与角色', 'path' => '/admin/users'],
        ['label' => '系统设置', 'path' => '/admin/settings'],
        ['label' => '插件管理', 'path' => '/admin/plugins'],
        ['label' => '运维工具', 'path' => '/admin/tools'],
        ['label' => '操作审计', 'path' => '/admin/audits'],
        ['section' => '商业授权'],
        ['label' => '授权与升级', 'path' => '/admin/license'],
    ]],
];
/** 当前 URL → 一级域（最长前缀匹配；插件页按 slug 归域） */
$currentDomain = 'home';
if (preg_match('#^/admin/plugin/([a-z0-9\-]+)#', $seg, $m)) {
    $currentDomain = $pluginDomain[$m[1]] ?? 'system';
} else {
    $bestLen = -1;
    foreach ($navDef as $key => $d) {
        foreach ($d['items'] as $it) {
            if (!isset($it['path'])) continue;
            $p = $it['path'];
            if ($p === $seg || ($p !== '/admin' && str_starts_with($seg, $p . '/')) || ($p === '/admin' && ($seg === '/admin' || $seg === '/admin/'))) {
                if (strlen($p) > $bestLen) { $bestLen = strlen($p); $currentDomain = $key; }
            }
        }
    }
}
$cur = $navDef[$currentDomain];
/** 二级渲染：核心项 + 该域插件项（追加在组尾） */
$renderSide = function (string $domain) use ($navDef, $pluginIn, $pluginMenus, $pluginDomain, $active): void {
    $d = $navDef[$domain];
    foreach ($d['items'] as $it) {
        if (isset($it['section'])) { echo '<p class="side-group">' . e($it['section']) . '</p>'; continue; }
        $badge = '';
        if (!empty($it['badge'])) {
            $cls = !empty($it['warn']) ? 'badge warn' : 'badge';
            $badge = '<b class="' . $cls . '">' . (int)$it['badge'] . '</b>';
        }
        echo '<a href="' . e($it['path']) . '"' . $active($it['path']) . '>' . e($it['label']) . $badge . '</a>';
    }
    $plugins = isset($d['plugins']) ? $pluginIn($d['plugins']) : [];
    foreach ($plugins as $p) {
        echo '<a href="' . e($p['path']) . '"' . $active($p['path']) . '>' . e($p['label']) . '</a>';
    }
    $ungrouped = array_filter($pluginMenus, fn ($pm) => !isset($pluginDomain[$pm['slug']]));
    if ($domain === 'system' && $ungrouped) {
        echo '<p class="side-group">插件扩展</p>';
        foreach ($ungrouped as $pm) {
            echo '<a href="' . e($pm['path']) . '"' . $active('/admin/plugin/' . $pm['slug']) . '>· ' . e($pm['title']) . '</a>';
        }
    }
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> - 一二三后台</title>
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
</head>
<body class="admin">
<?php
// v2.10.0：咨询师工作台角色 → 专属精简侧栏
$isCounselorWs = false;
try {
    $roleRow = app\Core\Database::fetch('SELECT permissions FROM roles WHERE id=?', [($u['role_id'] ?? 0)]);
    $perms = $roleRow ? (json_decode((string)$roleRow['permissions'], true) ?: []) : [];
    $isCounselorWs = $perms === ['counselor_workspace'];
} catch (\Throwable $t) {}
if ($isCounselorWs):
    $cwActive = fn($k) => strpos($seg, $k) !== false ? ' class="active"' : '';
?>
<header class="mainnav">
  <a class="mainnav-brand" href="/admin/counselor-home"><span class="brand-mark">123</span><span class="txt">咨询师工作台</span></a>
  <nav class="mainnav-tabs">
    <a class="mainnav-tab<?= strpos($seg, 'counselor-home') !== false && strpos($seg, 'profile') === false && strpos($seg, 'bookings') === false && strpos($seg, 'schedule') === false && strpos($seg, 'notes') === false && strpos($seg, 'cases') === false ? ' active' : '' ?>" href="/admin/counselor-home">总览</a>
    <a class="mainnav-tab<?= strpos($seg, '/profile') !== false ? ' active' : '' ?>" href="/admin/counselor-home/profile">我的资料</a>
    <a class="mainnav-tab<?= strpos($seg, '/bookings') !== false ? ' active' : '' ?>" href="/admin/counselor-home/bookings">我的预约</a>
    <a class="mainnav-tab<?= strpos($seg, '/schedule') !== false ? ' active' : '' ?>" href="/admin/counselor-home/schedule">我的排班</a>
    <a class="mainnav-tab<?= strpos($seg, '/cases') !== false ? ' active' : '' ?>" href="/admin/counselor-home/cases">个案管理</a>
    <a class="mainnav-tab<?= strpos($seg, '/notes') !== false ? ' active' : '' ?>" href="/admin/counselor-home/notes">咨询小结与作业</a>
  </nav>
  <div class="mainnav-right">
    <?php if ($lic['state'] === 'none' || $lic['warn']): ?><a class="top-tip warn" href="/admin/license"><?= e($lic['warn']) ?></a><?php endif; ?>
    <span class="admin-user"><?= e($u['display_name'] ?? '') ?></span>
    <a href="/admin/logout" class="btn btn-sm btn-ghost">退出</a>
  </div>
</header>
<div class="admin-body">
<aside class="side">
  <div class="side-domain-label">工作台目录</div>
  <nav class="side-nav">
    <a href="/admin/counselor-home"<?= $cwActive('/admin/counselor-home') && strpos($seg, '/admin/counselor-home/') === false ? ' class="active"' : '' ?>>总览</a>
    <a href="/admin/counselor-home/profile"<?= $cwActive('/admin/counselor-home/profile') ?>>我的资料与价格</a>
    <a href="/admin/counselor-home/bookings"<?= $cwActive('/admin/counselor-home/bookings') ?>>我的预约</a>
    <a href="/admin/counselor-home/schedule"<?= $cwActive('/admin/counselor-home/schedule') ?>>我的排班</a>
    <a href="/admin/counselor-home/cases"<?= $cwActive('/admin/counselor-home/cases') ?>>个案管理</a>
    <a href="/admin/counselor-home/notes"<?= $cwActive('/admin/counselor-home/notes') ?>>咨询小结与作业</a>
    <p class="side-group">帮助</p>
    <a href="/" target="_blank">查看前台 ↗</a>
  </nav>
</aside>
<div class="main">
  <header class="topbar">
    <div class="topbar-title"><?= e($title) ?></div>
    <div class="topbar-right">
      <span class="admin-user"><?= e($u['display_name'] ?? '') ?></span>
      <a href="/admin/logout" class="btn btn-sm btn-ghost">退出</a>
    </div>
  </header>
  <div class="content">
    <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?= $content ?? '' ?>
  </div>
</div>
</div>
<script>var CSRF_TOKEN=<?= json_encode(app\Core\Csrf::token(), JSON_HEX_TAG) ?>;var SITE_BASE=<?= json_encode(rtrim(base_url(), '/'), JSON_HEX_TAG) ?>;</script>
<script src="<?= asset('js/admin.js') ?>" defer></script>
</body>
</html>
<?php return; endif; ?>
<header class="mainnav">
  <a class="mainnav-brand" href="/admin"><span class="brand-mark">123</span><span class="txt">一二三后台</span></a>
  <nav class="mainnav-tabs">
    <?php foreach ($navDef as $key => $d): ?>
    <a class="mainnav-tab<?= $currentDomain === $key ? ' active' : '' ?>" href="<?= e($d['items'][0]['path'] ?? '/admin') ?>"><?= e($d['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="mainnav-right"><a href="/" target="_blank">查看前台 ↗</a></div>
</header>
<div class="admin-body">
<aside class="side">
  <div class="side-domain-label"><?= e($cur['label']) ?></div>
  <nav class="side-nav"><?php $renderSide($currentDomain); ?></nav>
</aside>

<div class="main">
  <header class="topbar">
    <div class="topbar-title"><?= e($title) ?></div>
    <div class="topbar-right">
      <?php if ($lic['state'] === 'none'): ?><a class="top-tip warn" href="/admin/license"><?= e($lic['warn']) ?></a>
      <?php elseif ($lic['warn']): ?><a class="top-tip warn" href="/admin/license"><?= e($lic['warn']) ?></a>
      <?php else: ?><span class="top-tip"><?= e($lic['label']) ?></span><?php endif; ?>
      <span class="admin-user"><?= e($u['display_name'] ?? '') ?></span>
      <a href="/admin/logout" class="btn btn-sm btn-ghost">退出</a>
    </div>
  </header>

  <nav class="quick-nav" aria-label="快速导航">
    <form method="get" action="/admin/search-client" class="qn-search" role="search">
      <input type="search" name="q" placeholder="🔍 客户查找：手机号/查询码…" value="" aria-label="客户查找">
    </form>
    <a href="/admin"<?= $active('/admin') && strpos($seg, '/admin/') === false ? ' class="active"' : '' ?>>📊 仪表盘</a>
    <a href="/admin/bookings"<?= $active('/admin/bookings') ?>>📅 预约<?= $pending['bookings'] ? ' <i class="qn-badge">' . $pending['bookings'] . '</i>' : '' ?></a>
    <a href="/admin/messages"<?= $active('/admin/messages') ?>>💬 留言<?= $pending['messages'] ? ' <i class="qn-badge">' . $pending['messages'] . '</i>' : '' ?></a>
    <a href="/admin/chat"<?= $active('/admin/chat') ?>>🎧 AI 会话<?= $pending['chats'] ? ' <i class="qn-badge warn">' . $pending['chats'] . '</i>' : '' ?></a>
    <a href="/admin/articles">📝 文章</a>
    <a href="/admin/counselors">🧑‍⚕️ 咨询师</a>
    <a href="/admin/services">🧩 服务</a>
    <a href="/admin/events">🎪 活动</a>
    <a href="/admin/assessments">🧪 量表</a>
    <a href="/admin/media">🖼 媒体库</a>
    <a href="/admin/settings">⚙️ 设置</a>
    <a href="/admin/license">🔑 授权</a>
  </nav>

  <div class="content">
    <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?= $content ?? '' ?>
  </div>
</div>
</div>
<script>var CSRF_TOKEN=<?= json_encode(app\Core\Csrf::token(), JSON_HEX_TAG) ?>;var SITE_BASE=<?= json_encode(rtrim(base_url(), '/'), JSON_HEX_TAG) ?>;</script>
<script src="<?= asset('js/admin.js') ?>" defer></script>
</body>
</html>
