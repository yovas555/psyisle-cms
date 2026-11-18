<?php
/** 微信支付 · 后台页（收款状态 + 配置 + 订单流水） */
$meta = $meta ?? \app\Core\PluginManager::get('wechat-pay');
$settings = $meta['settings'] ?? [];
$renderFields = function (array $settings, callable $filter) {
    foreach ($settings as $f) {
        $key = (string)($f['key'] ?? ''); if ($key === '' || !$filter($key)) continue;
        $pk = str_replace('.', '_', $key);
        $type = (string)($f['type'] ?? 'text');
        $label = (string)($f['label'] ?? $key);
        $help = (string)($f['help'] ?? '');
        $cur = setting($key, (string)($f['default'] ?? ''));
        $isSecret = \app\Core\Crypto::isEncrypted((string)$cur) || \app\Core\Crypto::isSecretKey($key) && \app\Core\Crypto::isMask((string)$cur);
        $saved = \app\Core\Crypto::isEncrypted((string)$cur);
        $options = (array)($f['options'] ?? []);
        if ($type === 'switch'): ?>
    <label class="field switch-field">
      <span class="label"><?= e($label) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($pk) ?>" value="1" <?= (string)$cur === '1' ? 'checked' : '' ?>><i></i></label>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($pk) ?>">
        <?php foreach ($options as $opt): ?>
        <option value="<?= e((string)$opt) ?>" <?= (string)$cur === (string)$opt ? 'selected' : '' ?>><?= e($opt === 'public_key' ? '微信支付公钥（2024-10 后新商户）' : ($opt === 'platform_cert' ? '平台证书（自动下载）' : (string)$opt)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'textarea'): ?>
    <label class="field field-full">
      <span class="label"><?= e($label) ?></span>
      <textarea name="<?= e($pk) ?>" rows="<?= (int)($f['rows'] ?? 4) ?>" placeholder="<?= $saved ? '••••••••（已加密保存，留空不修改）' : '' ?>"></textarea>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php else: ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <input type="<?= $type === 'password' ? 'password' : 'text' ?>" name="<?= e($pk) ?>" value="<?= $type === 'password' ? '' : e((string)$cur) ?>" placeholder="<?= $type === 'password' && $saved ? '••••••••（已保存，留空不修改）' : '' ?>" autocomplete="new-password">
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php endif; ?>
    <?php }
};
$stChip = static function (string $s): string {
    $map = ['paid' => '<span class="chip ok-text">已支付</span>', 'created' => '<span class="chip">待支付</span>', 'error' => '<span class="chip err-text">关闭/失败</span>'];
    return $map[$s] ?? e($s);
};
?>
<div class="page-guide" data-guide-key="wechat-pay">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>微信支付 · v<?= e((string)$meta['version']) ?></b>
  <?= $meta['_official'] ? '<span class="chip" style="margin-left:8px">官方签名</span>' : '<span class="chip" style="margin-left:8px;background:#fef3c7;color:#92400e">未签名</span>' ?>
  <div style="margin-top:6px;color:#475569">V3 官方 API：PC 扫码（Native）+ 微信内（JSAPI）；与咨询套餐插件联动，支付成功自动入账并通知。回调安全：SHA256-RSA2048 验签 + AES-256-GCM 解密 + 金额核对 + 幂等防重。</div>
</div>

<div class="dash-grid">
  <div class="panel">
    <h2>收款概况</h2>
    <div class="toolbar">
      <span class="chip"><?= $cfgReady && $enabled ? '<b class="ok">配置就绪 · 已启用</b>' : ($cfgReady ? '<b class="draft">配置就绪 · 未启用</b>' : '<b class="draft">配置不完整</b>') ?></span>
      <span class="chip">验签：<?= $verifyMode === 'public_key' ? '微信支付公钥' : '平台证书' ?></span>
    </div>
    <p style="margin-top:8px">
      <span class="chip ok-text">今日 <?= (int)$stats['today_paid'] ?> 笔 / ¥<?= number_format($stats['today_amount'] / 100, 2) ?></span>
      <span class="chip">本月 ¥<?= number_format($stats['month_amount'] / 100, 2) ?></span>
      <span class="chip">待入账 <?= (int)$stats['pending_credit'] ?></span>
      <span class="chip">待支付 <?= (int)$stats['created'] ?></span>
    </p>
    <h3>回调地址（商户平台 → API 安全）</h3>
    <p><code><?= e($notifyUrl) ?></code></p>
    <p class="hint">JSAPI 支付需在商户平台配置「支付授权目录」为本站路径；Native 无需目录配置。</p>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <h2>商户配置</h2>
  <form class="form" method="post" action="/admin/plugin/wechat-pay/save-settings">
    <?= csrf_field() ?>
    <div class="form-grid">
      <?php $renderFields($settings, fn ($k) => in_array($k, ['wxpay.enabled'], true)); ?>
      <?php $renderFields($settings, fn ($k) => in_array($k, ['wxpay.appid', 'wxpay.mchid', 'wxpay.mch_serial_no', 'wxpay.apiv3_key'], true)); ?>
      <?php $renderFields($settings, fn ($k) => $k === 'wxpay.mch_key_secret'); ?>
      <?php $renderFields($settings, fn ($k) => in_array($k, ['wxpay.verify_mode', 'wxpay.public_key_id', 'wxpay.public_key_pem', 'wxpay.notify_host'], true)); ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">保存配置</button></div>
  </form>
  <p class="hint">私钥/密钥/公钥均 AES-256-GCM 加密入库，保存后不再回显。配置完成先在 <b>咨询套餐</b> 中设置价格与次数，会员中心即出现「购买套餐」。</p>
</div>

<div class="panel" style="margin-top:16px">
  <h2>订单流水（最近 30）</h2>
  <?php if ($orders): ?>
  <table class="table">
    <tr><th>商户单号</th><th>套餐</th><th>金额</th><th>渠道</th><th>状态</th><th>入账</th><th>创建</th><th>支付</th><th>操作</th></tr>
    <?php foreach ($orders as $o): ?>
    <tr>
      <td><code><?= e((string)$o['out_trade_no']) ?></code></td>
      <td><?= e((string)$o['subject']) ?></td>
      <td>¥<?= number_format((int)$o['amount_cents'] / 100, 2) ?></td>
      <td><?= $o['channel'] === 'jsapi' ? 'JSAPI' : 'Native' ?></td>
      <td><?= $stChip((string)$o['status']) ?></td>
      <td><?= (int)$o['credited'] === 1 ? '<span class="chip ok-text">已入账</span>' : '<span class="chip draft">未入账</span>' ?></td>
      <td class="muted"><?= e(dt((string)$o['created_at'])) ?></td>
      <td class="muted"><?= e(dt((string)$o['paid_at'])) ?></td>
      <td>
        <div style="display:flex;gap:6px">
          <form method="post" action="/admin/plugin/wechat-pay/query-order"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">查单</button></form>
          <?php if ($o['status'] === 'paid' && (int)$o['credited'] !== 1): ?>
          <form method="post" action="/admin/plugin/wechat-pay/credit-order"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">补入账</button></form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无订单。会员从「会员中心 → 购买套餐」下单后显示在这里。</p>
  <?php endif; ?>
  <p class="hint">「查单」实时向微信查询支付状态（未支付订单可用于核实）；每天凌晨对账任务自动核对 2 小时以上的待支付订单。</p>
</div>
