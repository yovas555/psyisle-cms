<?php /** 咨询师工作台 · 总览 */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2>今日与待办</h2>
    <div class="toolbar">
      <span class="chip">今日咨询 <b><?= (int)$today ?></b></span>
      <span class="chip<?= $pending ? ' err-text' : '' ?>">待确认预约 <?= (int)$pending ?></span>
      <span class="chip">累计小结/作业 <?= (int)$notesCount ?></span>
      <span class="chip">进行中个案 <b><?= (int)($caseStats['active'] ?? 0) ?></b></span>
      <?php if (!empty($caseStats['high_risk'])): ?><span class="chip err-text">高风险个案 <?= (int)$caseStats['high_risk'] ?></span><?php endif; ?>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases">进入个案管理 →</a>
    </div>
    <p class="hint">工作台数据仅限本人：预约、排班、咨客套餐余额与咨询记录。</p>
  </div>
</div>

<?php $care = $care ?? ['open_crises' => [], 'due_followups' => [], 'overdue_homeworks' => 0]; ?>
<?php if (!empty($care['open_crises']) || !empty($care['due_followups']) || (int)$care['overdue_homeworks'] > 0): ?>
<div class="panel" style="margin-top:14px;border-left:3px solid #dc2626">
  <h2>需要处理（未闭环项）</h2>
  <?php if (!empty($care['open_crises'])): ?>
  <table class="table">
    <tr><th style="width:90px">危机</th><th style="width:120px">个案</th><th>事件与表现</th><th style="width:90px">发现</th><th style="width:80px"></th></tr>
    <?php foreach ($care['open_crises'] as $c): ?>
    <tr>
      <td class="err-text"><b><?= e(\app\Services\CaseCareService::CRISIS_LEVEL[(string)$c['level']] ?? (string)$c['level']) ?></b></td>
      <td><b><?= e((string)$c['case_no']) ?></b><div class="muted" style="font-size:12px"><?= e(str_take((string)$c['case_title'], 16, '…')) ?></div></td>
      <td><?= e(str_take((string)$c['summary'], 80, '…')) ?></td>
      <td class="muted"><?= e(substr((string)($c['occurred_at'] ?? ''), 0, 10)) ?></td>
      <td><a class="btn btn-sm" href="/admin/counselor-home/cases/<?= (int)$c['case_id'] ?>/care">去闭环 →</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($care['due_followups'])): ?>
  <table class="table" style="margin-top:10px">
    <tr><th style="width:110px">随访到期</th><th style="width:120px">个案</th><th>目的</th><th style="width:80px"></th></tr>
    <?php foreach ($care['due_followups'] as $f): ?>
    <tr>
      <td><b><?= e((string)$f['due_at']) ?></b></td>
      <td><?= e((string)$f['case_no']) ?></td>
      <td class="muted"><?= e(str_take((string)($f['purpose'] ?? ''), 60, '…') ?: '—') ?></td>
      <td><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$f['case_id'] ?>/care">去随访 →</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if ((int)$care['overdue_homeworks'] > 0): ?>
  <p class="hint err-text" style="margin-top:10px">另有 <b><?= (int)$care['overdue_homeworks'] ?></b> 项作业已过截止日仍未标记完成，可在个案「照护」页处理。</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $wxBound = (bool)\app\Core\Database::value('SELECT id FROM counselor_wx WHERE counselor_id=? AND openid IS NOT NULL AND openid<>""', [(int)$me['id']]); ?>
<div class="panel" style="margin-top:14px">
  <h2>微信提醒绑定</h2>
  <div class="toolbar">
    <?php if ($wxBound): ?>
    <span class="chip"><b class="ok">✓</b> 服务号已绑定，预约提醒将推送到微信</span>
    <?php else: ?>
    <a class="btn btn-primary" href="<?= url('/admin/counselor-home/bind-wx') ?>">绑定微信服务号（接收预约提醒）</a>
    <span class="muted">绑定后：新预约/取消/改期即时微信推送；手机号短信提醒由机构设置控制</span>
    <?php endif; ?>
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
