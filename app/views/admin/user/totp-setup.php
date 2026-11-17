<div class="panel">
  <h2>开启两步验证（第 1 步：添加到验证器）</h2>
  <p>打开手机上的验证器 App（Google Authenticator、Authy、腾讯身份验证器等），选择「手动输入密钥」，录入以下密钥：</p>
  <div class="code-box" style="text-align:center"><strong style="font-size:20px;letter-spacing:4px"><?= e(trim(chunk_split($secret, 4, ' '))) ?></strong></div>
  <p class="hint">或复制完整配置（部分 App 支持粘贴添加）：<code><?= e($uri) ?></code></p>
  <h2>第 2 步：输入当前动态码确认</h2>
  <form method="post" action="/admin/users/totp-enable" style="max-width:320px">
    <?= csrf_field() ?>
    <label class="field"><span class="label">6 位动态码</span>
      <input type="text" name="code" required pattern="\d{6}" maxlength="6" inputmode="numeric" style="letter-spacing:6px;font-size:18px;text-align:center">
    </label>
    <button class="btn btn-primary" type="submit">确认开启</button>
  </form>
  <p class="hint">⚠ 密钥只在本次显示一次，请务必先录入验证器再确认。丢失验证器将无法登录（需服务器端清空 totp_secret）。</p>
</div>
