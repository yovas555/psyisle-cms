<?php /** 升级分发主站 */ ?>
<div class="page-guide" data-guide-key="release">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>升级分发主站说明</b>
  <ol>
    <li>本页仅当 config.distribute.enabled=true 时对外提供分发服务，其他主机端点自动 404</li>
    <li>客户站升级需同时满足：<b>授权码有效 + 域名与授权绑定一致 + IP 与绑定一致</b>（首次请求自动锁定 IP）</li>
    <li>只下发代码升级包，<b>永不下发/覆盖客户站业务数据</b>（站点名、文章、设置、上传文件均不受影响）</li>
    <li>发布新版本：上传构建好的 .zip（含 version.json / plugin.json）→ 自动登记、旧核心包自动退役</li>
  </ol>
</div>

<?php if (!$enabled): ?>
<div class="alert alert-warning">本站未启用分发服务：需在 config/config.php 设置 <code>'distribute' => ['enabled' => true, 'private_key' => '发行方 Ed25519 私钥(b64)']</code>。本页仍可登记发行包，但客户站无法拉取。</div>
<?php endif; ?>

<div class="dash-grid">
  <div class="panel">
    <h2>发行包</h2>
    <?php $core = null; foreach ($pkgs as $q): if ($q['kind'] === 'core' && !$core) $core = $q; endforeach; ?>
    <?php if ($core): ?>
    <p class="ok-text">当前分发的最新核心版本：<b><?= e($core['version']) ?></b>（md5 <?= e(substr((string)$core['md5'], 0, 8)) ?>…，下载 <?= (int)$core['downloads'] ?> 次）</p>
    <?php else: ?><p class="muted">尚无登记的核心发行包 —— 上传 build-release.php 产出的 .zip 后客户站即可检查更新。</p><?php endif; ?>

    <form method="post" action="/admin/release/upload" enctype="multipart/form-data" style="margin:10px 0">
      <?= csrf_field() ?>
      <label class="field"><span class="label">上传发行包（核心整站包含 version.json；插件包含 plugin.json）</span>
        <input type="file" name="package" accept=".zip" required>
      </label>
      <div class="field-row">
        <label class="field"><span class="label">最低直升版本（可选，如 2.0.0，低于它需逐级升级）</span>
          <input type="text" name="min_from" placeholder="2.0.0">
        </label>
        <label class="field"><span class="label">更新说明（changelog）</span>
          <input type="text" name="changelog" placeholder="修复 xx，新增 yy">
        </label>
      </div>
      <button class="btn btn-primary" type="submit">上传并登记</button>
    </form>

    <table class="table">
      <tr><th>类型</th><th>版本</th><th>状态</th><th>大小</th><th>下载</th><th>发布时间</th><th>操作</th></tr>
      <?php if (!$pkgs): ?><tr><td colspan="7" class="empty">暂无发行包</td></tr><?php endif; ?>
      <?php foreach ($pkgs as $k): ?>
      <tr>
        <td><?= $k['kind'] === 'core' ? '核心' : '插件 ' . e((string)$k['slug']) ?></td>
        <td><b>v<?= e($k['version']) ?></b><?php if ($k['min_from']): ?><span class="chip">≥<?= e((string)$k['min_from']) ?></span><?php endif; ?></td>
        <td><?php
          if ($k['status'] === 'active') echo '<span class="ok-text">分发中</span>';
          elseif ($k['status'] === 'ladder') echo '<span class="chip" title="为低版本客户保留的阶梯中间包，登记新版时不会被自动退役">阶梯</span>';
          else echo '<span class="draft">已退役</span>';
        ?></td>
        <td class="muted"><?= round(((int)$k['size']) / 1024) ?>KB</td>
        <td><?= (int)$k['downloads'] ?></td>
        <td class="muted"><?= e((string)$k['released_at']) ?></td>
        <td>
          <form method="post" action="/admin/release/status/<?= (int)$k['id'] ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="status" value="<?= $k['status'] === 'active' ? 'retired' : 'active' ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= $k['status'] === 'active' ? '退役' : ($k['status'] === 'ladder' ? '改为分发中' : '恢复分发') ?></button>
          </form>
          <form method="post" action="/admin/release/delete/<?= (int)$k['id'] ?>" style="display:inline" data-confirm="删除后客户站将无法再获取该包，确认？">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="hint">核心包同一时刻只有一个「分发中」版本；登记新核心包后旧包自动退役。退役仅停止下发，不删文件。</p>
  </div>

  <div class="panel">
    <h2>授权客户（升级白名单）</h2>
    <p class="hint">客户站升级 = 授权码 ✓ + 域名绑定 ✓ + IP 绑定 ✓。IP 首次请求自动锁定；也可在此手工指定（多 IP 逗号分隔，留空则下次自动绑定）。关闭「允许远程升级」后该客户只能手动上传包升级。</p>
    <table class="table">
      <tr><th>授权码</th><th>版本</th><th>绑定域名</th><th>绑定 IP / 最近 IP</th><th>远程升级</th><th>最近校验</th></tr>
      <?php if (!$clients): ?><tr><td colspan="6" class="empty">暂无授权客户</td></tr><?php endif; ?>
      <?php foreach ($clients as $c): ?>
      <tr>
        <td><code><?= e($c['license_key']) ?></code></td>
        <td><?= e($c['edition']) ?></td>
        <td><?= e($c['domain'] ?: '—') ?></td>
        <td>
          <form method="post" action="/admin/release/client/<?= (int)$c['id'] ?>" style="display:flex;gap:6px;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="text" name="client_ip" value="<?= e($c['client_ip'] ?? '') ?>" placeholder="自动绑定" style="width:150px">
            <button class="btn btn-ghost btn-sm" type="submit">保存</button>
          </form>
          <span class="muted">最近：<?= e($c['last_ip'] ?? '—') ?></span>
        </td>
        <td>
          <form method="post" action="/admin/release/client/<?= (int)$c['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="client_ip" value="<?= e($c['client_ip'] ?? '') ?>">
            <input type="hidden" name="update_enabled" value="<?= (int)($c['update_enabled'] ?? 1) ? 0 : 1 ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= (int)($c['update_enabled'] ?? 1) ? '✓ 允许（点击关闭）' : '✗ 禁止（点击开启）' ?></button>
          </form>
        </td>
        <td class="muted"><?= e($c['last_check_at'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($isHost): ?><p class="ok-text">本站域名已绑定授权 <code><?= e($isHost) ?></code>（主站自检通过）</p>
    <?php else: ?><p class="hint">提示：主站自身域名未出现在上方授权绑定中（分发服务不受此影响）。</p><?php endif; ?>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <h2>分发日志（最近 100 条）</h2>
  <table class="table">
    <tr><th>时间</th><th>动作</th><th>授权码</th><th>域名</th><th>IP</th><th>结果</th><th>明细</th></tr>
    <?php if (!$logs): ?><tr><td colspan="7" class="empty">暂无记录</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td class="muted"><?= e((string)$l['created_at']) ?></td>
      <td><?= e(['activate' => '激活', 'check' => '检查', 'token' => '令牌', 'download' => '下载', 'bind' => 'IP绑定', 'deny' => '拒绝'][$l['action']] ?? $l['action']) ?></td>
      <td class="muted"><?= e(str_take((string)$l['license'], 18, '…')) ?></td>
      <td class="muted"><?= e((string)$l['domain']) ?></td>
      <td class="muted"><?= e((string)$l['ip']) ?></td>
      <td><?= $l['result'] === 'ok' ? '<span class="ok-text">✓</span>' : '<span class="err-text">✗</span>' ?></td>
      <td class="muted"><?= e((string)$l['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
