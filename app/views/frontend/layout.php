<?php
/** 前台布局（SEO/GEO 友好） */
$siteName = (string)setting('site.name', '一二三');
$meta = $meta ?? ['title' => $siteName, 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => []];
$crumbs = $crumbs ?? [];
$noindex = !empty($noindex);
$icp = (string)setting('site.icp', '');
$police = (string)setting('site.police_icp', '');
$phone = (string)setting('contact.phone', '');
$address = (string)setting('contact.address', '');
$hours = (string)setting('contact.hours', '');
$wechatQr = (string)setting('contact.wechat_qr', '');
$headerNavs = [];
$footerNavs = [];
try {
    $headerNavs = app\Core\Database::fetchAll("SELECT title, url FROM navigations WHERE location='header' AND status=1 ORDER BY sort LIMIT 10");
    $footerNavs = app\Core\Database::fetchAll("SELECT title, url FROM navigations WHERE location='footer' AND status=1 ORDER BY sort LIMIT 10");
} catch (\Throwable $t) {}
// 底部 CMS 标识：无「去版权」权限强制显示；专业版可在系统设置中关闭
$brandShown = \app\Services\LicenseService::feature('remove_brand')
    ? (bool)setting('site.powered_by', '1')
    : true;
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($meta['title']) ?></title>
<?php if ($noindex): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="description" content="<?= e($meta['description']) ?>">
<?php if (!empty($meta['keywords'])): ?><meta name="keywords" content="<?= e($meta['keywords']) ?>"><?php endif; ?>
<?php if (!empty($meta['canonical'])): ?><link rel="canonical" href="<?= e($meta['canonical']) ?>"><?php endif; ?>
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<meta property="og:description" content="<?= e($meta['description']) ?>">
<meta property="og:type" content="<?= e($meta['og']['type'] ?? 'website') ?>">
<meta property="og:image" content="<?= e($meta['og']['image'] ?? url('/assets/img/og-default.png')) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="alternate" type="application/rss+xml" title="<?= e($siteName) ?> RSS" href="<?= url('/rss.xml') ?>">
<link rel="icon" type="image/png" href="<?= url('/assets/img/favicon.png') ?>">
<?php $frontTheme = preg_replace('/[^a-z\-]/', '', (string)setting('site.theme', 'healing')); ?>
<link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
<?php if ($frontTheme && $frontTheme !== 'healing'): ?>
<link rel="stylesheet" href="<?= asset('css/themes/' . $frontTheme . '.css') ?>">
<?php endif; ?>
<?php foreach (($schemaList ?? []) as $schema): ?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endforeach; ?>
<?php foreach (($extraSchema ?? []) as $key => $schema): if ($schema): ?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; endforeach; ?>
</head>
<body>
<a class="skip-link" href="#content">跳到主内容</a>
<?php if ((bool)setting('site.maintenance')): ?>
<div style="background:#fef3c7;color:#92400e;text-align:center;padding:8px;font-size:13px">站点维护中，内容可能正在更新</div>
<?php endif; ?>

<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= url('/') ?>">
      <span class="brand-mark">123</span>
      <span class="brand-name"><?= e($siteName) ?></span>
      <?php if (setting('site.slogan')): ?><span class="brand-slogan"><?= e(setting('site.slogan')) ?></span><?php endif; ?>
    </a>
    <nav class="main-nav" aria-label="主导航">
      <a href="<?= url('/') ?>">首页</a>
      <a href="<?= url('/counselors') ?>">咨询师</a>
      <a href="<?= url('/services') ?>">咨询服务</a>
      <a href="<?= url('/news') ?>">心理资讯</a>
      <a href="<?= url('/faq') ?>">常见问题</a>
      <a href="<?= url('/events') ?>">活动</a>
      <a href="<?= url('/qa') ?>">问答</a>
      <a href="<?= url('/p/about') ?>">我们</a>
      <?php foreach ($headerNavs as $n): ?><a href="<?= e($n['url']) ?>"><?= e($n['title']) ?></a><?php endforeach; ?>
    </nav>
    <div class="header-cta">
      <?php $memberCur = \app\Services\MemberService::current(); ?>
      <a class="member-link" href="<?= url($memberCur ? '/member' : '/member/login') ?>" aria-label="会员"><?= $memberCur ? '👤 ' . e($memberCur['name']) : '会员' ?></a>
      <?php if ($phone): ?><a class="phone" href="tel:<?= e($phone) ?>" aria-label="联系电话"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg> <?= e($phone) ?></a><?php endif; ?>
      <a class="btn btn-primary" href="<?= url('/booking') ?>">预约</a>
    </div>
    <button class="nav-toggle" id="navToggle" aria-label="打开菜单">☰</button>
  </div>
</header>

