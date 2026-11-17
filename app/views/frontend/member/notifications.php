<?php /** 会员中心 · 站内消息 */ ?>
<div class="member-head">
  <h1>站内消息</h1>
  <p class="muted"><a href="<?= url('/member') ?>">返回会员中心</a> · 未读 <?= (int)$unread ?> 条</p>
</div>
<?php foreach (($flashes ?? []) as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if (!$rows): ?>
<p class="muted">暂无站内消息。预约、支付、套餐变动等通知都会出现在这里。</p>
<?php else: ?>
<div class="member-grid" style="grid-template-columns:1fr">
  <?php foreach ($rows as $n): ?>
  <div class="member-card" style="<?= $n['is_read'] ? '' : 'border-color:var(--primary,#0d9488)' ?>">
    <b><?= e(['booking' => '📅', 'order' => '💰', 'assess' => '🧮', 'system' => '🔔'][$n['kind']] ?? '🔔') ?>
      <?= e((string)$n['title']) ?><?= $n['is_read'] ? '' : ' <span class="badge">未读</span>' ?></b>
    <span class="muted"><?= e((string)$n['body']) ?></span>
    <span class="muted" style="font-size:12px"><?= e((string)$n['created_at']) ?></span>
    <?php if (!empty($n['url'])): ?><em><a href="<?= url((string)$n['url']) ?>" style="color:inherit">前往 ›</a></em><?php endif; ?>
    <?php if (!$n['is_read']): ?>
    <form method="post" action="<?= url('/member/notifications/read/' . (int)$n['id']) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:4px">标记已读</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
