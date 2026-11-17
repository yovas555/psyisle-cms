<?php /** 咨询师工作台 · 总览 */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2>今日与待办</h2>
    <div class="toolbar">
      <span class="chip">今日咨询 <b><?= (int)$today ?></b></span>
      <span class="chip<?= $pending ? ' err-text' : '' ?>">待确认预约 <?= (int)$pending ?></span>
      <span class="chip">累计小结/作业 <?= (int)$notesCount ?></span>
    </div>
    <p class="hint">工作台数据仅限本人：预约、排班、咨客套餐余额与咨询记录。</p>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>近期预约（已确认/已改期）</h2>
  <?php if ($upcoming): ?>
  <table class="table">
    <tr><th>日期</th><th>时段</th><th>咨客</th><th>方式</th><th>服务</th><th>主诉</th></tr>
    <?php foreach ($upcoming as $b): ?>
    <tr>
      <td><b><?= e((string)$b['prefer_date']) ?></b> <?= e((string)$b['prefer_slot']) ?></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)$b['phone'])) ?></td>
      <td><?= e((string)$b['method'] ?: '—') ?></td>
      <td class="muted"><?= e((string)($b['svc'] ?: '—')) ?></td>
      <td class="muted"><?= e(str_take((string)$b['issue'], 60, '…')) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无近期预约。</p>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:14px">
  <h2>咨客套餐余额（近 30 天有预约的咨客）</h2>
  <?php if ($clients): ?>
  <table class="table">
    <tr><th>咨客</th><th>剩余咨询次数</th></tr>
    <?php foreach ($clients as $c): ?>
    <tr>
      <td><?= e(\app\Services\MemberService::maskPhone((string)$c['phone'])) ?><?= ($c['name'] ?? '') !== '' ? '（' . e((string)$c['name']) . '）' : '' ?></td>
      <td><b style="color:var(--primary,#0d9488)"><?= (int)$c['remain'] ?></b> 次</td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无数据。</p>
  <?php endif; ?>
</div>
