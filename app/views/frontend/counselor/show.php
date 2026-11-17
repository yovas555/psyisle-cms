<?php /** 咨询师详情 */ ?>
<section class="section">
  <div class="container narrow">
    <div class="counselor-profile">
      <div class="cp-media">
        <?php if ($c['avatar']): ?><img src="<?= e($c['avatar']) ?>" alt="咨询师<?= e($c['name']) ?>照片">
        <?php else: ?><span class="avatar-ph avatar-lg"><?= e(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?>
      </div>
      <div class="cp-info">
        <h1><?= e($c['name']) ?> <small><?= e($c['title']) ?></small></h1>
        <?php if ($c['orientation']): ?><p class="cp-line"><strong>咨询流派：</strong><?= e($c['orientation']) ?></p><?php endif; ?>
        <?php if ($c['specialties']): ?><p class="cp-line"><strong>擅长方向：</strong><?= e($c['specialties']) ?></p><?php endif; ?>
        <?php if ($c['hours']): ?><p class="cp-line"><strong>咨询时长：</strong>累计 <?= (int)$c['hours'] ?> 小时</p><?php endif; ?>
        <div class="cp-fee">
          <?php if ($c['fee_from']): ?>
            <strong>咨询收费：</strong>¥<?= e($c['fee_from']) ?><?= $c['fee_to'] ? ' - ¥' . e($c['fee_to']) : '' ?> / <?= e($c['fee_unit'] ?: '50分钟') ?>
          <?php else: ?><strong>收费：</strong>面议<?php endif; ?>
        </div>
        <div class="cp-actions">
          <a class="btn btn-primary" href="<?= url('/booking?counselor=' . (int)$c['id'] . '&source=counselor') ?>">预约 <?= e($c['name']) ?></a>
          <a class="btn btn-ghost" href="<?= url('/message') ?>">先留言咨询</a>
        </div>
      </div>
    </div>

    <?php if (!empty($c['video_url'])):
        $vu = (string)$c['video_url'];
        $embed = '';
        // 白名单平台嵌入（B 站 / 腾讯视频），杜绝任意 iframe
        if (preg_match('#bilibili\.com/video/(bv|BV)([0-9A-Za-z]+)#', $vu, $m)) {
            $embed = 'https://player.bilibili.com/player.html?bvid=' . $m[1] . $m[2] . '&autoplay=0';
        } elseif (preg_match('#v\.qq\.com/x/cover/[^/]+/([0-9A-Za-z]+)\.html#', $vu, $m)) {
            $embed = 'https://v.qq.com/txp/iframe/player.html?vid=' . $m[1];
        }
        if ($embed !== ''): ?>
    <section class="cp-section">
      <h2>视频介绍</h2>
      <div class="video-embed"><iframe src="<?= e($embed) ?>" allowfullscreen title="<?= e($c['name']) ?> 视频介绍" loading="lazy"></iframe></div>
    </section>
    <?php endif; endif; ?>

    <?php foreach ([
        'introduction' => '个人简介',
        'education' => '教育背景',
        'experience' => '受训与从业经历',
        'style' => '咨询风格',
    ] as $field => $label):
        $hasContent = trim(preg_replace('/[\s\x{00a0}]+/u', '', (string)strip_tags((string)$c[$field]))) !== '';
        if ($hasContent): ?>
    <section class="cp-section">
      <h2><?= e($label) ?></h2>
      <div class="article-content"><?= eraw($c[$field]) ?></div>
    </section>
    <?php endif; endforeach; ?>

    <?php if (!empty($calendar)): ?>
    <section class="cp-section">
      <h2>未来两周可约日历</h2>
      <div class="calendar-strip">
        <?php foreach ($calendar as $day): ?>
        <div class="cal-day">
          <b><?= e($day['date']) ?></b>
          <span class="muted"><?= e($day['week']) ?></span>
          <?php foreach ($day['slots'] as $t): ?><span class="chip"><?= e($t) ?></span><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="muted">实际时间以确认回复为准，可点击上方「预约」直接选择。</p>
    </section>
    <?php endif; ?>
    <?php if ($slots): ?>
    <section class="cp-section">
      <h2>每周固定时段</h2>
      <table class="table">
        <thead><tr><th>星期</th><th>时段</th></tr></thead>
        <tbody>
        <?php $week = [1=>'周一',2=>'周二',3=>'周三',4=>'周四',5=>'周五',6=>'周六',7=>'周日'];
        foreach ($slots as $s): ?>
          <tr><td><?= e($week[$s['weekday']] ?? '') ?></td><td><?= e(substr((string)$s['start_time'],0,5)) ?> - <?= e(substr((string)$s['end_time'],0,5)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted">实际时间以确认回复为准。</p>
    </section>
    <?php endif; ?>
  </div>
</section>
