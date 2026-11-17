<?php /** 咨询师工作台 · 我的预约 */ ?>
<div class="toolbar">
  <?php foreach (['' => '全部', 'pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'] as $k => $v): ?>
  <a class="btn <?= $st === $k ? 'btn-primary' : 'btn-ghost' ?>" href="/admin/counselor-home/bookings<?= $k !== '' ? '?status=' . $k : '' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>
<div class="panel" style="margin-top:12px">
  <h2>预约列表</h2>
  <?php if ($rows): ?>
  <table class="table">
    <tr><th>期望时间</th><th>咨客</th><th>套餐余额</th><th>方式</th><th>服务</th><th>主诉</th><th>状态</th></tr>
    <?php foreach ($rows as $b): ?>
    <tr>
      <td><b><?= e((string)$b['prefer_date']) ?></b> <?= e((string)$b['prefer_slot']) ?></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)$b['phone'])) ?><?= ($b['name'] ?? '') !== '' ? '（' . e((string)$b['name']) . '）' : '' ?></td>
      <td><?= isset($b['mid']) && isset($remain[(int)$b['mid']]) ? '<b>' . (int)$remain[(int)$b['mid']] . '</b> 次' : '—' ?></td>
      <td><?= e((string)$b['method'] ?: '—') ?><?= ($b['method'] ?? '') === '视频' ? ' 🎥' : '' ?></td>
      <td class="muted"><?= e((string)($b['svc'] ?: '—')) ?></td>
      <td class="muted"><?= e(str_take((string)$b['issue'], 50, '…')) ?></td>
      <td><span class="chip"><?= e(['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'][$b['status']] ?? $b['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">咨客套餐余额按手机号匹配会员咨询套餐；预约确认与改期由预约助理操作，咨询师可在「咨询小结」为每次咨询写记录。</p>
  <?php else: ?>
  <p class="muted">暂无预约。</p>
  <?php endif; ?>
</div>
