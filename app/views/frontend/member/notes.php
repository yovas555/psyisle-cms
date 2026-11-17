<?php /** 会员中心 · 咨询记录列表 */ ?>
<div class="member-head">
  <h1>咨询记录</h1>
  <p class="muted">咨询师发布的咨询小结与作业训练</p>
</div>
<?php foreach (($flashes ?? []) as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if (!$rows): ?>
<p class="muted">暂无咨询记录。咨询师在咨询后发布的小结与作业会出现在这里。</p>
<?php else: ?>
<div class="member-grid">
  <?php foreach ($rows as $r): ?>
  <a class="member-card" href="<?= url('/member/notes/' . (int)$r['id']) ?>">
    <b><?= $r['kind'] === 'homework' ? '📝 作业训练' : '📋 咨询小结' ?>：<?= e((string)$r['title']) ?></b>
    <span class="muted"><?= e((string)($r['counselor_name'] ?? '')) ?> · <?= e(dt((string)$r['created_at'])) ?></span>
    <?php if (!empty($r['booking_code'])): ?><span class="muted">预约 <?= e((string)$r['booking_code']) ?>（<?= e((string)$r['prefer_date']) ?> <?= e((string)$r['prefer_slot']) ?> · <?= e((string)$r['method']) ?>）</span><?php endif; ?>
    <em>阅读 ›</em>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<p style="margin-top:14px"><a class="btn btn-ghost" href="<?= url('/member') ?>">返回会员中心</a></p>