<?php if ($crumbs): ?>
<nav class="breadcrumb container" aria-label="面包屑">
  <?php $last = array_key_last($crumbs); foreach ($crumbs as $t => $u): if ($t === null) continue; ?>
    <?php if ($t === $last || $u === ''): ?><span aria-current="page"><?= e((string)$t) ?></span>
    <?php else: ?><a href="<?= e($u) ?>"><?= e((string)$t) ?></a><span class="sep">›</span><?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<main id="content">
<?= $content ?? '' ?>
</main>

<footer class="site-footer">
  <div class="container footer-cols">
    <div class="footer-brand">
      <p class="brand-name"><?= e($siteName) ?></p>
      <p class="footer-desc"><?= e(setting('seo.description', '')) ?></p>
    </div>
    <div class="footer-links">
      <p class="footer-h">快速导航</p>
      <?php foreach ($footerNavs as $n): ?><a href="<?= e($n['url']) ?>"><?= e($n['title']) ?></a><?php endforeach; ?>
      <a href="<?= url('/p/privacy') ?>">隐私政策</a>
      <a href="<?= url('/p/consent') ?>">知情同意</a>
      <a href="<?= url('/p/crisis') ?>">危机干预资源</a>
    </div>
    <div class="footer-contact">
      <p class="footer-h">联系我们</p>
      <?php if ($phone): ?><p>电话：<?= e($phone) ?></p><?php endif; ?>
      <?php if ($address): ?><p>地址：<?= e($address) ?></p><?php endif; ?>
      <?php if ($hours): ?><p>时间：<?= e($hours) ?></p><?php endif; ?>
      <?php if ($wechatQr): ?><img src="<?= e($wechatQr) ?>" alt="<?= e($siteName) ?>微信公众号二维码" class="qr" width="90" height="90"><?php endif; ?>
    </div>
  </div>
  <?php if ((bool)setting('site.crisis_bar', '1')):
      $crisisText = trim((string)setting('site.crisis_bar_text', ''));
      if ($crisisText === '') {
          // 默认文案（系统硬编码，含热线链接）
          $crisisHtml = '如你或身边的人正处于危机中，请立即拨打 24 小时心理援助热线 <a href="tel:12356"><strong>12356</strong></a>，紧急情况拨打 <a href="tel:110"><strong>110</strong></a>。<a href="' . url('/p/crisis') . '">了解更多 →</a>';
      } else {
          // 管理员自定义：转义后输出（防存储型 XSS），支持换行
          $crisisHtml = nl2br(e($crisisText));
      }
  ?>
  <div class="footer-crisis">
    <div class="container"><?= $crisisHtml ?></div>
  </div>
  <?php endif; ?>
  <div class="footer-bottom">
    <div class="container">
      <?php if ($brandShown): ?>
      <span class="powered-by">Powered by
        <a href="<?= CMS_VENDOR_URL ?>" target="_blank" rel="noopener">123xl-cms</a>
        <span class="cms-ver">V<?= CMS_VERSION ?></span>
      </span><span class="dot">·</span>
      <?php endif; ?>
      <span class="copyright">©2025-2026 <?= e($siteName) ?></span>
      <?php if ($icp): ?><a href="https://beian.miit.gov.cn" rel="noopener nofollow" target="_blank"><?= e($icp) ?></a><?php endif; ?>
      <?php if ($police): ?><span><?= e($police) ?></span><?php endif; ?>
    </div>
  </div>
</footer>

<a class="chat-fab" id="chatFab" href="javascript:void(0)" role="button" aria-label="在线咨询">
  <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg>
  <span class="chat-fab-badge" id="chatBadge" hidden>1</span>
</a>
<div class="chat-panel" id="chatPanel" hidden>
  <div class="chat-head">
    <strong>小壹 · 智能咨询助手</strong>
    <button class="chat-close" id="chatClose" aria-label="关闭">✕</button>
  </div>
  <div class="chat-body" id="chatBody"></div>
  <div class="chat-foot">
    <div class="chat-actions">
      <button class="chat-act" id="chatEscalate">转人工</button>
      <button class="chat-act" id="chatLeave">留言</button>
    </div>
    <form id="chatForm" autocomplete="off">
      <input type="text" id="chatInput" placeholder="输入您的问题…" maxlength="500" aria-label="输入消息">
      <button type="submit" class="btn btn-primary">发送</button>
    </form>
    <p class="chat-tip">AI 生成内容仅供参考，不构成专业建议</p>
  </div>
</div>

<script>var SITE_BASE=<?= json_encode(rtrim(base_url(), '/'), JSON_HEX_TAG) ?>;var CSRF_TOKEN=<?= json_encode(app\Core\Csrf::token(), JSON_HEX_TAG) ?>;</script>
<script src="<?= asset('js/main.js') ?>" defer></script>
<script src="<?= asset('js/chat.js') ?>" defer></script>
</body>
</html>

