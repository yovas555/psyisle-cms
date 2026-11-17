<?php /** 会员 · 阅读咨询小结/作业 */ ?>
<div class="member-login-card">
  <?php if (empty($n)): ?>
  <h1>咨询记录</h1>
  <div class="alert alert-error">记录不存在或不可见。</div>
  <?php else: ?>
  <h1><?= $n['kind'] === 'homework' ? '📝 作业训练' : '📋 咨询小结' ?>：<?= e((string)$n['title']) ?></h1>
  <p class="muted">咨询师：<?= e((string)($n['counselor_name'] ?? '')) ?> · <?= e(dt((string)$n['created_at'])) ?></p>
  <div class="card" style="margin-top:14px;padding:16px;border:1px solid var(--border,#e2e8f0);border-radius:10px;background:#fff">
    <?= eraw((string)$n['content']) ?>
  </div>
  <?php endif; ?>
  <p style="margin-top:14px"><a class="btn btn-ghost" href="<?= url('/member/notes') ?>">返回列表</a></p>
</div>
