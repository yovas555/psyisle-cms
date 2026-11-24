<?php /** 会员中心 · 我的测评 */ ?>
<div class="container member-wrap">
  <div class="member-head">
    <h1>我的测评</h1>
    <p class="muted"><a href="<?= url('/member') ?>">返回会员中心</a> · <a href="<?= url('/assessment') ?>">去自测 ›</a> · 共 <?= (int)$total ?> 条记录</p>
  </div>

  <?php if (!$grouped): ?>
  <p class="muted">还没有测评记录。<a href="<?= url('/assessment') ?>">去自测</a> —— 登录状态下完成的测评会自动存档在这里。</p>
  <?php else: ?>
  <p class="hint">量表仅用于自测参考，不构成诊断。分数持续升高或影响生活时，建议预约专业咨询。</p>
  <?php foreach ($grouped as $name => $rows): ?>
  <h2 class="section-title"><?= e((string)$name) ?></h2>
  <table class="table member-table">
    <tr><th>时间</th><th>分数</th><th>分级</th><th>趋势</th></tr>
    <?php $prev = null; foreach ($rows as $r): ?>
    <tr>
      <td class="muted"><?= e((string)$r['created_at']) ?></td>
      <td><b><?= (int)$r['score'] ?></b></td>
      <td><span class="chip"><?= e((string)$r['level']) ?></span></td>
      <td>
        <?php if ($prev === null): ?><span class="muted">首次</span>
        <?php else: $d = (int)$r['score'] - $prev; ?>
        <span class="<?= $d > 0 ? 'err-text' : ($d < 0 ? 'ok-text' : 'muted') ?>"><?= $d > 0 ? '↑' : ($d < 0 ? '↓' : '—') ?> <?= abs($d) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['report_token'])): ?> · <a href="<?= url('/report/' . $r['report_token']) ?>" target="_blank">报告</a><?php endif; ?>
      </td>
    </tr>
    <?php $prev = (int)$r['score']; endforeach; ?>
  </table>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
