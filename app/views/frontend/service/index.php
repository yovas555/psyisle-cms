<?php /** 服务列表 */ ?>
<section class="section">
  <div class="container">
    <h1 class="page-title">咨询服务</h1>
    <p class="page-sub">了解项目形式与收费，选择适合你的开始方式</p>
    <?php if (!$rows): ?>
    <p class="empty">暂无服务项目。</p>
    <?php else: ?>
    <div class="card-grid service-grid">
      <?php foreach ($rows as $s): ?>
      <a class="card service-card" href="<?= url('/services/' . $s['slug']) ?>">
        <?php if ($s['cover']): ?><div class="card-media"><img src="<?= e($s['cover']) ?>" alt="<?= img_alt($s['name']) ?>" loading="lazy"></div><?php endif; ?>
        <div class="card-body">
          <h3><?= e($s['name']) ?></h3>
          <p><?= e(str_take((string)$s['summary'], 70)) ?></p>
          <div class="service-meta">
            <?php if ($s['format']): ?><span class="chip"><?= e($s['format']) ?></span><?php endif; ?>
            <?php if ($s['duration']): ?><span class="chip"><?= e($s['duration']) ?></span><?php endif; ?>
            <?php if ($s['price_from']): ?><strong class="price">¥<?= e($s['price_from']) ?><?= $s['price_to'] ? ' - ' . e($s['price_to']) : '' ?></strong>
            <?php elseif ($s['price_note']): ?><strong class="price"><?= e($s['price_note']) ?></strong><?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
