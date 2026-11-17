<?php
/** 首页 */
$schemaList = [];
if (!empty($orgSchema)) $schemaList[] = $orgSchema;
if (!empty($faqSchema)) $schemaList[] = $faqSchema;
?>
<section class="hero">
  <?php if ($banners): ?>
  <div class="hero-slider" id="heroSlider">
    <?php foreach ($banners as $b): ?>
    <a class="hero-slide" href="<?= e($b['link'] ?: 'javascript:void(0)') ?>" <?= $b['link'] ? '' : 'onclick="return false"' ?>>
      <img src="<?= e($b['image']) ?>" alt="<?= img_alt($b['title'], $b['title'] ?? '横幅') ?>" loading="eager">
      <?php if ($b['title']): ?><span class="hero-slide-title"><?= e($b['title']) ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="hero-dots" id="heroDots"></div>
  <?php endif; ?>
  <div class="hero-inner container">
    <h1><?= e(setting('site.hero_title', '让每一次倾诉，都被温柔以待')) ?></h1>
    <p class="hero-sub"><?= e(setting('site.hero_sub', '专业 · 保密 · 温暖 —— 我们在这里，陪你一起面对')) ?></p>
    <div class="hero-cta">
      <a class="btn btn-primary btn-lg" href="<?= url('/booking') ?>">预约咨询</a>
      <a class="btn btn-ghost btn-lg" href="<?= url('/counselors') ?>">了解咨询师</a>
    </div>
    <ul class="hero-points">
      <li>一对一专业咨询</li><li>严格保密制度</li><li>多形式灵活预约</li>
    </ul>
  </div>
</section>

<?php if ($about): ?>
<section class="section section-alt">
  <div class="container about-teaser">
    <div class="about-text">
      <h2 class="section-title">关于我们</h2>
      <p class="lead"><?= e(str_take(strip_tags((string)$about['content']), 180)) ?></p>
      <a class="more-link" href="<?= url('/p/about') ?>">了解更多 →</a>
    </div>
    <div class="about-stats">
      <div class="stat"><strong><?= e(setting('site.stat_years', '8')) ?></strong><span>年从业经验</span></div>
      <div class="stat"><strong><?= e(setting('site.stat_hours', '20000')) ?></strong><span>+ 累计咨询时长</span></div>
      <div class="stat"><strong><?= e(setting('site.stat_clients', '3000')) ?></strong><span>+ 服务会员</span></div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($featured): ?>
