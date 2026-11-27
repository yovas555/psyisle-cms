<?php /** 咨询师工作台 · 疗程反馈 */ ?>
<div class="toolbar">
  <form class="toolbar" method="get" action="/admin/counselor-home/feedbacks" style="margin:0">
    <input type="month" name="month" value="<?= e($month) ?>" required>
    <button class="btn btn-primary" type="submit">查询汇总</button>
  </form>
  <a class="btn btn-ghost" href="/admin/counselor-home/cases">个案管理</a>
</div>
<div class="dash-grid" style="margin-top:12px">
  <div class="panel"><h2>已提交反馈</h2><p><b style="font-size:28px"><?= (int)$summary['submitted'] ?></b> 份</p><p class="hint"><?= e($month) ?></p></div>
  <div class="panel"><h2>平均评分</h2><p><b style="font-size:28px"><?= $summary['average_rating'] !== null ? e((string)$summary['average_rating']) : '—' ?></b></p><p class="hint">满分 5 分；仅统计已提交反馈。</p></div>
  <div class="panel"><h2>随访请求</h2><p><b style="font-size:28px"><?= (int)$summary['followup_requests'] ?></b> 次</p><p class="hint">已桥接 <?= (int)$summary['bridged'] ?>，待关联个案 <?= (int)$summary['waiting_case'] ?>。</p></div>
</div>
<div class="panel" style="margin-top:14px">
  <h2>反馈列表</h2>
  <?php if ($rows): ?>
  <div class="table-wrap"><table class="table">
    <tr><th>预约</th><th>咨客</th><th>评分</th><th>反馈内容</th><th>随访</th><th>桥接状态</th><th></th></tr>
    <?php foreach ($rows as $f): ?>
    <tr>
      <td><b><?= e((string)$f['prefer_date']) ?></b><div class="muted"><?= e((string)$f['prefer_slot']) ?></div></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)$f['phone'])) ?><?= ($f['name'] ?? '') !== '' ? '（' . e((string)$f['name']) . '）' : '' ?></td>
      <td><?= $f['rating'] !== null ? '<b>' . (int)$f['rating'] . '</b> / 5' : '—' ?></td>
      <td><?= $f['comment'] !== null && $f['comment'] !== '' ? e(str_take((string)$f['comment'], 120, '…')) : '<span class="muted">未填写</span>' ?></td>
      <td><?= (int)$f['wants_followup'] === 1 ? '需要' : '不需要' ?></td>
      <td>
        <?php if ((string)$f['status'] === 'pending'): ?><span class="chip">待提交</span>
        <?php else: ?>
          <?= e(['waiting_case' => '待关联个案', 'created' => '已建随访', 'not_applicable' => '无需随访', 'unavailable' => '不可用'][(string)$f['bridge_status']] ?? (string)$f['bridge_status']) ?>
        <?php endif; ?>
      </td>
      <td>
        <?php if ((string)$f['bridge_status'] === 'waiting_case'): ?>
        <form method="post" action="/admin/counselor-home/feedbacks" onsubmit="return confirm('确认关联此个案并创建随访吗？')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <select name="case_id" required>
            <option value="">选择同会员同咨询师的进行中个案</option>
            <?php foreach ($waitingMembers[(int)$f['member_id']] ?? [] as $case): ?>
            <option value="<?= (int)$case['id'] ?>"><?= e((string)$case['case_no'] . ' · ' . (string)$case['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-primary" type="submit">关联并随访</button>
        </form>
        <?php elseif ((int)$f['case_id'] > 0): ?>
        <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$f['case_id'] ?>/care">查看随访</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="muted">暂无咨询后反馈。</p><?php endif; ?>
</div>
