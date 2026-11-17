<?php /** 留言回复查询 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">回复查询</h1>

    <?php if ($newCode): ?>
    <div class="alert alert-success">
      留言已提交！你的查询码是 <strong><?= e($newCode) ?></strong>，请保存。回复后凭此码查看。
    </div>
    <?php endif; ?>

    <form class="form card" method="post" action="<?= url('/message/reply') ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label class="field">
          <span class="label">查询码 <em>*</em></span>
          <input type="text" name="code" required maxlength="8">
        </label>
        <label class="field">
          <span class="label">联系方式（校验）</span>
          <input type="text" name="contact" maxlength="120" placeholder="留言时填写的联系方式">
        </label>
      </div>
      <button class="btn btn-primary" type="submit">查看</button>
    </form>

    <?php if ($result): ?>
      <?php if (!empty($result['error'])): ?>
      <div class="alert alert-error"><?= e($result['error']) ?></div>
      <?php else: $m = $result['message']; ?>
      <div class="card result-card">
        <h2>留言内容</h2>
        <p class="muted"><?= e($m['subject'] ?: '（无主题）') ?> · 提交于 <?= e($m['created_at']) ?> · 状态：<?= e($m['status'] === 'pending' ? '待回复' : ($m['status'] === 'replied' ? '已回复' : '已关闭')) ?></p>
        <blockquote class="msg-content"><?= nl2br(e($m['content'])) ?></blockquote>

        <?php if ($result['replies']): ?>
        <h2>回复</h2>
        <?php foreach ($result['replies'] as $r): ?>
        <div class="reply-item">
          <p class="reply-meta"><?= e($r['display_name'] ?: '咨询师团队') ?> · <?= e($r['created_at']) ?></p>
          <div class="reply-body"><?= nl2br(e($r['content'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <p class="empty">还没有回复，我们会尽快处理。</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
