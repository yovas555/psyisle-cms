<?php /** 服务详情 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title"><?= e($s['name']) ?></h1>
    <?php if ($s['summary']): ?><p class="lead"><?= e($s['summary']) ?></p><?php endif; ?>

    <div class="service-info card">
      <div class="card-body">
        <?php if ($s['format']): ?><p><strong>咨询形式：</strong><?= e($s['format']) ?></p><?php endif; ?>
        <?php if ($s['duration']): ?><p><strong>单次时长：</strong><?= e($s['duration']) ?></p><?php endif; ?>
        <?php if ($s['price_from']): ?>
        <p><strong>收费标准：</strong>¥<?= e($s['price_from']) ?><?= $s['price_to'] ? ' - ¥' . e($s['price_to']) : '' ?><?= $s['price_note'] ? '（' . e($s['price_note']) . '）' : '' ?></p>
        <?php elseif ($s['price_note']): ?><p><strong>收费说明：</strong><?= e($s['price_note']) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="article-content"><?= eraw($s['content']) ?></div>

    <div class="article-cta">
      <a class="btn btn-primary btn-lg" href="<?= url('/booking?service=' . (int)$s['id']) ?>">预约此服务</a>
      <a class="btn btn-ghost btn-lg" href="<?= url('/message') ?>">先咨询问题</a>
    </div>

    <?php if ($counselors): ?>
    <section class="cp-section">
      <h2>提供此服务的咨询师</h2>
      <div class="chip-row">
        <?php foreach ($counselors as $c): ?>
        <a class="chip" href="<?= e(url('/counselors/' . $c['slug'])) ?>"><?= e($c['name']) ?><?= $c['title'] ? ' · ' . e($c['title']) : '' ?></a>
        <?php endforeach; ?>
      </div>
      <p class="hint">仅展示机构已明确关联此服务的咨询师；咨询方式与可约时间请查看个人档案。</p>
    </section>
    <?php else: ?>
    <p class="hint">机构尚未关联可提供此服务的咨询师，可先提交预约申请，由机构协助安排。</p>
    <?php endif; ?>
  </div>
</section>
