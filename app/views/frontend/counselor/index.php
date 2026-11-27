<?php
/** 咨询师列表 */
$queryUrl = static function (array $changes = []) use ($filters): string {
    $query = array_merge($filters, $changes);
    $pairs = [];
    foreach ($query as $key => $value) {
        if ($value !== '' && $value !== null) $pairs[] = urlencode((string)$key) . '=' . urlencode((string)$value);
    }
    return url('/counselors' . ($pairs ? '?' . implode('&', $pairs) : ''));
};
?>
<section class="section counselor-directory">
  <div class="container">
    <header class="directory-head">
      <div>
        <p class="directory-kicker">找一位适合你的咨询师</p>
        <h1 class="page-title">咨询师团队</h1>
        <p class="page-sub">先看擅长方向，再确认服务方式、可约时间与收费。档案中的经历与资质均由机构如实填写。</p>
      </div>
      <a class="btn btn-ghost" href="<?= url('/booking?source=web') ?>">不确定选谁？让机构推荐</a>
    </header>

    <form class="directory-filters" method="get" action="<?= url('/counselors') ?>" aria-label="筛选咨询师">
      <label class="filter-field filter-wide">
        <span>擅长方向</span>
        <select name="spec">
          <option value="">全部方向</option>
          <?php foreach ($tags as $t => $n): ?>
          <option value="<?= e($t) ?>" <?= $currentSpec === $t ? 'selected' : '' ?>><?= e($t) ?>（<?= (int)$n ?>）</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="filter-field">
        <span>服务方式</span>
        <select name="mode">
          <option value="">不限</option>
          <?php foreach (\app\Services\CounselorProfile::MODES as $mode): ?>
          <option value="<?= e($mode) ?>" <?= ($filters['mode'] ?? '') === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="filter-field">
        <span>可约状态</span>
        <select name="available">
          <option value="">不限</option>
          <option value="1" <?= ($filters['available'] ?? '') === '1' ? 'selected' : '' ?>>未来两周有可约时段</option>
        </select>
      </label>
      <label class="filter-field">
        <span>单次收费不高于</span>
        <input type="number" name="max_fee" min="0" step="1" inputmode="decimal" value="<?= e((string)($filters['max_fee'] ?? '')) ?>" placeholder="不限">
      </label>
      <label class="filter-field">
        <span>排序</span>
        <select name="sort">
          <option value="recommended" <?= ($filters['sort'] ?? '') === '' ? 'selected' : '' ?>>推荐优先</option>
          <option value="price_asc" <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>收费从低到高</option>
          <option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>收费从高到低</option>
          <option value="hours_desc" <?= ($filters['sort'] ?? '') === 'hours_desc' ? 'selected' : '' ?>>累计时长优先</option>
        </select>
      </label>
      <button class="btn btn-primary filter-submit" type="submit">筛选</button>
    </form>

    <?php if ($tags): ?>
    <div class="filter-chips directory-tags" aria-label="热门擅长方向">
      <a class="chip<?= $currentSpec === '' ? ' active' : '' ?>" href="<?= e($queryUrl(['spec' => '', 'page' => ''])) ?>">全部</a>
      <?php foreach ($tags as $t => $n): ?>
      <a class="chip<?= $currentSpec === $t ? ' active' : '' ?>" href="<?= e($queryUrl(['spec' => $t, 'page' => ''])) ?>"><?= e($t) ?> (<?= (int)$n ?>)</a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="directory-result">
      <p>共 <strong><?= (int)$total ?></strong> 位咨询师<?= $pages > 1 ? '，第 ' . (int)$page . ' / ' . (int)$pages . ' 页' : '' ?></p>
      <?php if (array_filter($filters)): ?><a href="<?= url('/counselors') ?>">清除全部筛选</a><?php endif; ?>
    </div>

    <?php if (!$rows): ?>
    <div class="empty directory-empty">
      <h2>暂时没有符合条件的咨询师</h2>
      <p>可以放宽收费或可约时间条件，也可以提交预约申请，由机构协助安排。</p>
      <a class="btn btn-primary" href="<?= url('/counselors') ?>">查看全部咨询师</a>
    </div>
    <?php else: ?>
    <div class="card-grid counselor-grid">
      <?php foreach ($rows as $c):
        $cid = (int)$c['id'];
        $modes = \app\Services\CounselorProfile::modes($c['service_modes'] ?? '');
        $modeText = $modes ? implode(' / ', $modes) : '面对面 / 视频 / 电话';
        $next = $firstAvailable[$cid] ?? null;
        $feeText = \app\Services\BookingPricing::formatRange(
            isset($c['display_fee_from']) ? (float)$c['display_fee_from'] : null,
            isset($c['display_fee_to']) ? (float)$c['display_fee_to'] : null
        );
      ?>
      <a class="card counselor-card" href="<?= e(url('/counselors/' . $c['slug'])) ?>">
        <div class="card-media">
          <?php if ($c['avatar']): ?><img src="<?= e(thumb($c['avatar'])) ?>" alt="咨询师<?= e($c['name']) ?>照片" loading="lazy">
          <?php else: ?><span class="avatar-ph"><?= e(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?>
          <?php if ($c['is_featured']): ?><span class="featured-badge">推荐</span><?php endif; ?>
          <span class="availability-badge<?= !empty($c['on_leave']) ? ' is-away' : '' ?>"><?= !empty($c['on_leave']) ? '暂停接单' : ($next ? '可预约' : '待协调') ?></span>
        </div>
        <div class="card-body">
          <h3><?= e($c['name']) ?><small class="c-title"><?= e($c['title']) ?></small></h3>
          <?php if ($c['orientation']): ?><p class="c-orient"><?= e(str_take((string)$c['orientation'], 28)) ?></p><?php endif; ?>
          <p class="c-spec"><?= e(str_take((string)$c['specialties'], 56)) ?></p>
          <div class="c-facts">
            <span><?= e($modeText) ?></span>
            <?php if ($c['hours']): ?><span>累计 <?= (int)$c['hours'] ?> 小时</span><?php endif; ?>
          </div>
          <div class="c-next">
            <span class="muted"><?= !empty($c['on_leave']) ? '当前请假，可提交申请' : ($next ? '最早可约' : '未来两周暂无可约时段') ?></span>
            <?php if ($next && empty($c['on_leave'])): ?><strong><?= e(date('n月j日 H:i', strtotime($next))) ?></strong><?php endif; ?>
          </div>
          <div class="c-card-foot">
            <div class="c-price"><strong><?= e($feeText) ?></strong><span>/ <?= e($c['fee_unit'] ?: '50分钟') ?></span></div>
            <span class="c-more">查看档案 →</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="咨询师列表分页">
      <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e($queryUrl(['page' => $page - 1])) ?>">上一页</a><?php endif; ?>
      <span>第 <?= (int)$page ?> / <?= (int)$pages ?> 页</span>
      <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e($queryUrl(['page' => $page + 1])) ?>">下一页</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
    <p class="directory-note">服务方式未单独设置的咨询师沿用预约表单的全部方式；可约时间会实时扣除已预约时段，最终以预约确认信息为准。</p>
  </div>
</section>
