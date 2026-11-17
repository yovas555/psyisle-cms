<?php
/** 邮件通知插件后台：配置 + 队列状态 + 测试发信 */
use app\Core\PluginManager;

$meta = $meta ?? PluginManager::get('mail-notify');
$settings = $meta['settings'] ?? [];
?>
<div class="page-guide" data-guide-key="plugin-mail-notify">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>邮件通知 · v<?= e((string)$meta['version']) ?></b>
  <?= $meta['_official'] ? '<span class="chip" style="margin-left:8px">官方签名</span>' : '<span class="chip" style="margin-left:8px;background:#fef3c7;color:#92400e">未签名</span>' ?>
  <div style="margin-top:6px;color:#475569">预约 / 留言 / 访客提问 / AI 转人工 / 活动报名 / 系统与授权事件，实时推送到管理员与值班邮箱。危机预警与登录告警始终发送。</div>
</div>

<?php
// SMTP 完整性检查 + 服务器地址智能推断
$hostCur = trim((string)setting('mail.host', ''));
$userCur = trim((string)setting('mail.user', ''));
$suggest = '';
if ($hostCur === '' && preg_match('/@([\w\.\-]+)$/', $userCur, $m)) {
    $map = [
        'qq.com' => 'smtp.qq.com', 'foxmail.com' => 'smtp.qq.com',
        '163.com' => 'smtp.163.com', '126.com' => 'smtp.126.com',
        'sina.com' => 'smtp.sina.com', 'sohu.com' => 'smtp.sohu.com',
        'aliyun.com' => 'smtp.aliyun.com', 'gmail.com' => 'smtp.gmail.com',
        'outlook.com' => 'smtp.office365.com', 'hotmail.com' => 'smtp.office365.com',
    ];
    $suggest = $map[$m[1]] ?? ('smtp.' . $m[1]);
}
?>
<?php if ((string)setting('mail.enabled') === '1' && $hostCur === ''): ?>
<div class="alert alert-warning">
  <b>邮件已开启，但 SMTP 服务器地址为空 —— 邮件无法发出。</b>
  <?php if ($suggest !== ''): ?>
  根据发件邮箱 <code><?= e($userCur) ?></code> 推断应为 <code><?= e($suggest) ?></code>。
  <a href="javascript:void(0)" onclick="var i=document.querySelector('input[name=mail_host]');if(i){i.value='<?= e($suggest) ?>';i.focus();}">一键填入</a>（填好后点下方「保存配置」）
  <?php else: ?>
  请在上方「SMTP 服务器」填写，例如 QQ 邮箱 smtp.qq.com、163 邮箱 smtp.163.com。
  <?php endif; ?>
  <br><span class="hint">QQ/163 邮箱的「密码」处请填写邮箱设置中生成的 <b>SMTP 授权码</b>（不是登录密码），并确认已开启 SMTP 服务。</span>
</div>
<?php elseif ((string)setting('mail.enabled') === '1' && $hostCur !== '' && !$smtpReady): ?>
<div class="alert alert-warning">SMTP 参数不完整（需服务器地址 + 用户名 + 密码），请检查后再保存。</div>
<?php endif; ?>

<div class="toolbar">
  <span class="chip">SMTP：<?= $smtpReady ? '<b class="ok">已就绪</b>' : '<b class="draft">未配置</b>' ?></span>
  <span class="chip">待发送：<b><?= (int)$pending ?></b></span>
  <?php if ($failed > 0): ?><span class="chip" style="background:#fef2f2;color:#b91c1c">失败待处理：<?= (int)$failed ?></span><?php endif; ?>
  <span class="chip">收件人：<?= $recipients ? e(implode(' ; ', $recipients)) : '未配置' ?></span>
  <span class="chip" style="<?= !empty($quietOn) && !empty($quietNext) ? 'background:#fef3c7;color:#92400e' : '' ?>">
    🌙 <?= e($quietState ?? '') ?>
  </span>
</div>

