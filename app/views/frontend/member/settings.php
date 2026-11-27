<?php /** 会员设置（v2.9.0）：密码 / 邮箱 / 通知偏好 */ ?>
<div class="container member-wrap">
  <?php foreach ($flashes ?? [] as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
  <div class="member-head">
    <h1>会员设置</h1>
    <p class="muted">手机号 <?= e(\app\Services\MemberService::maskPhone($m['phone'])) ?> · <a href="<?= url('/member') ?>">返回中心</a></p>
  </div>

  <div class="member-login-card" style="margin-bottom:16px">
    <h2 style="font-size:16px;margin:0 0 10px">🔐 登录密码</h2>
    <form method="post" class="member-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="password">
      <label class="field"><span class="label">当前密码<?= empty($m['password_hash']) ? '（首次设置可留空）' : '' ?></span>
        <input type="password" name="old_password" autocomplete="current-password" <?= empty($m['password_hash']) ? '' : 'required' ?>>
      </label>
      <label class="field"><span class="label"><?= e(\app\Core\PasswordPolicy::RULE_TEXT) ?></span>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
      </label>
      <button class="btn btn-primary" type="submit"><?= empty($m['password_hash']) ? '设置密码' : '修改密码' ?></button>
      <?php if (empty($m['password_hash'])): ?><span class="hint">设置密码后可用手机号+密码快捷登录</span><?php endif; ?>
    </form>
  </div>

  <div class="member-login-card" style="margin-bottom:16px">
    <h2 style="font-size:16px;margin:0 0 10px">📧 通知邮箱</h2>
    <form method="post" class="member-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="email">
      <label class="field"><span class="label">接收邮箱（预约/订单/套餐变动通知）</span>
        <input type="email" name="email" maxlength="120" value="<?= e((string)($m['email'] ?? '')) ?>" placeholder="name@example.com">
      </label>
      <button class="btn btn-primary" type="submit">保存邮箱</button>
    </form>
  </div>

  <div class="member-login-card" style="margin-bottom:16px">
    <h2 style="font-size:16px;margin:0 0 10px">🔔 通知偏好</h2>
    <form method="post" class="member-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="prefs">
      <label class="field checkbox-line">
        <label><input type="checkbox" name="pref_mail" value="1" <?= !empty($m['notify_prefs']['mail']) ? 'checked' : '' ?>> 邮件通知（预约确认、订单、套餐变动）<?= empty($m['email']) ? ' —— ⚠️ 请先填写邮箱' : '' ?></label>
      </label>
      <?php if (!empty($wxOn)): ?>
      <label class="field checkbox-line">
        <label><input type="checkbox" name="pref_wechat" value="1" <?= !empty($m['notify_prefs']['wechat']) ? 'checked' : '' ?>> 微信通知（服务号模板消息）</label>
      </label>
      <?php else: ?>
      <label class="field checkbox-line muted">
        <label><input type="checkbox" disabled> 微信通知（站点尚未接入微信服务号）</label>
      </label>
      <?php endif; ?>
      <?php if (empty($smsOn)): ?>
      <p class="hint">ℹ️ 短信通知通道未启用：为避免失联，邮件通知不可关闭。</p>
      <?php else: ?>
      <p class="hint">ℹ️ 短信通知已启用，您可按需关闭邮件或微信通知。</p>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit">保存偏好</button>
    </form>
  </div>

  <p><a class="btn btn-ghost" href="<?= url('/member') ?>">返回会员中心</a></p>
</div>
