<?php
/** 咨询师详情 */
$hasText = static function ($value): bool {
    return trim(preg_replace('/[\s\x{00a0}]+/u', '', (string)strip_tags((string)$value))) !== '';
};
$modeText = $serviceModes ? implode(' / ', $serviceModes) : '面对面 / 视频 / 电话';
$bookingUrl = url('/booking?counselor=' . (int)$c['id'] . '&source=counselor');
$feeText = \app\Services\BookingPricing::formatRange($priceRange['from'] ?? null, $priceRange['to'] ?? null);
?>
<section class="section counselor-detail">
  <div class="container narrow">
    <div class="counselor-profile">
      <div class="cp-media">
        <?php if ($c['avatar']): ?><img src="<?= e($c['avatar']) ?>" alt="咨询师<?= e($c['name']) ?>照片">
        <?php else: ?><span class="avatar-ph avatar-lg"><?= e(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?>
      </div>
      <div class="cp-info">
        <p class="cp-kicker">咨询师档案</p>
        <h1><?= e($c['name']) ?> <small><?= e($c['title']) ?></small></h1>
        <div class="cp-status<?= !empty($c['on_leave']) ? ' is-away' : '' ?>">
          <span class="status-dot"></span>
          <?= !empty($c['on_leave']) ? '当前请假，暂停接单' : ($firstAvailable ? '最早可约 ' . e(date('n月j日 H:i', strtotime($firstAvailable))) : '未来两周暂无可约时段，可提交申请') ?>
        </div>
        <?php if ($c['orientation']): ?><p class="cp-line"><strong>咨询流派：</strong><?= e($c['orientation']) ?></p><?php endif; ?>
        <?php if ($c['specialties']): ?><p class="cp-line"><strong>擅长方向：</strong><?= e($c['specialties']) ?></p><?php endif; ?>
        <div class="cp-facts">
          <span><b>服务方式</b><?= e($modeText) ?></span>
          <?php if ($c['hours']): ?><span><b>累计咨询</b><?= (int)$c['hours'] ?> 小时</span><?php endif; ?>
          <?php if ($c['languages']): ?><span><b>咨询语言</b><?= e($c['languages']) ?></span><?php endif; ?>
        </div>
        <div class="cp-fee">
          <strong>咨询收费：</strong><?= e($feeText) ?> / <?= e($c['fee_unit'] ?: '50分钟') ?>
        </div>
        <div class="cp-actions">
          <a class="btn btn-primary" href="<?= e($bookingUrl) ?>">预约 <?= e($c['name']) ?></a>
          <a class="btn btn-ghost" href="#availability">查看可约时段</a>
          <a class="btn btn-ghost" href="<?= url('/message') ?>">先留言咨询</a>
        </div>
        <?php if ($serviceModes): ?>
        <div class="cp-mode-links" aria-label="按服务方式预约">
          <span>直接预约</span>
          <?php foreach ($serviceModes as $mode): ?>
          <a href="<?= e($bookingUrl . '&method=' . urlencode($mode)) ?>"><?= e($mode) ?>咨询</a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($services): ?>
    <section class="cp-section service-matrix" aria-labelledby="service-matrix-title">
      <div class="cp-section-head">
        <h2 id="service-matrix-title">服务项目与收费</h2>
        <p>以下仅列出 <?= e($c['name']) ?> 已关联的服务。选择服务后，预约价格以该服务为基准；机构设置的分时定价优先。</p>
      </div>
      <div class="service-matrix-grid">
        <?php foreach ($services as $s):
          $sPrice = \app\Services\BookingPricing::formatRange((float)($s['price_from'] ?? 0) ?: null, (float)($s['price_to'] ?? 0) ?: null);
        ?>
        <article class="service-plan">
          <div class="service-plan-head">
            <h3><?= e($s['name']) ?></h3>
            <strong><?= e($sPrice) ?></strong>
          </div>
          <?php if ($s['summary']): ?><p class="service-plan-summary"><?= e(str_take((string)$s['summary'], 90)) ?></p><?php endif; ?>
          <dl class="service-plan-meta">
            <?php if ($s['format']): ?><div><dt>咨询形式</dt><dd><?= e($s['format']) ?></dd></div><?php endif; ?>
            <?php if ($s['duration']): ?><div><dt>单次时长</dt><dd><?= e($s['duration']) ?></dd></div><?php endif; ?>
            <?php if ($s['price_note']): ?><div><dt>收费说明</dt><dd><?= e($s['price_note']) ?></dd></div><?php endif; ?>
          </dl>
          <div class="service-plan-actions">
            <a class="btn btn-primary btn-sm" href="<?= url('/booking?counselor=' . (int)$c['id'] . '&service=' . (int)$s['id'] . '&source=counselor') ?>">选择此服务</a>
            <a class="text-link" href="<?= e(url('/services/' . $s['slug'])) ?>">服务详情</a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($hasText($trust['audiences']) || $hasText($trust['unsuitable_topics']) || $hasText($trust['personal_therapy'])): ?>
    <section class="cp-section trust-section">
      <h2>服务对象与专业边界</h2>
      <p class="hint">服务对象与不适合议题由机构维护；个人体验为咨询师自述。此信息用于预约前判断适配度，不替代初次评估。</p>
      <div class="trust-grid">
        <?php if ($hasText($trust['audiences'])): ?><div><h3>主要服务对象</h3><p><?= nl2br(e($trust['audiences'])) ?></p></div><?php endif; ?>
        <?php if ($hasText($trust['unsuitable_topics'])): ?><div><h3>暂不适合的情形</h3><p><?= nl2br(e($trust['unsuitable_topics'])) ?></p></div><?php endif; ?>
        <?php if ($hasText($trust['personal_therapy'])): ?><div><h3>个人体验与自我觉察（自述）</h3><p><?= nl2br(e($trust['personal_therapy'])) ?></p></div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($hasText($trust['self_credentials']) || $hasText($trust['self_supervision']) || array_filter($trust['verifications'], fn($v) => $v['status'] !== 'unverified')): ?>
    <section class="cp-section trust-section">
      <h2>专业资质、督导与核验</h2>
      <p class="hint">自述资料与平台核验分开列示。核验仅对应记录中的范围与有效期，不代表对所有自述内容作真实性保证。</p>
      <?php if ($hasText($trust['self_credentials']) || $hasText($trust['self_supervision'])): ?>
      <h3 class="trust-subhead">咨询师自述（未经独立核验）</h3>
      <div class="trust-grid">
        <?php if ($hasText($trust['self_credentials'])): ?><div><h4>资质与注册信息</h4><p><?= nl2br(e($trust['self_credentials'])) ?></p></div><?php endif; ?>
        <?php if ($hasText($trust['self_supervision'])): ?><div><h4>督导与专业支持</h4><p><?= nl2br(e($trust['self_supervision'])) ?></p></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <h3 class="trust-subhead">机构核验记录</h3>
      <div class="trust-grid">
        <?php foreach ($trust['verifications'] as $state): if ($state['status'] === 'unverified') continue; ?>
        <div class="verification-card">
          <div class="row"><h4><?= e($state['item_key'] === 'credentials' ? '资质与注册信息' : ($state['item_key'] === 'supervision' ? '督导与专业支持' : ($state['item_key'] === 'boundaries' ? '服务对象与专业边界' : '年度专业审阅'))) ?></h4><span class="tag <?= $state['verified'] ? '' : 'warn' ?>"><?= e($state['label']) ?></span></div>
          <?php if ($state['verified_summary']): ?><p><?= nl2br(e($state['verified_summary'])) ?></p><?php endif; ?>
          <p class="muted"><?= $state['reviewed_at'] ? '记录时间：' . e(date('Y-m-d', strtotime($state['reviewed_at']))) : '' ?><?= $state['expires_at'] ? ' · 有效期至：' . e($state['expires_at']) : '' ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="muted">核验依据仅供机构内部留存。如需了解核验范围，请在预约前联系机构。</p>
    </section>
    <?php endif; ?>

    <?php if (!empty($c['video_url'])):
        $vu = (string)$c['video_url'];
        $embed = '';
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
        if ($hasText($c[$field])): ?>
    <section class="cp-section">
      <h2><?= e($label) ?></h2>
      <div class="article-content"><?= eraw($c[$field]) ?></div>
    </section>
    <?php endif; endforeach; ?>

    <section class="cp-section boundary-section">
      <h2>开始前，请了解</h2>
      <div class="boundary-grid">
        <div>
          <h3>知情与隐私</h3>
          <p>预约提交前需阅读并同意《咨询知情同意书》和《隐私政策》；未成年人预约须取得监护人知情同意。</p>
          <a class="text-link" href="<?= url('/p/consent') ?>" target="_blank" rel="noopener">阅读知情同意书</a>
        </div>
        <div>
          <h3>改期与取消</h3>
          <p>已确认预约如需调整，请尽早登录会员中心操作或联系机构；具体安排以预约确认信息为准。</p>
          <a class="text-link" href="<?= url('/member/bookings') ?>">管理我的预约</a>
        </div>
        <div>
          <h3>服务边界</h3>
          <p>心理咨询不替代医疗诊断与急救服务。如存在伤害自己或他人的紧迫风险，请立即联系当地急救或报警。</p>
          <a class="text-link" href="<?= url('/p/crisis') ?>" target="_blank" rel="noopener">查看危机支持资源</a>
        </div>
      </div>
    </section>

    <section class="cp-section availability-section" id="availability">
      <div class="cp-section-head">
        <h2>未来两周可约日历</h2>
        <p>点击可约时段直接进入预约确认，灰色时段已被预约。</p>
      </div>
      <?php if (!empty($calendar)): ?>
      <?php if (!empty($calendar[0]['leave'])): ?>
        <div class="alert alert-warning"><b><?= e($c['name']) ?> 当前请假暂停接单</b>——可提交预约申请，恢复后优先安排。</div>
      <?php endif; ?>
      <div class="calendar-strip">
        <?php foreach ($calendar as $day): ?>
        <div class="cal-day">
          <b><?= e($day['md']) ?></b>
          <span class="muted"><?= e($day['week']) ?></span>
          <?php if ($day['closed_note'] !== null): ?>
            <span class="chip" title="<?= e((string)$day['closed_note']) ?>">停诊</span>
          <?php elseif ($day['leave']): ?>
            <span class="chip">请假</span>
          <?php else: ?>
            <?php foreach ($day['slots'] as $t): ?>
              <?php if ($t['available']): ?>
              <a class="chip chip-link" href="<?= url('/booking/confirm?counselor=' . (int)$c['id'] . '&date=' . $day['ymd'] . '&start=' . urlencode($t['start'])) ?>" title="点击预约"><?= e($t['label']) ?></a>
              <?php else: ?>
              <span class="chip is-taken" title="该时段已被约"><?= e($t['label']) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$day['slots']): ?><span class="muted">无可约</span><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="muted">实际时间以确认信息为准。</p>
      <?php else: ?>
      <div class="empty">未来两周暂无排班，可提交预约申请，由机构协调安排。</div>
      <?php endif; ?>
    </section>

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

    <section class="detail-cta">
      <div>
        <h2>准备好开始了吗？</h2>
        <p>可以先选择具体时段，也可以提交预约申请，由机构协助安排。</p>
      </div>
      <div class="detail-cta-actions">
        <a class="btn btn-primary btn-lg" href="<?= e($bookingUrl) ?>">提交预约申请</a>
        <a class="btn btn-ghost btn-lg" href="<?= url('/message') ?>">先问问机构</a>
      </div>
    </section>
  </div>
</section>
