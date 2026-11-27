<?php /** 咨询师工作台 · 日程 */ ?>
<?php
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
$week = ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', strtotime($date))];
?>
<div class="toolbar">
  <a class="btn btn-ghost" href="/admin/counselor-home/calendar?date=<?= e($prev) ?>">前一天</a>
  <form class="toolbar" method="get" action="/admin/counselor-home/calendar" style="margin:0">
    <input type="date" name="date" value="<?= e($date) ?>" required>
    <button class="btn btn-primary" type="submit">查看</button>
  </form>
  <a class="btn btn-ghost" href="/admin/counselor-home/calendar?date=<?= e($next) ?>">后一天</a>
  <a class="btn btn-ghost" href="/admin/counselor-home/schedule">维护排班与价格</a>
</div>
<div class="panel" style="margin-top:12px">
  <h2>周<?= e($week) ?> · <?= e($date) ?></h2>
  <?php if ($slots['leave']): ?>
    <p class="err-text">当前处于请假状态，前台暂停接单。</p>
  <?php elseif ($slots['closed_note'] !== null): ?>
    <p class="muted"><?= e((string)$slots['closed_note']) ?></p>
  <?php elseif (!$slots['slots']): ?>
    <p class="muted">当天未设置可约时段。</p>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <tr><th style="width:130px">时段</th><th>预约</th><th>状态</th><th></th></tr>
      <?php foreach ($slots['slots'] as $s): ?>
      <?php $items = $bySlot[$s['start']] ?? []; ?>
      <tr>
        <td><b><?= e(BookingSlots::label($s['start'], $s['end'])) ?></b></td>
        <td>
          <?php if (!$items): ?><span class="muted">—</span><?php endif; ?>
          <?php foreach ($items as $b): ?>
          <div><?= e(\app\Services\MemberService::maskPhone((string)$b['phone'])) ?><?= ($b['name'] ?? '') !== '' ? '（' . e((string)$b['name']) . '）' : '' ?></div>
          <div class="muted"><?= e((string)($b['svc'] ?: '—')) ?> · <?= e((string)($b['method'] ?: '—')) ?></div>
          <?php endforeach; ?>
        </td>
        <td>
          <?php foreach ($items as $b): ?>
          <span class="chip"><?= e(['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期'][(string)$b['status']] ?? (string)$b['status']) ?></span>
          <?php endforeach; ?>
          <?php if (!$items): ?><span class="ok">可预约</span><?php endif; ?>
        </td>
        <td><?php if ($items): ?><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/bookings">预约详情</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
</div>
<div class="panel" style="margin-top:14px">
  <h2>当日预约</h2>
  <?php if ($bookings): ?>
  <div class="table-wrap"><table class="table">
    <tr><th>时间</th><th>咨客</th><th>方式</th><th>服务</th><th>状态</th></tr>
    <?php foreach ($bookings as $b): ?>
    <tr>
      <td><b><?= e(substr((string)$b['prefer_slot'], 0, 5)) ?></b></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)$b['phone'])) ?></td>
      <td><?= e((string)($b['method'] ?: '—')) ?></td>
      <td><?= e((string)($b['svc'] ?: '—')) ?></td>
      <td><?= e(['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期'][(string)$b['status']] ?? (string)$b['status']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="muted">当日暂无预约。</p><?php endif; ?>
</div>
