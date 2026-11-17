<?php /** 注册会员（v2.9.0：手机号+密码，零外部依赖） */ ?>
<div class="container member-wrap">
  <div class="member-login-card">
    <h1>注册会员</h1>
    <p class="muted">注册后可预约咨询、做测评、购买咨询套餐；机构确认后全程邮件/微信通知</p>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="member-form" id="tab-register">
      <?= csrf_field() ?>
      <label class="field"><span class="label">手机号 <em>*</em></span>
        <input type="tel" name="phone" required placeholder="11 位手机号，作为登录账号" maxlength="11" pattern="1[3-9]\d{9}">
      </label>
      <label class="field"><span class="label">设置密码 <em>*</em></span>
        <input type="password" name="password" required minlength="8" placeholder="至少 8 位，包含字母和数字" autocomplete="new-password">
      </label>
      <label class="field"><span class="label">怎么称呼你</span>
        <input type="text" name="name" maxlength="40" placeholder="昵称或真实姓名（选填）">
      </label>
      <label class="field"><span class="label">邮箱</span>
        <input type="email" name="email" maxlength="120" placeholder="接收预约与订单通知（推荐填写）">
      </label>
      <button class="btn btn-primary btn-block" type="submit">注册</button>
      <p class="hint">注册即表示同意<a href="<?= url('/p/consent') ?>" target="_blank">《咨询知情同意书》</a>与<a href="<?= url('/p/privacy') ?>" target="_blank">《隐私政策》</a>。已有账号？<a href="<?= url('/member/login') ?>">去登录</a></p>
    </form>
  </div>
</div>