<form class="form card" method="post" action="/admin/plugin/mail-notify/save-settings">
  <?= csrf_field() ?>
  <h2>SMTP 与收件人（接口数据）</h2>
  <div class="form-grid">
  <?php foreach ($settings as $f):
      $key = (string)($f['key'] ?? ''); if ($key === '') continue;
      $pk = str_replace('.', '_', $key);
      $type = (string)($f['type'] ?? 'text');
      $label = (string)($f['label'] ?? $key);
      $help = (string)($f['help'] ?? '');
      $ph = (string)($f['placeholder'] ?? '');
      $cur = setting($key, (string)($f['default'] ?? ''));
  ?>
    <?php if (strpos($key, 'mail.') !== 0 && $key !== 'notify.admin_email') continue; ?>
    <?php if ($type === 'switch'): ?>
    <label class="field switch-field">
      <span class="label"><?= e($label) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($pk) ?>" value="1" <?= ((string)$cur === '1') ? 'checked' : '' ?>><i></i></label>
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'textarea'): ?>
    <label class="field field-full">
      <span class="label"><?= e($label) ?></span>
      <textarea name="<?= e($pk) ?>" rows="<?= (int)($f['rows'] ?? 3) ?>" placeholder="<?= e($ph) ?>"><?= e((string)$cur) ?></textarea>
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
    </label>
    <?php else: ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <input type="<?= $type === 'password' ? 'password' : ($type === 'number' ? 'number' : 'text') ?>" name="<?= e($pk) ?>" value="<?= $type === 'password' ? '' : e((string)$cur) ?>" placeholder="<?= $type === 'password' && (string)$cur !== '' ? '••••••••（已保存，留空不修改）' : e($ph) ?>" autocomplete="new-password">
      <?php if ($help): ?><span class="hint"><?= e($help) ?></span><?php endif; ?>
    </label>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>

  <h2 style="margin-top:20px">🌙 夜间免打扰</h2>
  <div class="form-grid">
    <label class="field switch-field">
      <span class="label">启用夜间免打扰</span>
      <label class="switch"><input type="checkbox" name="notify_quiet_enabled" value="1" <?= setting('notify.quiet_enabled', '0') === '1' ? 'checked' : '' ?>><i></i></label>
      <span class="hint">静默期内产生的通知<b>不会丢失</b>，会自动延迟到时段结束后发送</span>
    </label>
    <label class="field switch-field">
      <span class="label">危机预警与登录告警豁免</span>
      <label class="switch"><input type="checkbox" name="notify_quiet_exempt_crisis" value="1" checked disabled><i></i></label>
      <span class="hint">危机预警与登录告警始终即时送达（v2.2.0 起为核心强制，开关固定开启）</span>
    </label>
    <label class="field">
      <span class="label">免打扰开始时间</span>
      <input type="time" name="notify_quiet_start" value="<?= e((string)setting('notify.quiet_start', '22:00')) ?>">
    </label>
    <label class="field">
      <span class="label">免打扰结束时间</span>
      <input type="time" name="notify_quiet_end" value="<?= e((string)setting('notify.quiet_end', '08:00')) ?>">
      <span class="hint">支持跨午夜（如 22:00 – 08:00）；微信/短信通知也将遵循此时段</span>
    </label>
  </div>

  <h2 style="margin-top:20px">通知类型开关</h2>
  <div class="form-grid">
  <?php foreach ($settings as $f):
      $key = (string)($f['key'] ?? '');
      if (strpos($key, 'notify.on_') !== 0) continue;
      $pk = str_replace('.', '_', $key);
      $cur = setting($key, (string)($f['default'] ?? '1'));
  ?>
    <label class="field switch-field">
      <span class="label"><?= e((string)$f['label']) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($pk) ?>" value="1" <?= ((string)$cur === '1') ? 'checked' : '' ?>><i></i></label>
    </label>
  <?php endforeach; ?>
    <label class="field switch-field">
      <span class="label">通知中手机号打码</span>
      <label class="switch"><input type="checkbox" name="notify_mask_phone" value="1" <?= setting('notify.mask_phone', '1') === '1' ? 'checked' : '' ?>><i></i></label>
    </label>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit">保存配置</button>
  </div>
</form>

<div class="panel">
  <h2>发送队列（最近 10 条）</h2>
  <table class="table">
    <tr><th>收件人</th><th>主题</th><th>状态</th><th>计划发送</th><th>尝试</th><th>失败原因</th><th>创建时间</th></tr>
    <?php if (!$recent): ?><tr><td colspan="7" class="empty">队列为空</td></tr><?php endif; ?>
    <?php foreach ($recent as $q): ?>
    <tr>
      <td><?= e($q['to_email']) ?></td>
      <td class="muted"><?= e(str_take((string)$q['subject'], 34, '…')) ?></td>
      <td><?= $q['status'] === 'sent' ? '<b class="ok">已发送</b>' : ($q['attempts'] >= 3 ? '<b class="draft">失败</b>' : '排队中') ?></td>
      <td class="muted"><?= e((string)($q['scheduled_at'] ?? '')) ?><?= strtotime((string)($q['scheduled_at'] ?? '')) > time() ? ' <span class="chip" style="font-size:10px">延迟</span>' : '' ?></td>
      <td><?= (int)$q['attempts'] ?></td>
      <td class="muted"><?= e(str_take((string)($q['error'] ?? ''), 40, '…')) ?></td>
      <td class="muted"><?= e($q['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">每分钟由服务器 cron 自动发送；SMTP 未配置时事件也入队保留，配置保存后自动补发。</p>
</div>
