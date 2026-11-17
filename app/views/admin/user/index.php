<?php /** 角色权限说明 */ ?>
<div class="panel">
  <h2>角色与权限（RBAC 已生效）</h2>
  <table class="table">
    <tr><th>角色</th><th>可访问模块</th><th>说明</th></tr>
    <?php foreach ($roleDocs as $r): ?>
    <tr>
      <td><b><?= e($r[1]) ?></b><br><code class="muted"><?= e($r[0]) ?></code></td>
      <td><code><?= e($r[2]) ?></code></td>
      <td class="muted"><?= e($r[3]) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">给账号分配角色：编辑该账号的「角色」字段保存即可。权限在每次请求时校验，越权访问返回 403。</p>
</div>

<div class="panel">
  <h2>两步验证（TOTP）</h2>
  <?php $me = admin_user();
  $u = \app\Core\Database::fetch('SELECT totp_secret FROM users WHERE id=?', [$me['id']]);
  if ($u && !empty($u['totp_secret'])): ?>
  <p>✅ 当前账号已开启两步验证。登录时需输入验证器动态码。</p>
  <form method="post" action="/admin/users/totp-disable" data-confirm="确认关闭两步验证？关闭后仅凭密码即可登录。">
    <?= csrf_field() ?>
    <button class="btn btn-danger" type="submit">关闭两步验证</button>
  </form>
  <?php else: ?>
  <p>为账号开启两步验证（TOTP）：密码 + 验证器动态码双重保护，强烈建议管理员账号开启。</p>
  <a class="btn btn-primary" href="/admin/users/totp-setup">开启两步验证</a>
  <?php endif; ?>
</div>
