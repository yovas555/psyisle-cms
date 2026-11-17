<?php /** 插件设置页（接口数据可视化编辑） */ ?>
<?php
/** @var array $metaV @var string $slugV */
$meta = $meta ?? ($metaV ?? []);
$slug = $slug ?? ($slugV ?? '');
$settings = $meta['settings'] ?? [];
?>
<div class="page-guide" data-guide-key="plugin-<?= e($slug) ?>">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b><?= e((string)$meta['name']) ?> · v<?= e((string)$meta['version']) ?></b>
  <?= $meta['_official'] ? '<span class="chip" style="margin-left:8px">官方签名</span>' : '<span class="chip" style="margin-left:8px;background:#fef3c7;color:#92400e">未签名</span>' ?>
  <div style="margin-top:6px;color:#475569"><?= e((string)($meta['description'] ?? '')) ?></div>
  <div class="hint" style="margin-top:6px">
    插件 slug：<code><?= e($slug) ?></code>
    · 作者：<?= e((string)($meta['author'] ?? '')) ?>
    <?php if (!empty($meta['min_cms_version'])): ?>· 最低核心版本：<?= e((string)$meta['min_cms_version']) ?><?php endif; ?>
    <?php if (!empty($meta['dependencies'])): ?>· 依赖：<?= e(implode(', ', (array)$meta['dependencies'])) ?><?php endif; ?>
  </div>
</div>

<?php if (!$settings): ?>
<div class="panel"><p class="muted">该插件没有可配置项。</p></div>
<?php else: ?>
<form class="form card" method="post" action="/admin/plugin/<?= e($slug) ?>/save-settings">
  <?= csrf_field() ?>
  <h2>插件配置（接口与运行参数）</h2>
  <div class="form-grid">
  <?php foreach ($settings as $f):
      $key = (string)($f['key'] ?? '');
      if ($key === '') continue;
      $pk = str_replace('.', '_', $key);
      $type = (string)($f['type'] ?? 'text');
      $label = (string)($f['label'] ?? $key);
      $help = (string)($f['help'] ?? '');
      $ph = (string)($f['placeholder'] ?? '');
      $cur = setting($key, (string)($f['default'] ?? ''));
  ?>
    <?php if ($type === 'switch'): ?>
    <label class="field switch-field">
      <span class="label"><?= e($label) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($pk) ?>" value="1" <?= ((string)$cur === '1') ? 'checked' : '' ?>><i></i></label>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'textarea'): ?>
    <label class="field field-full">
      <span class="label"><?= e($label) ?></span>
      <textarea name="<?= e($pk) ?>" rows="<?= (int)($f['rows'] ?? 4) ?>" placeholder="<?= e($ph) ?>"><?= e((string)$cur) ?></textarea>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($pk) ?>">
        <?php foreach ((array)($f['options'] ?? []) as $ov => $ol): ?>
        <option value="<?= e((string)$ov) ?>" <?= (string)$cur === (string)$ov ? 'selected' : '' ?>><?= e((string)$ol) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php else: ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <?php $isPwd = $type === 'password'; ?>
      <input type="<?= $isPwd ? 'password' : ($type === 'number' ? 'number' : 'text') ?>"
             name="<?= e($pk) ?>" value="<?= $isPwd ? '' : e((string)$cur) ?>"
             placeholder="<?= $isPwd && (string)$cur !== '' ? '••••••••（已保存，留空不修改）' : e($ph) ?>"
             <?= $type === 'number' ? 'step="1"' : '' ?> autocomplete="new-password">
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">保存插件配置</button>
    <a class="btn btn-ghost" href="/admin/plugins">返回插件列表</a>
  </div>
</form>
<?php endif; ?>

<?php if (!empty($meta['cron'])): ?>
<div class="panel">
  <h2>定时任务</h2>
  <table class="table">
    <tr><th>频率</th><th>执行方法</th></tr>
    <?php foreach ($meta['cron'] as $job): ?>
    <tr><td><?= e($job['schedule'] === 'minutely' ? '每分钟' : '每日') ?></td><td><code><?= e((string)($job['action'] ?? '')) ?></code></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">由服务器 cron 触发：分钟任务 <code>/admin/cron/minute</code>、每日任务 <code>/admin/cron/plugins</code>（Header 鉴权）。</p>
</div>
<?php endif; ?>
