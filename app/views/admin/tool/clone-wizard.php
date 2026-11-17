<?php /** 克隆/换身份向导 */ $scan = $scan ?? []; ?>
<div class="page-guide" data-guide-key="clone-wizard">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>克隆/换身份向导说明</b>
  <ol>
    <li>整库克隆建站会携带源站的微信/支付/邮件/短信/AI 凭据与授权绑定——不处理会导致客户站流量记到源站名下</li>
    <li>「可重生成」项一键替换安全值；「需人工确认」项点击直达对应设置页修改</li>
    <li>config 侧约束（issuer_public_key/stats_salt 等）不可随意更改</li>
  </ol>
</div>

<?php if (!$scan['cloned']): ?>
<div class="alert alert-success" style="border-radius:12px;margin-bottom:16px">
  <b>✓ 未检测到克隆特征</b>——当前站点身份正常。以下为全量检查清单（供新站验收参考）。
</div>
<?php else: ?>
<div class="alert alert-warning" style="border-radius:12px;margin-bottom:16px">
  <b>⚠ 检测到疑似克隆特征</b>——请逐项确认并处理以下设置。
</div>
<?php endif; ?>

<div class="panel">
  <h2>检测清单</h2>
  <table class="table">
    <tr><th>检查项</th><th>状态</th><th>详情</th></tr>
    <?php foreach ($scan['checks'] as $c): ?>
    <tr>
      <td><b><?= e($c[1]) ?></b></td>
      <td>
        <?php if ($c[2] === 'ok'): ?><span class="ok-text">✓ 正常</span>
        <?php elseif ($c[2] === 'warn'): ?><span class="draft">⚠ 需确认</span>
        <?php else: ?><span class="err-text">✗ 异常</span><?php endif; ?>
      </td>
      <td class="muted"><?= e((string)$c[3]) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if (!empty($scan['regen'])): ?>
<div class="panel">
  <h2>可一键重生成的安全键</h2>
  <?php foreach ($scan['regen'] as $r): ?>
  <div style="display:flex;gap:12px;align-items:center;margin:8px 0">
    <b style="min-width:180px"><?= e($r['label']) ?></b>
    <?php if ($r['has_value']): ?><span class="muted">已设置</span><?php else: ?><span class="draft">未设置</span><?php endif; ?>
    <form method="post" action="/admin/tools/clone-regen">
      <?= csrf_field() ?>
      <input type="hidden" name="skey" value="<?= e($r['skey']) ?>">
      <button class="btn btn-ghost btn-sm" type="submit" data-confirm="确认重生成？旧值立即失效。">重生成</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($scan['manual'])): ?>
<div class="panel">
  <h2>需人工确认/替换</h2>
  <table class="table">
    <tr><th>设置键</th><th>说明</th><th>处理建议</th></tr>
    <?php foreach ($scan['manual'] as $m): ?>
    <tr>
      <td><code><?= e($m[0]) ?></code></td>
      <td><b><?= e($m[1]) ?></b></td>
      <td class="muted"><?= e($m[2]) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="panel">
  <h2>config 侧约束（只读提示）</h2>
  <table class="table">
    <tr><th>键</th><th>说明</th></tr>
    <tr><td><code>issuer_public_key</code></td><td class="muted">授权验签公钥——必须与加密数据同源，不可随意换</td></tr>
    <tr><td><code>stats_salt</code></td><td class="muted">统计 IP 哈希盐——换掉后历史统计数据不可比对</td></tr>
    <tr><td><code>distribute.enabled</code></td><td class="muted">客户站必须为 false（仅 ww 分发主站开启）</td></tr>
    <tr><td><code>license_secret</code></td><td class="muted">离线授权签名密钥——不得带出签发方</td></tr>
    <tr><td><code>distribute.private_key</code></td><td class="muted">分发 Ed25519 私钥——仅分发主站持有</td></tr>
  </table>
</div>
