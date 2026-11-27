<?php /** 站内搜索 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">站内搜索</h1>
    <form class="search-form" method="get" action="<?= url('/search') ?>" role="search">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="搜索文章、咨询师、服务…" maxlength="50" aria-label="搜索关键词">
      <button class="btn btn-primary" type="submit">搜索</button>
    </form>

    <?php if ($q !== ''): ?>
    <p class="muted">找到 <strong><?= (int)$total ?></strong> 条与「<strong><?= e($q) ?></strong>」相关的内容</p>
    <?php if (!empty($results['error'])): ?><div class="alert alert-error"><?= e($results['error']) ?></div><?php endif; ?>

    <?php if (!empty($results['articles'])): ?>
    <section class="cp-section"><h2>文章</h2>
      <ul class="search-list">
        <?php foreach ($results['articles'] as $r): ?>
        <li><a href="<?= url('/news/' . $r['slug']) ?>"><?= e($r['title']) ?></a><span class="muted"><?= e(substr((string)$r['published_at'], 0, 10)) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if (!empty($results['counselors'])): ?>
    <section class="cp-section"><h2>咨询师</h2>
      <ul class="search-list">
        <?php foreach ($results['counselors'] as $r): ?>
        <li><a href="<?= e(url('/counselors/' . $r['slug'])) ?>"><?= e($r['name']) ?> · <?= e($r['title']) ?></a><span class="muted"><?= e(str_take((string)$r['specialties'], 30)) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if (!empty($results['services'])): ?>
    <section class="cp-section"><h2>服务</h2>
      <ul class="search-list">
        <?php foreach ($results['services'] as $r): ?>
        <li><a href="<?= e(url('/services/' . $r['slug'])) ?>"><?= e($r['name']) ?></a><span class="muted"><?= e(str_take((string)$r['summary'], 30)) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if (!empty($results['faqs'])): ?>
    <section class="cp-section"><h2>常见问题</h2>
      <ul class="search-list">
        <?php foreach ($results['faqs'] as $r): ?>
        <li><a href="<?= url('/faq') ?>"><?= e($r['question']) ?></a><span class="muted"><?= e(str_take(strip_tags((string)$r['answer']), 30)) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($total === 0 && empty($results['error'])): ?>
    <p class="empty">没有找到相关内容，换个词试试？</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