<section class="section">
  <div class="container">
    <h2 class="section-title">咨询师团队</h2>
    <p class="section-sub">持有正规资质、持续受训，找到与你匹配的那个人</p>
    <div class="card-grid counselor-grid">
      <?php foreach ($featured as $c): ?>
      <a class="card counselor-card" href="<?= url('/counselors/' . $c['slug']) ?>">
        <div class="card-media">
          <?php if ($c['avatar']): ?><img src="<?= e(thumb($c['avatar'])) ?>" alt="咨询师<?= e($c['name']) ?>照片" loading="lazy">
          <?php else: ?><span class="avatar-ph"><?= e(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?>
        </div>
        <div class="card-body">
          <h3><?= e($c['name']) ?><small class="c-title"><?= e($c['title']) ?></small></h3>
          <p class="c-spec"><?= e(str_take((string)$c['specialties'], 40)) ?></p>
          <?php if ($c['orientation']): ?><span class="chip"><?= e(str_take((string)$c['orientation'], 16)) ?></span><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="section-more"><a class="btn btn-ghost" href="<?= url('/counselors') ?>">全部咨询师</a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($services): ?>
<section class="section section-alt">
  <div class="container">
    <h2 class="section-title">咨询服务</h2>
    <p class="section-sub">个体 · 伴侣 · 家庭 · 青少年，选择适合你的方式</p>
    <div class="card-grid service-grid">
      <?php foreach ($services as $s): ?>
      <a class="card service-card" href="<?= url('/services/' . $s['slug']) ?>">
        <div class="card-body">
          <h3><?= e($s['name']) ?></h3>
          <p><?= e(str_take((string)$s['summary'], 60)) ?></p>
          <div class="service-meta">
            <?php if ($s['format']): ?><span class="chip"><?= e($s['format']) ?></span><?php endif; ?>
            <?php if ($s['price_from']): ?><strong class="price">¥<?= e($s['price_from']) ?><?= $s['price_to'] ? ' - ' . e($s['price_to']) : '' ?></strong>
            <?php elseif ($s['price_note']): ?><strong class="price"><?= e($s['price_note']) ?></strong><?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="section-more"><a class="btn btn-ghost" href="<?= url('/services') ?>">全部服务</a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($news): ?>
<section class="section">
  <div class="container">
    <h2 class="section-title">心理资讯</h2>
    <p class="section-sub">科普知识与机构近况</p>
    <div class="news-list">
      <?php foreach ($news as $n): ?>
      <a class="news-item" href="<?= url('/news/' . $n['slug']) ?>">
        <?php if ($n['cover']): ?><img class="news-cover" src="<?= e(thumb($n['cover'])) ?>" alt="<?= img_alt($n['title']) ?>" loading="lazy"><?php endif; ?>
        <div class="news-body">
          <h3><?= e($n['title']) ?></h3>
          <p><?= e(str_take((string)$n['summary'], 70)) ?></p>
          <time datetime="<?= e(substr((string)$n['published_at'], 0, 10)) ?>"><?= e(substr((string)$n['published_at'], 0, 10)) ?></time>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="section-more"><a class="btn btn-ghost" href="<?= url('/news') ?>">更多动态</a></p>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($voices)): ?>
<section class="section section-alt">
  <div class="container">
    <h2 class="section-title">会员心声</h2>
    <p class="section-sub">已获授权并做匿名化处理的分享</p>
    <div class="voice-grid">
      <?php foreach ($voices as $v): ?>
      <div class="card voice-card">
        <div class="card-body">
          <p class="voice-content">“<?= e(str_take((string)$v['content'], 110)) ?>”</p>
          <p class="voice-meta"><strong><?= e($v['alias']) ?></strong> <span class="muted"><?= e($v['meta'] ?: '') ?></span></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($events)): ?>
<section class="section">
  <div class="container">
    <h2 class="section-title">近期活动</h2>
    <p class="section-sub">讲座 · 工作坊 · 家长课堂</p>
    <div class="card-grid service-grid">
      <?php foreach ($events as $ev): ?>
      <a class="card service-card" href="<?= url('/events/' . $ev['slug']) ?>">
        <div class="card-body">
          <div class="event-when">
            <strong><?= e(dt($ev['event_date'], 'm月d日')) ?></strong>
            <?php if ($ev['fee']): ?><span class="chip"><?= e($ev['fee']) ?></span><?php endif; ?>
          </div>
          <h3><?= e($ev['title']) ?></h3>
          <p><?= e(str_take((string)$ev['summary'], 50)) ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="section-more"><a class="btn btn-ghost" href="<?= url('/events') ?>">全部活动</a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<section class="section section-alt">
  <div class="container">
    <h2 class="section-title">常见问题</h2>
    <div class="faq-list">
      <?php foreach (array_slice($faqs, 0, 6) as $f): ?>
      <details class="faq-item">
        <summary><?= e($f['question']) ?></summary>
        <div class="faq-answer"><?= eraw($f['answer']) ?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="section-more"><a class="btn btn-ghost" href="<?= url('/faq') ?>">更多问题</a></p>
  </div>
</section>
<?php endif; ?>

<section class="section cta-band">
  <div class="container cta-inner">
    <div>
      <h2>准备好迈出第一步了吗？</h2>
      <p>填写简单信息，我们会尽快与你确认咨询安排。所有信息严格保密。</p>
    </div>
    <a class="btn btn-light btn-lg" href="<?= url('/booking') ?>">立即预约</a>
  </div>
</section>
