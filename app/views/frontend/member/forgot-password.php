<?php /** 忘记密码（邮箱+短信双通道） */ ?>
<div class="container member-wrap">
  <div class="member-login-card">
    <h1>忘记密码</h1>
    <p class="muted">通过邮箱或短信验证码重置密码</p>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <div class="member-tabs">
      <button type="button" class="member-tab active" data-tab="email">邮箱重置</button>
      <button type="button" class="member-tab" data-tab="sms">短信重置</button>
    </div>
    <form method="post" class="member-form" id="tab-email" action="<?= url('/member/forgot-password') ?>">
      <?= csrf_field() ?>
      <label class="field"><span class="label">注册邮箱</span>
        <input type="email" name="email" required maxlength="120" placeholder="接收重置链接的邮箱">
      </label>
      <button class="btn btn-primary btn-block" type="submit">发送重置链接</button>
    </form>
    <form method="post" class="member-form hidden" id="tab-sms" action="<?= url('/member/forgot-password/reset-sms') ?>">
      <?= csrf_field() ?>
      <label class="field"><span class="label">手机号</span>
        <input type="tel" name="phone" id="smsPhone" required maxlength="11" placeholder="会员手机号">
      </label>
      <label class="field"><span class="label">短信验证码</span>
        <div class="code-row">
          <input type="text" name="sms_code" id="smsCode" required maxlength="6" placeholder="6 位验证码" inputmode="numeric">
          <button type="button" class="btn btn-ghost" id="sendSmsBtn">发送验证码</button>
        </div>
      </label>
      <label class="field"><span class="label">新密码</span>
        <input type="password" name="new_password" id="newPwd" required minlength="8" placeholder="请输入新密码">
        <span class="field-help"><?= e(\app\Core\PasswordPolicy::RULE_TEXT) ?></span>
      </label>
      <button class="btn btn-primary btn-block" type="submit">重置密码</button>
    </form>
    <p class="hint"><a href="<?= url('/member/login') ?>">返回登录</a></p>
  </div>
</div>
<script>
document.addEventListener('click', function (e) {
  var t = e.target.closest('.member-tab');
  if (!t) return;
  document.querySelectorAll('.member-tab').forEach(function (b) { b.classList.remove('active'); });
  t.classList.add('active');
  document.querySelectorAll('.member-form').forEach(function (f) { f.classList.add('hidden'); });
  document.getElementById('tab-' + t.dataset.tab).classList.remove('hidden');
});
document.addEventListener('click', function (e) {
  var btn = e.target.closest('#sendSmsBtn');
  if (!btn) return;
  var phone = (document.getElementById('smsPhone') || {}).value || '';
  if (!/^1[3-9]\d{9}$/.test(phone)) { alert('请先填写正确的手机号'); return; }
  btn.disabled = true; btn.textContent = '发送中…';
  fetch('<?= url('/member/login/send-code') ?>', { method: 'POST', body: 'phone=' + encodeURIComponent(phone) + '&_token=' + encodeURIComponent(document.querySelector('input[name=_token]').value), headers: {'Content-Type':'application/x-www-form-urlencoded'}, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      alert(d.ok ? (d.message || '已发送') : (d.error || '发送失败'));
      var n = 60; var timer = setInterval(function () {
        btn.textContent = '重发(' + (--n) + 's)';
        if (n <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = '发送验证码'; }
      }, 1000);
    })
    .catch(function () { btn.disabled = false; btn.textContent = '发送验证码'; });
});
</script>
