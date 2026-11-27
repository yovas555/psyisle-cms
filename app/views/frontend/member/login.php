<?php /** 会员登录 · 微信优先 + 密码登录（v2.16.0：去掉预约号登录） */
$inWx = stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'MicroMessenger') !== false;
?>
<div class="container member-wrap">
  <div class="member-login-card">
    <h1>会员中心</h1>
    <p class="muted">登录后可管理预约、查看测评记录、导出个人数据</p>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php foreach (($flashes ?? []) as $flash): ?>
      <div class="alert alert-<?= e((string)($flash['type'] ?? 'error')) ?>"><?= e((string)($flash['msg'] ?? '')) ?></div>
    <?php endforeach; ?>

    <?php /* ===== 微信登录（主入口，PC 扫码 / 微信内一键） ===== */ ?>
    <div class="wx-login-section">
      <?php
        $wxhubMode = (string)setting('wxhub.mode', 'direct') === 'hub';
        $wxhubReady = $wxhubMode && trim((string)setting('wxhub.site_key', '')) !== '';
      ?>
      <?php if ($inWx): ?>
      <a class="btn btn-block wx-login-btn" href="<?= url($wxhubReady ? '/plugin/wxhub-client/login' : '/plugin/wechat-login/wxauth') ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px"><path d="M8.69 4C4.75 4 1.55 6.76 1.55 10.18c0 1.92 1.03 3.63 2.64 4.77l-.66 2.12 2.36-1.19c.86.26 1.79.4 2.8.4.25 0 .5-.01.74-.03-.15-.53-.23-1.08-.23-1.65 0-3.33 3.13-6.04 6.99-6.04.25 0 .49.01.73.03C15.36 5.86 12.3 4 8.69 4zm-2.53 3.29c.55 0 .99.44.99.99s-.44.99-.99.99-.99-.44-.99-.99.44-.99.99-.99zm5.06 0c.55 0 .99.44.99.99s-.44.99-.99.99-.99-.44-.99-.99.44-.99.99-.99zM16.8 12.85c-3.34 0-6.04 2.28-6.04 5.08 0 2.8 2.7 5.08 6.04 5.08.7 0 1.38-.11 2.01-.3l1.9.95-.53-1.65c1.5-1.04 2.66-2.55 2.66-4.08 0-2.8-2.7-5-6.04-5zm-2.13 2.13c.47 0 .85.38.85.85s-.38.85-.85.85-.85-.38-.85-.85.38-.85.85-.85zm4.26 0c.47 0 .85.38.85.85s-.38.85-.85.85-.85-.38-.85-.85.38-.85.85-.85z"/></svg>
        微信一键登录
      </a>
      <?php else: ?>
      <a class="btn btn-block wx-login-btn wx-qr-btn" href="<?= url($wxhubReady ? '/plugin/wxhub-client/qr' : '/plugin/wechat-login/qr') ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px"><path d="M3 3h8v8H3V3zm10 0h8v8h-8V3zM3 13h8v8H3v-8zm10 0h8v8h-8v-8z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        微信扫码登录
      </a>
      <?php endif; ?>
      <div class="wx-login-divider"><span>或使用密码登录</span></div>
    </div>

    <?php /* ===== 账号密码登录 ===== */ ?>
    <form method="post" class="member-form" id="tab-password" action="<?= url('/member/login') ?>">
      <?= csrf_field() ?>
      <label class="field"><span class="label">账号</span>
        <input type="text" name="account" required placeholder="注册手机号 / 咨询师账号名" autocomplete="username">
      </label>
      <label class="field"><span class="label">密码</span>
        <input type="password" name="password" required placeholder="登录密码" autocomplete="current-password">
      </label>
      <button class="btn btn-primary btn-block" type="submit">登录</button>
      <p class="hint"><a href="<?= url('/member/forgot-password') ?>">忘记密码？</a> · 没有账号？<a href="<?= url('/member/register') ?>">直接注册会员</a></p>
      <p class="muted" style="margin-top:6px;font-size:12px">会员与咨询师共用此入口：会员用注册手机号、咨询师用工作台账号名登录，系统自动跳转对应界面。</p>
    </form>
  </div>
</div>
<style>
.wx-login-section { margin: 18px 0 0; }
.wx-login-btn { display: flex; align-items: center; justify-content: center; width: 100%; padding: 12px; border-radius: 8px; font-size: 15px; background: #07c160; color: #fff; border: none; cursor: pointer; text-decoration: none; }
.wx-login-btn:hover { background: #06ad56; }
.wx-qr-btn { background: #24292f; }
.wx-qr-btn:hover { background: #383f45; }
.wx-login-divider { display: flex; align-items: center; margin: 16px 0; color: #94a3b8; font-size: 13px; }
.wx-login-divider::before, .wx-login-divider::after { content: ''; flex: 1; border-bottom: 1px solid #e2e8f0; }
.wx-login-divider span { padding: 0 12px; white-space: nowrap; }
</style>
