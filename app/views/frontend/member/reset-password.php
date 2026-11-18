<?php /** 重置密码 */ ?>
<div class="container member-wrap">
  <div class="member-login-card">
    <h1>重置密码</h1>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="member-form">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e((string)$token) ?>" required>
      <label class="field"><span class="label">新密码（至少 8 位，含大写字母、小写字母和数字）</span>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
      </label>
      <button class="btn btn-primary btn-block" type="submit">重置密码</button>
      <p class="hint"><a href="<?= url('/member/forgot-password') ?>">重新获取链接</a></p>
    </form>
  </div>
</div>
