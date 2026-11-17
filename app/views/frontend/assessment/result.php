<?php /** 量表结果页 */ ?>
<section class="section">
  <div class="container narrow">
    <div class="card success-card result-card">
      <p class="muted"><?= e($a['name']) ?> · 结果</p>
      <h1 class="score-num"><?= (int)$score ?><small> 分</small></h1>
      <h2 class="score-level"><?= e($level) ?></h2>
      <div class="score-advice"><?= nl2br(e($advice)) ?></div>
      <div class="cp-actions" style="justify-content:center">
        <?php if (mb_stripos($level, '重度') !== false || mb_stripos($level, '中重') !== false): ?>
        <a class="btn btn-primary" href="<?= url('/booking?source=assessment') ?>">尽快预约咨询</a>
        <a class="btn btn-danger-line" href="<?= url('/p/crisis') ?>">危机资源</a>
        <?php else: ?>
        <a class="btn btn-primary" href="<?= url('/booking?source=assessment') ?>">预约一次咨询</a>
        <a class="btn btn-ghost" href="<?= url('/message') ?>">先留言聊聊</a>
        <?php endif; ?>
      </div>
      <?php if (!empty($reportToken)): ?>
      <p style="margin-top:16px"><a class="btn btn-ghost" href="<?= url('/report/' . $reportToken) ?>">查看完整报告（可保存/打印）</a></p>
      <p class="muted" style="margin-top:6px">报告链接可直接保存或发给信任的人；链接不含任何个人信息。</p>
      <?php endif; ?>
      <p class="muted" style="margin-top:20px"><?= e($disclaimer) ?></p>
      <p class="muted">重测 <a href="<?= url('/assessment/' . $a['slug']) ?>">再测一次</a> · <a href="<?= url('/assessment') ?>">其他量表</a></p>
    </div>
  </div>
</section>
