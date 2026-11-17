<?php /** FAQ 页（FAQPage 结构化数据由 layout 输出） */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">常见问题</h1>
    <p class="page-sub">关于咨询流程、收费与保密的答疑</p>
    <?php if (!$rows): ?>
    <p class="empty">暂无内容。</p>
    <?php else: ?>
    <div class="faq-list">
      <?php foreach ($rows as $f): ?>
      <details class="faq-item">
        <summary><h3><?= e($f['question']) ?></h3></summary>
        <div class="faq-answer"><?= eraw($f['answer']) ?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="article-cta">
      <p>没有找到答案？</p>
      <a class="btn btn-primary" href="<?= url('/message') ?>">给我们留言</a>
    </div>
  </div>
</section>
