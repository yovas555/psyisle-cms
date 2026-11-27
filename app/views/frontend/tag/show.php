<?php /** 标签专题聚合页 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">「<?= e($tag) ?>」专题</h1>
    <p class="page-sub">围绕「<?= e($tag) ?>」的文章、咨询师、服务与常见问题，一站式了解</p>

    <?php if ($articles): ?>
    <section class="cp-section"><h2>相关文章</h2>
      <div class="news-list">
        <?php foreach ($articles as $a): ?>
        <a class="news-item" href="<?= url('/news/' . $a['slug']) ?>">
          <div class="news-body">
            <h3><?= e($a['title']) ?></h3>
            <p><?= e(str_take((string)$a['summary'], 70)) ?></p>
            <time><?= e(substr((string)$a['published_at'], 0, 10)) ?></time>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($counselors): ?>
    <section class="cp-section"><h2>擅长「<?= e($tag) ?>」的咨询师</h2>
      <div class="chip-row">
        <?php foreach ($counselors as $c): ?>
        <a class="chip" href="<?= e(url('/counselors/' . $c['slug'])) ?>"><?= e($c['name']) ?> · <?= e(str_take((string)$c['title'], 14, '')) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($services): ?>
    <section class="cp-section"><h2>相关服务</h2>
      <div class="chip-row">
        <?php foreach ($services as $s): ?>
        <a class="chip" href="<?= e(url('/services/' . $s['slug'])) ?>"><?= e($s['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($faqs): ?>
    <section class="cp-section"><h2>常见疑问</h2>
      <div class="faq-list">
        <?php foreach ($faqs as $f): ?>
        <details class="faq-item">
          <summary><?= e($f['question']) ?></summary>
          <div class="faq-answer"><?= eraw($f['answer']) ?></div>
        </details>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <div class="article-cta">
      <a class="btn btn-primary" href="<?= url('/booking') ?>">预约「<?= e(str_take($tag, 10)) ?>」相关咨询</a>
      <a class="btn btn-ghost" href="<?= url('/message') ?>">先留言咨询</a>
    </div>
  </div>
</section>
