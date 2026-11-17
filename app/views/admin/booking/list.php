<?php /** 预约列表 */ $statusText = ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消']; ?>
<div class="page-guide" data-guide-key="bookings">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>预约管理怎么用</b>
  <ol><li>新预约：电话确认时间与匹配咨询师 → 状态改「已确认」→ 结束改「已完成」</li><li>「机构备注」会展示在会员的进度查询页（凭查询码可见），措辞得体</li><li>「来源」列显示客户渠道；「急」标记优先电话联系</li><li>右上角可导出 CSV 备份</li></ol>
</div>
<div class="toolbar">
  <div class="filter-chips">
    <a class="chip<?= $st === '' ? ' active' : '' ?>" href="/admin/bookings">全部</a>
    <?php foreach ($statusText as $k => $v): ?>
    <a class="chip<?= $st === $k ? ' active' : '' ?>" href="/admin/bookings?status=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <form class="search-inline" method="get">
    <input type="search" name="q" value="<?= e($kw) ?>" placeholder="姓名 / 手机 / 查询码">
    <button class="btn btn-ghost">搜索</button>
  </form>
  <span class="muted">共 <?= (int)$total ?> 条</span>
  <a class="btn btn-ghost" href="/admin/bookings/export" download>导出 CSV</a>
</div>

<table class="table table-hover">
  <thead><tr><th>#</th><th>姓名</th><th>手机</th><th>服务/咨询师</th><th>期望时间</th><th>方式</th><th>来源</th><th>状态</th><th>提交时间</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无预约</td></tr><?php endif; ?>
  <?php foreach ($rows as $b): ?>
  <tr>
    <td><?= (int)$b['id'] ?></td>
    <td><a href="/admin/bookings/<?= (int)$b['id'] ?>/edit"><?= e($b['name']) ?></a><?= $b['urgency'] ? ' <b class="badge warn">急</b>' : '' ?></td>
    <td><?= e(mask_phone($b['phone'])) ?></td>
    <td><?= e($b['svc'] ?: '—') ?><?= $b['cname'] ? ' / ' . e($b['cname']) : '' ?></td>
    <td><?= e(($b['prefer_date'] ?: '不限') . ' ' . str_take((string)$b['prefer_slot'], 10, '')) ?></td>
    <td><?= e($b['method'] ?: '—') ?></td>
    <td><?= e(['assessment' => '量表转化', 'chat' => 'AI 客服', 'counselor' => '咨询师页', 'web' => '官网'][($b['source'] ?? 'web')] ?? $b['source']) ?></td>
    <td>
      <form method="post" action="/admin/bookings/status/<?= (int)$b['id'] ?>" class="inline-status" onchange-target="1">
        <?= csrf_field() ?>
        <input type="hidden" name="admin_note" value="<?= e((string)$b['admin_note']) ?>">
        <select name="status" onchange="this.form.submit()" title="下拉快捷修改状态">
          <?php foreach ($statusText as $k => $v): ?>
          <option value="<?= $k ?>" <?= $b['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </td>
    <td class="muted"><?= e(dt((string)$b['created_at'])) ?></td>
    <td class="ops"><a class="btn btn-sm btn-ghost" href="/admin/bookings/<?= (int)$b['id'] ?>/edit">处理</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
<nav class="pagination">
  <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
    <?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
</nav>
<?php endif; ?>
