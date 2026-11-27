<?php /** 咨询师工作台 · 结算与课时 */ ?>
<div class="toolbar">
  <form class="toolbar" method="get" action="/admin/counselor-home/settlement" style="margin:0">
    <input type="month" name="month" value="<?= e($month) ?>" required>
    <button class="btn btn-primary" type="submit">查询</button>
  </form>
  <a class="btn btn-ghost" href="/admin/counselor-home/settlement?month=<?= e(date('Y-m')) ?>">本月</a>
</div>
<div class="dash-grid" style="margin-top:12px">
  <div class="panel"><h2>已完成服务</h2><p><b style="font-size:28px"><?= (int)$sessions ?></b> 次</p><p class="hint">按已完成预约的咨询日期统计，含线上与线下。</p></div>
  <div class="panel"><h2>线上预约实收</h2><p><b style="font-size:28px">¥<?= number_format($received_cents / 100, 2) ?></b></p><p class="hint">仅已完成且支付状态为 paid 的预约；不含退款与线下收款。</p></div>
  <div class="panel"><h2>套餐核销</h2><p><b style="font-size:28px"><?= (int)$redeemed_sessions ?></b> 次</p><p class="hint">按已完成预约关联的 use 流水统计，不把套餐销售额重复计入服务量。</p></div>
  <div class="panel"><h2>线下服务</h2><p><b style="font-size:28px"><?= (int)$offline_sessions ?></b> 次</p><p class="hint">线下金额由机构与咨询师按制度核对，本页不推定应付款。</p></div>
</div>
<div class="panel" style="margin-top:14px">
  <h2>会次明细 <span class="muted"><?= e($month) ?></span></h2>
  <?php if ($session_rows): ?>
  <div class="table-wrap"><table class="table">
    <tr><th>日期</th><th>个案</th><th>时长</th><th>方式</th><th>出勤</th><th>预约号</th></tr>
    <?php foreach ($session_rows as $s): ?>
    <tr>
      <td><b><?= e((string)($s['held_at'] ?? '—')) ?></b></td>
      <td><?= e((string)($s['case_no'] ?: '—')) ?></td>
      <td><?= isset($s['duration_min']) ? (int)$s['duration_min'] . ' 分钟' : '—' ?></td>
      <td><?= e((string)($s['method'] ?: '—')) ?></td>
      <td><?= e(\app\Services\CasePolicy::ATTENDANCE[(string)$s['attendance']] ?? (string)$s['attendance']) ?></td>
      <td><?= (int)$s['booking_id'] > 0 ? '#' . (int)$s['booking_id'] : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="muted">本月暂无会次记录。</p><?php endif; ?>
</div>
