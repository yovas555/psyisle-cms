<?php /** 忘记密码 */ ?>
<div class="container member-wrap">
  <div class="member-login-card">
    <h1>忘记密码</h1>
    <p class="muted">输入注册邮箱，我们将发送密码重置链接</p>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="member-form">
      <?= csrf_field() ?>
      <label class="field"><span class="label">注册邮箱</span>
        <input type="email" name="email" required maxlength="120" placeholder="接收重置链接的邮箱">
      </label>
      <button class="btn btn-primary btn-block" type="submit">发送重置链接</button>
      <p class="hint"><a href="<?= url('/member/login') ?>">返回登录</a></p>
    </form>
  </div>
</div>
