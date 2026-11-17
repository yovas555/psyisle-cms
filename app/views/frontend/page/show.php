<?php /** 单页 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title"><?= e($page['title']) ?></h1>
    <div class="article-content">
      <?= eraw($page['content']) ?>
    </div>
    <p class="muted">更新时间：<?= e(dt($page['updated_at'], 'Y-m-d')) ?></p>
  </div>
</section>
