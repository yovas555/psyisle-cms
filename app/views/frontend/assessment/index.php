<?php /** 量表列表 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">心理自评量表</h1>
    <p class="page-sub">国际通用筛查工具，约 3-5 分钟完成。结果仅供自我了解，不构成诊断。</p>
    <?php if (!$rows): ?><p class="empty">暂无量表。</p><?php endif; ?>
    <div class="assess-list">
      <?php foreach ($rows as $a): ?>
      <a class="card assess-card" href="<?= url('/assessment/' . $a['slug']) ?>">
        <div class="card-body">
          <h3><?= e($a['name']) ?></h3>
          <p><?= e(str_take((string)$a['description'], 90)) ?></p>
          <span class="more-link">开始测评 →</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="alert alert-warning" style="margin-top:24px">
      如你正处于强烈的痛苦中，或有伤害自己的念头，请立即拨打 24 小时心理援助热线
      <a href="tel:12356"><strong>12356</strong></a> 或查看
      <a href="<?= url('/p/crisis') ?>">危机干预资源</a>。
    </div>
  </div>
</section>
