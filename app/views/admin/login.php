<?php /** 后台登录（独立布局） */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="X-Robots-Tag" content="noindex, nofollow">
<title>登录 - 一二三后台</title>
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
</head>
<body class="admin login-page">
<div class="login-box">
  <div class="login-brand"><span class="brand-mark">屿</span> <?= CMS_SHORT ?><small style="display:block;font-size:11px;color:#94a3b8;font-weight:400;letter-spacing:0">心理咨询网站系统</small></div>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <?php $demoCred = (string)setting('site.demo_credentials', ''); if ($demoCred !== ''): ?>
  <div class="alert alert-info" style="font-size:12px;padding:8px 12px;margin-bottom:10px">🎭 演示账号：<code><?= e($demoCred) ?></code>（只读模式，不可修改数据）</div>
  <?php endif; ?>
  <form method="post" action="/admin/login" autocomplete="off">
    <?= csrf_field() ?>
    <label class="field"><span class="label">用户名</span>
      <input type="text" name="username" required autofocus autocomplete="username">
    </label>
    <label class="field"><span class="label">密码</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <label class="field"><span class="label">验证码</span>
      <div class="captcha-row">
        <input type="text" name="captcha" required maxlength="4" autocomplete="off">
        <img src="/admin/captcha" alt="验证码" onclick="this.src='/admin/captcha?t='+Date.now()" title="点击刷新">
      </div>
    </label>
    <button class="btn btn-primary btn-block" type="submit">登录</button>
  </form>
  <p class="login-foot">连续失败 5 次将锁定 15 分钟 · 所有登录行为均有审计记录</p>
</div>
</body>
</html>
