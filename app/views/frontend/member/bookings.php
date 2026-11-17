<?php /** 会员中心 · 我的预约 */ ?>
<div class="container member-wrap">
  <?php foreach ($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
  <div class="member-head">
    <h1>我的预约</h1>
    <p class="muted"><a href="<?= url('/member') ?>">返回会员中心</a></p>
  </div>

  <?php if (!$list): ?>
  <p class="muted">还没有预约记录。<a href="<?= url('/booking') ?>">立即预约</a></p>
  <?php else: ?>
  <table class="table member-table">
    <thead><tr><th>查询码</th><th>日期 / 时段</th><th>状态</th><th>机构备注</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($list as $b): $canAct = in_array($b['status'], ['pending', 'confirmed'], true); ?>
    <tr>
      <td><code><?= e($b['code']) ?></code></td>
      <td><?= e((string)$b['prefer_date']) ?> <?= e((string)$b['prefer_slot']) ?></td>
      <td><span class="chip"><?= e($statusMap[$b['status']] ?? $b['status']) ?></span>
        <?php if (stripos((string)$b['method'], '视频') !== false): ?>
          <?php $ps = (string)($b['pay_status'] ?? 'free'); ?>
          <?php if ($ps === 'paid'): ?><span class="chip ok-text">已付费</span>
          <?php elseif ($ps === 'free'): ?><span class="chip">免费</span>
          <?php else: ?><span class="chip draft">待付费</span><?php endif; ?>
        <?php endif; ?>
      </td>
      <td class="muted"><?= nl2br(e(str_take((string)$b['admin_note'], 60, '…'))) ?></td>
      <td>
        <?php if (!empty($b['room_url'])): ?>
        <a class="btn btn-primary btn-sm" href="<?= e($b['room_url']) ?>">▶ 进入咨询室</a>
        <?php elseif (($b['room_state'] ?? '') === 'early'): ?>
        <span class="muted">咨询室 <?= e($b['room_open_at'] ?? '') ?> 开放</span>
        <?php elseif (($b['room_state'] ?? '') === 'unpaid' && $canAct && (int)($b['amount_cents'] ?? 0) > 0): ?>
        <a class="btn btn-ghost btn-sm" href="<?= url('/plugin/wechat-pay/pay-booking/' . (int)$b['id']) ?>">去支付咨询费</a>
        <?php endif; ?>
        <?php if ($canAct): ?>
        <details class="member-ops">
          <summary>取消 / 改期</summary>
          <form method="post" action="<?= url('/member/bookings/cancel/' . (int)$b['id']) ?>" data-confirm="确认取消该预约？" style="margin:6px 0">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">取消预约</button>
          </form>
          <form method="post" action="<?= url('/member/bookings/reschedule/' . (int)$b['id']) ?>" style="margin:6px 0">
            <?= csrf_field() ?>
            <input type="date" name="new_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
            <input type="text" name="new_slot" placeholder="期望时段（如 上午）" style="width:130px">
            <button class="btn btn-ghost btn-sm" type="submit">申请改期</button>
          </form>
        </details>
        <?php else: ?><span class="muted">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">距咨询不足 24 小时无法在线取消/改期，请直接联系机构。改期申请提交后，机构确认后会回电通知。</p>
  <?php endif; ?>
</div>
