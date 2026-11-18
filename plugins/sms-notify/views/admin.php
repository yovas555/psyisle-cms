<?php
/** 短信通知 · 后台页（状态 + 设置 + 日志 + 计量） */
$meta = $meta ?? \app\Core\PluginManager::get('sms-notify');
$settings = $meta['settings'] ?? [];
$renderFields = function (array $settings, callable $filter) {
    foreach ($settings as $f) {
        $key = (string)($f['key'] ?? ''); if ($key === '' || !$filter($key)) continue;
        $pk = str_replace('.', '_', $key);
        $type = (string)($f['type'] ?? 'text');
        $label = (string)($f['label'] ?? $key);
        $help = (string)($f['help'] ?? '');
        $ph = (string)($f['placeholder'] ?? '');
        $cur = setting($key, (string)($f['default'] ?? ''));
        if ($type === 'switch'): ?>
    <label class="field switch-field">
      <span class="label"><?= e($label) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($pk) ?>" value="1" <?= (string)$cur === '1' ? 'checked' : '' ?>><i></i></label>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'textarea'): ?>
    <label class="field field-full">
      <span class="label"><?= e($label) ?></span>
      <textarea name="<?= e($pk) ?>" rows="<?= (int)($f['rows'] ?? 3) ?>" placeholder="<?= e($ph) ?>"><?= e((string)$cur) ?></textarea>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php else: ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <input type="<?= $type === 'password' ? 'password' : ($type === 'number' ? 'number' : 'text') ?>" name="<?= e($pk) ?>" value="<?= $type === 'password' ? '' : e((string)$cur) ?>" placeholder="<?= $type === 'password' && (string)$cur !== '' ? '••••••••（已保存，留空不修改）' : e($ph) ?>" autocomplete="new-password">
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php endif; ?>
    <?php }
};
?>
<div class="page-guide" data-guide-key="sms-notify">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>短信通知 · v<?= e((string)$meta['version']) ?></b>
  <?= $meta['_official'] ? '<span class="chip" style="margin-left:8px">官方签名</span>' : '<span class="chip" style="margin-left:8px;background:#fef3c7;color:#92400e">未签名</span>' ?>
  <div style="margin-top:6px;color:#475569">危机预警即时送达值班手机（不受免打扰限制）；AI 转人工即时提醒；其余事件默认关闭可按需开启。按条计量，费用以阿里云短信账单为准。</div>
</div>

<div class="dash-grid">
  <div class="panel">
    <h2>状态</h2>
    <div class="toolbar">
      <span class="chip">接口：<?= (setting('sms.access_key_id', '') !== '' && setting('sms.access_secret', '') !== '' && setting('sms.sign_name', '') !== '') ? '<b class="ok">已配置</b>' : '<b class="draft">未配置</b>' ?></span>
      <span class="chip">接收人：<?= $phones ? e(implode(' ; ', $phones)) : '未配置' ?></span>
      <span class="chip" style="<?= $quietOn ? 'background:#fef3c7;color:#92400e' : '' ?>">🌙 <?= e($quietState) ?></span>
    </div>
    <h3>到达率与计量</h3>
    <p>
      <span class="chip ok-text">今日成功 <?= (int)$stats['ok'] ?> 条</span>
      <span class="chip err-text">失败 <?= (int)$stats['fail'] ?></span>
      <span class="chip">队列中 <?= (int)$stats['queued'] ?></span>
      <span class="chip draft">失败(终) <?= (int)$stats['failed'] ?></span>
    </p>
    <p class="hint">近 30 天计费条数：<b><?= (int)$stats['month'] ?></b>（成功发送即计费；单价见阿里云控制台）。手机号在日志中脱敏存储。</p>
    <form method="post" action="/admin/plugin/sms-notify/send-test">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">发送测试短信</button>
    </form>
    <p class="hint">测试短信发往第一个手机号。</p>
  </div>

  <div class="panel">
    <h2>接入要点</h2>
    <table class="table">
      <tr><th>步骤</th><th>说明</th></tr>
      <tr><td>① 开通短信服务</td><td>阿里云控制台 → 短信服务；建议建仅授权 Dysmsapi 的 RAM 子账号取 AccessKey</td></tr>
      <tr><td>② 申请签名</td><td>签名=短信开头【×××】，须与机构名称一致，需备案/资质审核</td></tr>
      <tr><td>③ 申请模板</td><td>通用通知模板，变量建议：<code>${site}</code>、<code>${event}</code>、<code>${time}</code></td></tr>
      <tr><td>④ 填配置</td><td>AccessKey / 签名 / 模板 Code / 接收手机号（; 分隔）</td></tr>
    </table>
    <p class="hint">夜间免打扰与邮件/微信共用同一时段设置；危机预警（12356 危机词命中）即时送达。</p>
  </div>
</div>

<form class="form card" method="post" action="/admin/plugin/sms-notify/save-settings" style="margin-top:16px">
  <?= csrf_field() ?>
  <h2>阿里云接口</h2>
  <div class="form-grid">
    <?php $renderFields($settings, fn ($k) => in_array($k, ['sms.enabled', 'sms.access_key_id', 'sms.access_secret', 'sms.sign_name', 'sms.template_code'], true)); ?>
  </div>

  <h2 style="margin-top:20px">接收人</h2>
  <div class="form-grid">
    <?php $renderFields($settings, fn ($k) => $k === 'sms.admin_phones'); ?>
  </div>

  <h2 style="margin-top:20px">事件开关</h2>
  <div class="form-grid">
    <?php $renderFields($settings, fn ($k) => strpos($k, 'sms.on_') === 0); ?>
  </div>

  <div class="form-actions" style="margin-top:16px">
    <button class="btn btn-primary" type="submit">保存配置</button>
    <span class="hint">AccessKey Secret 加密存储</span>
  </div>
</form>

<div class="panel" style="margin-top:16px">
  <h2>发送日志（最近 12 条）</h2>
  <table class="table">
    <tr><th>时间</th><th>事件</th><th>手机号</th><th>状态</th><th>备注</th></tr>
    <?php if (!$recent): ?><tr><td colspan="5" class="empty">暂无发送记录</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td class="muted"><?= e((string)$r['created_at']) ?></td>
      <td><?= e(['booking' => '新预约', 'message' => '新留言', 'chat_escalate' => 'AI 转人工', 'crisis' => '危机预警', 'login_alert' => '登录告警', 'system' => '系统', 'event' => '活动'][$r['type']] ?? $r['type']) ?></td>
      <td class="muted"><?= e((string)$r['phone_mask']) ?></td>
      <td>
        <?php if ($r['status'] === 'ok'): ?><span class="ok-text">✓ 成功</span>
        <?php elseif ($r['status'] === 'queued'): ?><span class="draft">⏱ 延迟至 <?= e((string)$r['scheduled_at']) ?></span>
        <?php else: ?><span class="err-text">✗ <?= e($r['status']) ?></span><?php endif; ?>
      </td>
      <td class="muted"><?= e((string)($r['err'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
