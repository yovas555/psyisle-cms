<?php /** 问答精选页（FAQPage 结构化数据） */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">咨询问答精选</h1>
    <p class="page-sub">会员与访客最关心的真实问题与我们的回答（已获授权匿名展示）</p>
    <?php if (!$qas): ?><p class="empty">内容整理中。</p><?php endif; ?>
    <div class="faq-list">
      <?php foreach ($qas as $x): ?>
      <details class="faq-item">
        <summary><h3><?= e($x['q']) ?></h3></summary>
        <div class="faq-answer"><?= nl2br(e($x['a'])) ?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <div class="article-cta">
      <p>你的问题没被覆盖？可以直接问我们。</p>
      <a class="btn btn-primary" href="<?= url('/message') ?>">给我留言</a>
      <a class="btn btn-ghost" href="<?= url('/chat') ?>" onclick="document.getElementById('chatFab')?.click();return false;">问 AI 客服</a>
    </div>
    <p class="muted">以上内容均经会员授权并匿名化处理，不包含可识别信息。</p>
  </div>
</section>
