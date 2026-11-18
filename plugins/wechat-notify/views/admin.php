<?php
/** 微信服务号通知 · 后台页（状态 + 设置 + 日志） */
$meta = $meta ?? \app\Core\PluginManager::get('wechat-notify');
$settings = $meta['settings'] ?? [];
/** 渲染一段设置字段（按键名前缀过滤） */
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
<div class="page-guide" data-guide-key="wechat-notify">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>微信通知 · v<?= e((string)$meta['version']) ?></b>
  <?= $meta['_official'] ? '<span class="chip" style="margin-left:8px">官方签名</span>' : '<span class="chip" style="margin-left:8px;background:#fef3c7;color:#92400e">未签名</span>' ?>
  <div style="margin-top:6px;color:#475569">新预约 / 留言 / AI 转人工 / 危机预警，实时推送到管理员的微信服务号。需认证服务号；夜间免打扰与邮件共用同一时段（危机即时送达）；失败自动降级邮件。</div>
</div>

<div class="dash-grid">
  <div class="panel">
    <h2>状态</h2>
    <div class="toolbar">
      <span class="chip">接口：<?= (setting('wechat.appid', '') !== '' && setting('wechat.secret', '') !== '') ? '<b class="ok">AppID/Secret 已配置</b>' : '<b class="draft">未配置</b>' ?></span>
      <span class="chip">接收人：<?= $openids ? e(implode(' ; ', $openids)) : '未配置' ?></span>
      <span class="chip">模板：已配 <?= (int)$tplCount ?>/4</span>
      <span class="chip" style="<?= $quietOn ? 'background:#fef3c7;color:#92400e' : '' ?>">🌙 <?= e($quietState) ?></span>
    </div>
    <h3>今日到达率</h3>
    <p>
      <span class="chip ok-text">成功 <?= (int)$stats['ok'] ?></span>
      <span class="chip err-text">失败 <?= (int)$stats['fail'] ?></span>
      <span class="chip">队列中 <?= (int)$stats['queued'] ?></span>
      <span class="chip draft">失败(终) <?= (int)$stats['failed'] ?></span>
    </p>
    <form method="post" action="/admin/plugin/wechat-notify/send-test">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">发送测试消息</button>
    </form>
    <p class="hint">测试消息发往第一个 OpenID，走「系统」模板；收到即表示配置正确。</p>
  </div>

  <div class="panel">
    <h2>模板消息申请要点</h2>
    <p class="hint">公众号后台 → 模板消息 → 选用/申请新模板。模板字段命名需包含：</p>
    <table class="table">
      <tr><th>字段</th><th>内容</th></tr>
      <tr><td>first</td><td>【站点名】事件类型（危机为红色）</td></tr>
      <tr><td>keyword1</td><td>事件标题</td></tr>
      <tr><td>keyword2</td><td>内容摘要</td></tr>
      <tr><td>keyword3</td><td>发生时间</td></tr>
      <tr><td>remark</td><td>「详情请登录后台处理」</td></tr>
    </table>
    <p class="hint">新预约类模板可加跳转：发送时自动带后台预约详情链接。</p>
  </div>
</div>

<form class="form card" method="post" action="/admin/plugin/wechat-notify/save-settings" style="margin-top:16px">
  <?= csrf_field() ?>
  <input type="hidden" name="_full_settings" value="1">
  <h2>接口与接收人</h2>
  <div class="form-grid">
    <?php $renderFields($settings, fn ($k) => in_array($k, ['wechat.enabled', 'wechat.appid', 'wechat.secret', 'wechat.admin_openids'], true)); ?>
  </div>

  <h2 style="margin-top:20px">事件模板 ID</h2>
  <div class="form-grid">
    <?php $renderFields($settings, fn ($k) => strpos($k, 'wechat.tpl_') === 0); ?>
  </div>

  <h2 style="margin-top:20px">事件开关</h2>
  <div class="form-grid">
    <?php $renderFields($settings, fn ($k) => strpos($k, 'wechat.on_') === 0); ?>
  </div>

  <div class="form-actions" style="margin-top:16px">
    <button class="btn btn-primary" type="submit">保存配置</button>
    <span class="hint">AppSecret 加密存储，保存后以掩码显示</span>
  </div>
</form>

<div class="panel" style="margin-top:16px">
  <h2>发送日志（最近 12 条）</h2>
  <table class="table">
    <tr><th>时间</th><th>事件</th><th>接收人</th><th>状态</th><th>备注</th></tr>
    <?php if (!$recent): ?><tr><td colspan="5" class="empty">暂无发送记录</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td class="muted"><?= e((string)$r['created_at']) ?></td>
      <td><?= e(['booking' => '新预约', 'message' => '新留言', 'chat_question' => '访客提问', 'chat_escalate' => 'AI 转人工', 'crisis' => '危机预警', 'login_alert' => '登录告警', 'system' => '系统', 'upgrade' => '升级', 'license' => '授权', 'event' => '活动'][$r['type']] ?? $r['type']) ?></td>
      <td class="muted"><?= e((string)$r['openid_mask']) ?></td>
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
