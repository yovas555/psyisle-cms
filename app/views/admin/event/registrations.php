<?php /** 活动报名名单 */ ?>
<div class="toolbar">
  <a class="btn btn-ghost" href="/admin/events">← 返回活动列表</a>
  <a class="btn btn-ghost" href="/admin/events/<?= (int)$e['id'] ?>/regs-export" download>导出 CSV</a>
  <span class="muted">共 <?= count($regs) ?> 条报名</span>
</div>
<table class="table table-hover">
  <thead><tr><th>#</th><th>姓名</th><th>手机</th><th>人数</th><th>备注</th><th>状态</th><th>报名时间</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (!$regs): ?><tr><td colspan="8" class="empty">暂无报名</td></tr><?php endif; ?>
  <?php foreach ($regs as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= e($r['name']) ?></td>
    <td><?= e($r['phone']) ?></td>
    <td><?= (int)$r['people_count'] ?></td>
    <td class="muted"><?= e(str_take((string)$r['note'], 20, '…')) ?></td>
    <td><?= ['pending' => '<b class="draft">待确认</b>', 'confirmed' => '<b class="ok">已确认</b>', 'cancelled' => '已取消'][$r['status']] ?? e($r['status']) ?></td>
    <td class="muted"><?= e($r['created_at']) ?></td>
    <td class="ops">
      <form method="post" action="/admin/events/reg-status/<?= (int)$r['id'] ?>" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="status" value="<?= $r['status'] === 'confirmed' ? 'cancelled' : 'confirmed' ?>">
        <button class="btn btn-sm btn-ghost" type="submit"><?= $r['status'] === 'confirmed' ? '取消确认' : '确认' ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
