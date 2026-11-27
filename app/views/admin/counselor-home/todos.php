<?php /** 咨询师工作台 · 待办 */ ?>
<div class="toolbar">
  <a class="btn btn-ghost" href="/admin/counselor-home/calendar">查看今日日程</a>
  <a class="btn btn-ghost" href="/admin/counselor-home/cases">个案管理</a>
  <form method="post" action="/admin/counselor-home/todos">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="read-all">
    <button class="btn btn-ghost" type="submit">全部通知标为已读</button>
  </form>
</div>
<div class="panel" style="margin-top:12px">
  <h2>待处理 <span class="muted"><?= count($rows) >= 100 ? '100+' : count($rows) ?> 项</span></h2>
  <?php if ($rows): ?>
  <div class="table-wrap">
  <table class="table">
    <tr><th>优先级</th><th>类型</th><th>事项</th><th>时间</th><th>个案</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['priority'] === 0 ? '<span class="chip err-text">紧急</span>' : ((int)$r['priority'] === 1 ? '<span class="chip err-text">需处理</span>' : '<span class="muted">常规</span>') ?></td>
      <td><?= e(['crisis' => '危机闭环', 'followup' => '随访', 'homework' => '作业', 'feedback' => '疗程反馈', 'booking' => '预约', 'notification' => '通知'][(string)$r['item_type']] ?? (string)$r['item_type']) ?></td>
      <td><b><?= e((string)$r['title']) ?></b></td>
      <td class="muted"><?= e(substr((string)$r['due_at'], 0, 16)) ?></td>
      <td><?= e((string)$r['case_no'] ?: '—') ?></td>
      <td><a class="btn btn-sm" href="<?= e((string)$r['url']) ?>">去处理</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="hint">待办由预约、危机、随访、作业、反馈和未读通知实时汇总，不另存一份状态。</p>
  <?php else: ?>
  <p class="muted">当前没有待处理事项。</p>
  <?php endif; ?>
</div>
<div class="panel" style="margin-top:14px">
  <h2>站内通知</h2>
  <?php if ($notifications): ?>
  <div class="table-wrap"><table class="table">
    <tr><th>状态</th><th>通知</th><th>时间</th><th></th></tr>
    <?php foreach ($notifications as $n): ?>
    <tr>
      <td><?= (int)$n['is_read'] === 0 ? '<span class="chip">未读</span>' : '<span class="muted">已读</span>' ?></td>
      <td><b><?= e((string)$n['title']) ?></b><div class="muted"><?= e((string)$n['body']) ?></div></td>
      <td class="muted"><?= e((string)$n['created_at']) ?></td>
      <td>
        <?php if ((int)$n['is_read'] === 0): ?>
        <form method="post" action="/admin/counselor-home/todos">
          <?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <button class="btn btn-sm btn-ghost" type="submit">标为已读</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="muted">暂无站内通知。</p><?php endif; ?>
</div>
