<?php /** 文章列表 */ ?>
<?php $currentCat = $currentCat ?? null; // 列表页与分类页共用本视图，缺省视为「全部」 ?>
<section class="section">
  <div class="container">
    <h1 class="page-title"><?= e($currentCat['name'] ?? '心理资讯') ?></h1>
    <?php if ($cats): ?>
    <div class="filter-chips">
      <a class="chip<?= empty($currentCat) ? ' active' : '' ?>" href="<?= url('/news') ?>">全部</a>
      <?php foreach ($cats as $c): ?>
      <a class="chip<?= ($currentCat['id'] ?? 0) === $c['id'] ? ' active' : '' ?>" href="<?= url('/news/category/' . $c['slug']) ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$data['rows']): ?>
    <p class="empty">暂无内容，敬请期待。</p>
    <?php else: ?>
    <div class="news-list">
      <?php foreach ($data['rows'] as $n): ?>
      <a class="news-item" href="<?= url('/news/' . $n['slug']) ?>">
        <?php if ($n['cover']): ?><img class="news-cover" src="<?= e(thumb($n['cover'])) ?>" alt="<?= img_alt($n['title']) ?>" loading="lazy"><?php endif; ?>
        <div class="news-body">
          <h2><?= e($n['title']) ?></h2>
          <p><?= e(str_take((string)$n['summary'], 80)) ?></p>
          <div class="news-meta">
            <time datetime="<?= e(substr((string)$n['published_at'], 0, 10)) ?>"><?= e(substr((string)$n['published_at'], 0, 10)) ?></time>
            <span>·</span><span><?= (int)$n['views'] ?> 次浏览</span>
            <?php if ($n['is_top']): ?><span class="chip chip-top">置顶</span><?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($data['pages'] > 1): ?>
    <nav class="pagination" aria-label="分页">
      <?php for ($i = 1; $i <= $data['pages']; $i++):
        $base = $currentCat ? url('/news/category/' . $currentCat['slug']) : url('/news'); ?>
        <?php if ($i === $data['page']): ?><span class="cur"><?= $i ?></span>
        <?php else: ?><a href="<?= $base . ($i > 1 ? '?page=' . $i : '') ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </nav>
    <?php endif; ?>
  </div>
</section>
