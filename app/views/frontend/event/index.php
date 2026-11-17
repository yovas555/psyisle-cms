<?php /** 活动列表 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">活动与工作坊</h1>
    <p class="page-sub">公益讲座 · 家长课堂 · 正念体验 —— 报名后在开始前我们会与你确认</p>
    <?php if (!$rows): ?><p class="empty">近期暂无活动，可关注「心理资讯」或留言咨询。</p><?php endif; ?>
    <div class="event-list">
      <?php foreach ($rows as $e): ?>
      <a class="card event-card" href="<?= url('/events/' . $e['slug']) ?>">
        <div class="card-body">
          <div class="event-when">
            <?php if ($e['event_date']): ?>
            <strong><?= e(dt($e['event_date'], 'm月d日 H:i')) ?></strong>
            <?php endif; ?>
            <?php if ($e['location']): ?><span class="chip"><?= e(str_take((string)$e['location'], 18)) ?></span><?php endif; ?>
            <?php if ($e['fee']): ?><span class="chip"><?= e($e['fee']) ?></span><?php endif; ?>
            <?php if ($e['status'] === 'full'): ?><span class="chip chip-top">已满员</span><?php endif; ?>
          </div>
          <h3><?= e($e['title']) ?></h3>
          <p><?= e(str_take((string)$e['summary'], 80)) ?></p>
          <?php if ($e['capacity']): ?>
          <p class="event-cap">已报 <?= (int)$e['registered'] ?> / <?= (int)$e['capacity'] ?> 人</p>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
