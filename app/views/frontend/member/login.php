<?php /** 会员中心 · 登录 */ ?>
<div class="container member-wrap">
  <div class="member-login-card">
    <h1>会员中心</h1>
    <p class="muted">登录后可管理预约、查看测评记录、导出个人数据</p>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="member-tabs" role="tablist">
      <button type="button" class="member-tab<?= ($mode ?? '') === 'password' ? '' : ' active' ?>" data-tab="code">预约号登录</button>
      <?php if (!empty($smsReady)): ?><button type="button" class="member-tab" data-tab="sms">手机验证码</button><?php endif; ?>
      <button type="button" class="member-tab<?= ($mode ?? '') === 'password' ? ' active' : '' ?>" data-tab="password">密码登录</button>
    </div>

    <form method="post" class="member-form<?= ($mode ?? '') === 'password' ? ' hidden' : '' ?>" id="tab-code">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="code">
      <label class="field"><span class="label">手机号</span>
        <input type="tel" name="phone" required placeholder="预约时填写的手机号" maxlength="11">
      </label>
      <label class="field"><span class="label">预约查询码</span>
        <input type="text" name="booking_code" required placeholder="见预约成功页 / 确认短信" maxlength="20">
      </label>
      <button class="btn btn-primary btn-block" type="submit">登录</button>
      <p class="hint">没有预约记录？<a href="<?= url('/booking') ?>">先完成一次预约</a>或<a href="<?= url('/member/register') ?>">直接注册会员</a>。</p>
    </form>

    <form method="post" class="member-form<?= ($mode ?? '') === 'password' ? '' : ' hidden' ?>" id="tab-password">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="password">
      <label class="field"><span class="label">手机号</span>
        <input type="tel" name="phone" required placeholder="注册会员的手机号" maxlength="11">
      </label>
      <label class="field"><span class="label">密码</span>
        <input type="password" name="password" required autocomplete="current-password" placeholder="注册时设置的密码">
      </label>
      <button class="btn btn-primary btn-block" type="submit">登录</button>
      <p class="hint">还没有账号？<a href="<?= url('/member/register') ?>">注册会员</a>（手机号+密码，一分钟完成）。</p>
    </form>

    <?php if (!empty($smsReady)): ?>
    <form method="post" class="member-form hidden" id="tab-sms">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="sms">
      <label class="field"><span class="label">手机号</span>
        <input type="tel" name="phone" id="smsPhone" required placeholder="会员手机号" maxlength="11">
      </label>
      <label class="field"><span class="label">短信验证码</span>
        <div class="code-row">
          <input type="text" name="code" required placeholder="6 位验证码" maxlength="6" inputmode="numeric">
          <button type="button" class="btn btn-ghost" id="sendCodeBtn">发送验证码</button>
        </div>
      </label>
      <button class="btn btn-primary btn-block" type="submit">登录</button>
    </form>
    <?php endif; ?>

    <?php /* v2.7.0：微信登录等插件登录入口（Hook 注入，格式 ['label','url','style']） */
      $loginExtras = \app\Core\Hook::fire('member.login.extras', ['buttons' => []])['buttons'] ?? [];
      foreach ($loginExtras as $lx): ?>
    <a class="btn btn-block" href="<?= url((string)$lx['url']) ?>" rel="nofollow" style="<?= e((string)($lx['style'] ?? 'background:#07c160;color:#fff')) ?>"><?= e((string)$lx['label']) ?></a>
    <?php endforeach; ?>
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
  var btn = e.target.closest('#sendCodeBtn');
  if (!btn) return;
  var phone = (document.getElementById('smsPhone') || {}).value || '';
  if (!/^1[3-9]\d{9}$/.test(phone)) { alert('请先填写正确的手机号'); return; }
  var fd = new FormData();
  fd.append('phone', phone);
  fd.append('_token', (document.querySelector('input[name=_token]') || {}).value || '');
  btn.disabled = true; btn.textContent = '发送中…';
  fetch('<?= url('/member/login/send-code') ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
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
