<?php /** TOTP 二段验证（独立布局） */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>两步验证 - <?= CMS_SHORT ?>后台</title>
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
</head>
<body class="admin login-page">
<div class="login-box">
  <div class="login-brand"><span class="brand-mark">屿</span> 两步验证</div>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <p class="hint" style="margin-bottom:14px">请输入验证器 App（Google Authenticator / 微信小程序「腾讯身份验证器」等）中的 6 位动态码。</p>
  <form method="post" action="/admin/totp" autocomplete="off">
    <?= csrf_field() ?>
    <label class="field"><span class="label">动态验证码</span>
      <input type="text" name="code" required pattern="\d{6}" maxlength="6" inputmode="numeric" autofocus style="letter-spacing:6px;font-size:20px;text-align:center">
    </label>
    <button class="btn btn-primary btn-block" type="submit">验证并登录</button>
  </form>
  <p class="login-foot"><a href="/admin/logout">返回重新登录</a></p>
</div>
</body>
</html>
