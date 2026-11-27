<?php /** 咨询师工作台 · 我的预约 */ ?>
<div class="toolbar">
  <?php foreach (['' => '全部', 'pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'] as $k => $v): ?>
  <a class="btn <?= $st === $k ? 'btn-primary' : 'btn-ghost' ?>" href="/admin/counselor-home/bookings<?= $k !== '' ? '?status=' . $k : '' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>
<div class="panel" style="margin-top:12px">
  <h2>预约列表</h2>
  <?php if ($rows): ?>
  <div class="table-wrap">
  <table class="table">
    <tr><th>期望时间</th><th>咨客</th><th>套餐余额</th><th>方式</th><th>服务</th><th>主诉</th><th>状态</th><th>疗程反馈</th><th>咨询室</th></tr>
    <?php foreach ($rows as $b): ?>
    <tr>
      <td><b><?= e((string)$b['prefer_date']) ?></b> <?= e((string)$b['prefer_slot']) ?></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)$b['phone'])) ?><?= ($b['name'] ?? '') !== '' ? '（' . e((string)$b['name']) . '）' : '' ?></td>
      <td><?= isset($b['mid']) && isset($remain[(int)$b['mid']]) ? '<b>' . (int)$remain[(int)$b['mid']] . '</b> 次' : '—' ?></td>
      <td><?= e((string)$b['method'] ?: '—') ?><?= ($b['method'] ?? '') === '视频' ? ' 🎥' : '' ?></td>
      <td class="muted"><?= e((string)($b['svc'] ?: '—')) ?></td>
      <td class="muted"><?= e(str_take((string)$b['issue'], 50, '…')) ?></td>
      <td><span class="chip"><?= e(['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'][$b['status']] ?? $b['status']) ?></span></td>
      <td><?php if (empty($b['feedback_id'])): ?>
        <span class="muted"><?= $b['status'] === 'completed' ? '未建立邀请' : '—' ?></span>
      <?php elseif ((string)$b['feedback_status'] === 'pending'): ?><span class="chip">待咨客提交</span>
      <?php else: ?>
        <b><?= $b['feedback_rating'] !== null ? (int)$b['feedback_rating'] . ' / 5' : '无评分' ?></b>
        <div class="muted"><?= e(str_take((string)($b['feedback_comment'] ?? ''), 50, '…')) ?></div>
        <div class="muted"><?= (int)$b['feedback_followup'] ? '需要随访' : '无需随访' ?> · <?= e(['waiting_case' => '待关联个案', 'created' => '已建随访', 'not_applicable' => '无需随访', 'unavailable' => '不可用'][(string)$b['feedback_bridge']] ?? (string)$b['feedback_bridge']) ?></div>
      <?php endif; ?></td>
      <td><?php
        // v2.24.2：工作台在线咨询入口（音视频预约且窗口内可进房；rtc-pro 未启用时静默）
        $rst = (class_exists(\plugins\rtcpro\Plugin::class) && \app\Core\PluginManager::isEnabled('rtc-pro'))
          ? \plugins\rtcpro\Plugin::roomWindowState($b) : 'off';
        if ($rst === 'ready'): ?>
          <a class="btn btn-sm btn-primary" href="<?= url('/plugin/rtc-pro/room/' . (int)$b['id']) ?>" target="_blank">🎥 进入咨询室</a>
        <?php elseif ($rst === 'early'):
          $openAt = strtotime((string)$b['prefer_date'] . ' ' . substr((string)$b['prefer_slot'], 0, 5)) - 10 * 60; ?>
          <span class="muted">开窗 <?= $openAt ? date('H:i', $openAt) : '' ?></span>
        <?php elseif ($rst === 'done'): ?><span class="muted">已结束</span>
        <?php else: ?><span class="muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="hint">咨客套餐余额按手机号匹配会员咨询套餐；预约确认与改期由预约助理操作，咨询师可在「咨询小结」为每次咨询写记录。</p>
  <?php else: ?>
  <p class="muted">暂无预约。</p>
  <?php endif; ?>
</div>
