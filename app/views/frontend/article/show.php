<?php /** 文章详情 */ $schemaList = []; ?>
<article class="section article-detail">
  <div class="container narrow">
    <header class="article-head">
      <h1><?= e($a['title']) ?></h1>
      <div class="article-meta">
        <?php if ($a['author']): ?><span>作者：<?= e($a['author']) ?></span><span>·</span><?php endif; ?>
        <time datetime="<?= e((string)$a['published_at']) ?>"><?= e(dt($a['published_at'])) ?></time>
        <span>·</span><span><?= (int)$a['views'] ?> 次浏览</span>
        <?php if ($cat): ?><a class="chip" href="<?= url('/news/category/' . $cat['slug']) ?>"><?= e($cat['name']) ?></a><?php endif; ?>
      </div>
    </header>
    <?php if ($a['cover']): ?>
    <figure class="article-cover"><img src="<?= e($a['cover']) ?>" alt="<?= img_alt($a['title']) ?>"></figure>
    <?php endif; ?>
    <div class="article-content">
      <?= $a['_content_html'] ?? eraw($a['content']) ?>
    </div>
    <?php if ($a['tags']): ?>
    <div class="article-tags">
      <?php foreach (preg_split('/[、,，\s]+/u', (string)$a['tags']) as $t): if ($t): ?>
      <a class="chip" href="<?= url('/tag/' . urlencode($t)) ?>"><?= e($t) ?></a>
      <?php endif; endforeach; ?>
    </div>
    <?php endif; ?>

    <nav class="article-prevnext">
      <?php if ($prev): ?><a href="<?= url('/news/' . $prev['slug']) ?>">« <?= e($prev['title']) ?></a><?php endif; ?>
      <?php if ($next): ?><a class="next" href="<?= url('/news/' . $next['slug']) ?>"><?= e($next['title']) ?> »</a><?php endif; ?>
    </nav>

    <div class="article-cta">
      <p>如果你想聊聊自己的情况，欢迎预约一对一咨询。</p>
      <a class="btn btn-primary" href="<?= url('/booking') ?>">预约咨询</a>
    </div>

    <?php if ($related): ?>
    <section class="related">
      <h2>相关阅读</h2>
      <ul>
        <?php foreach ($related as $r): ?>
        <li><a href="<?= url('/news/' . $r['slug']) ?>"><?= e($r['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
</article>
