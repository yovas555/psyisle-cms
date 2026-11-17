<?php /** 通知中心 */ ?>
<?php
// 通知渠道状态（v2.2.0 渠道注册制）：站内为内置渠道，其余由插件注册
$channelRows = [['name' => 'inbox', 'label' => '站内通知中心', 'enabled' => true, 'stats' => null]];
foreach (\app\Services\NotifyService::channels() as $ch) {
    $channelRows[] = ['name' => $ch->name(), 'label' => $ch->label(), 'enabled' => $ch->enabled(), 'stats' => $ch->stats()];
}
?>
<div class="panel" style="margin-bottom:14px">
  <h2>通知渠道</h2>
  <div class="toolbar">
    <?php foreach ($channelRows as $c): ?>
    <span class="chip" style="<?= $c['enabled'] ? '' : 'background:#f1f5f9;color:#64748b' ?>">
      <?= $c['enabled'] ? '<b class="ok">✓</b>' : '—' ?> <?= e($c['label']) ?>
      <?php if (is_array($c['stats'])): ?>
      <span class="muted">今日 成功<?= (int)$c['stats']['ok'] ?> / 失败<?= (int)$c['stats']['fail'] ?> / 队列<?= (int)$c['stats']['queued'] ?></span>
      <?php endif; ?>
    </span>
    <?php endforeach; ?>
    <span class="muted">渠道由插件提供（邮件 = 「邮件通知」插件 · 微信 = 「微信服务号通知」插件 · 短信 = 「短信通知」插件），危机预警与登录告警即时送达所有渠道</span>
  </div>
</div>
<div class="toolbar">
  <button class="btn btn-ghost" id="markAllRead" type="button">全部标为已读</button>
  <button class="btn btn-danger" id="clearAllNtf" type="button" data-confirm="清空全部通知（含未读）？此操作不可恢复。">清空全部</button>
  <span class="muted">重要事件会同时推送到管理员邮箱（系统设置中配置）</span>
</div>
<table class="table table-hover">
  <thead><tr><th>类型</th><th>标题</th><th>内容</th><th>时间</th><th>状态</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无通知</td></tr><?php endif; ?>
  <?php foreach ($rows as $n): ?>
  <tr class="<?= $n['is_read'] ? '' : 'unread' ?>">
    <td><?= e(['booking' => '预约', 'message' => '留言', 'chat_escalate' => '转人工', 'crisis' => '危机', 'login_alert' => '告警', 'system' => '系统', 'upgrade' => '升级', 'license' => '授权', 'member' => '会员'][$n['type']] ?? $n['type']) ?></td>
    <td><b><?php
        // 按类型跳转对应业务记录
        $jumpMap = [
            'booking' => '/admin/bookings/' . (int)$n['ref_id'] . '/edit',
            'message' => '/admin/messages/' . (int)$n['ref_id'] . '/edit',
            'chat_escalate' => '/admin/chat/' . (int)$n['ref_id'] . '/edit',
            'crisis' => '/admin/chat/' . (int)$n['ref_id'] . '/edit',
            'upgrade' => '/admin/license', 'license' => '/admin/license', 'member' => '/admin/members', 'system' => null,
        ];
        $jump = $jumpMap[$n['type']] ?? null;
        if ($jump && $n['ref_id'] !== '' && $n['ref_id'] !== '0'):
    ?><a href="<?= e($jump) ?>"><?= e($n['title']) ?></a><?php else: ?><?= e($n['title']) ?><?php endif; ?></b></td>
    <td class="muted"><?= nl2br(e(str_take((string)$n['body'], 80, '…'))) ?></td>
    <td class="muted"><?= e($n['created_at']) ?></td>
    <td><?= $n['is_read'] ? '已读' : '<b class="badge">未读</b>' ?></td>
    <td class="ops">
      <?php if (!$n['is_read']): ?>
      <button class="btn btn-sm btn-ghost js-mark" data-id="<?= (int)$n['id'] ?>" type="button">标已读</button>
      <?php endif; ?>
      <button class="btn btn-sm btn-danger js-del-ntf" data-id="<?= (int)$n['id'] ?>" type="button">删除</button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($pages > 1): ?>
<nav class="pagination">
  <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
    <?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
</nav>
<?php endif; ?>
