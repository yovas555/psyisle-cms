<?php /** 咨询师列表 */ ?>
<section class="section">
  <div class="container">
    <h1 class="page-title">咨询师团队</h1>
    <p class="page-sub">所有咨询师均持有正规资质，可通过擅长方向筛选</p>
    <?php if ($tags): ?>
    <div class="filter-chips">
      <a class="chip<?= $currentSpec === '' ? ' active' : '' ?>" href="<?= url('/counselors') ?>">全部</a>
      <?php foreach ($tags as $t => $n): ?>
      <a class="chip<?= $currentSpec === $t ? ' active' : '' ?>" href="<?= url('/counselors?spec=' . urlencode($t)) ?>"><?= e($t) ?> (<?= $n ?>)</a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
    <p class="empty">暂无咨询师信息。</p>
    <?php else: ?>
    <div class="card-grid counselor-grid">
      <?php foreach ($rows as $c): ?>
      <a class="card counselor-card" href="<?= url('/counselors/' . $c['slug']) ?>">
        <div class="card-media">
          <?php if ($c['avatar']): ?><img src="<?= e(thumb($c['avatar'])) ?>" alt="咨询师<?= e($c['name']) ?>照片" loading="lazy">
          <?php else: ?><span class="avatar-ph"><?= e(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?>
          <?php if ($c['is_featured']): ?><span class="featured-badge">推荐</span><?php endif; ?>
        </div>
        <div class="card-body">
          <h3><?= e($c['name']) ?><small class="c-title"><?= e($c['title']) ?></small></h3>
          <?php if ($c['orientation']): ?><p class="c-orient">流派：<?= e(str_take((string)$c['orientation'], 20)) ?></p><?php endif; ?>
          <p class="c-spec"><?= e(str_take((string)$c['specialties'], 40)) ?></p>
          <?php if ($c['hours']): ?><p class="c-hours">累计咨询 <?= (int)$c['hours'] ?> 小时</p><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
