<?php /** 留言列表 */ ?>
<div class="page-guide" data-guide-key="messages">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>留言管理怎么用</b>
  <ol><li>回复默认对用户可见（凭查询码查看）；取消勾选则为内部备注</li><li>处理完点「关闭留言」；确认不留档再删除</li><li>右上角可导出 CSV</li></ol>
</div>
<div class="toolbar">
  <div class="filter-chips">
    <a class="chip<?= $st === '' ? ' active' : '' ?>" href="/admin/messages">全部</a>
    <a class="chip<?= $st === 'pending' ? ' active' : '' ?>" href="/admin/messages?status=pending">待回复</a>
    <a class="chip<?= $st === 'replied' ? ' active' : '' ?>" href="/admin/messages?status=replied">已回复</a>
    <a class="chip<?= $st === 'closed' ? ' active' : '' ?>" href="/admin/messages?status=closed">已关闭</a>
  </div>
  <form class="search-inline" method="get">
    <input type="search" name="q" value="<?= e($kw) ?>" placeholder="姓名 / 联系方式 / 查询码">
    <button class="btn btn-ghost">搜索</button>
  </form>
  <span class="muted">共 <?= (int)$total ?> 条</span>
  <a class="btn btn-ghost" href="/admin/messages/export" download>导出 CSV</a>
</div>

<table class="table table-hover">
  <thead><tr><th>#</th><th>联系人</th><th>主题</th><th>留言摘要</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无留言</td></tr><?php endif; ?>
  <?php foreach ($rows as $m): ?>
  <tr>
    <td><?= (int)$m['id'] ?></td>
    <td><a href="/admin/messages/<?= (int)$m['id'] ?>/edit"><?= e($m['name'] ?: '匿名') ?><br><span class="muted"><?= e(mask_phone($m['contact'])) ?></span></a><?= $m['has_unread_reply'] ? ' <b class="badge">用户未读</b>' : '' ?></td>
    <td><?= e(str_take((string)$m['subject'], 16, '')) ?></td>
    <td><?= e(str_take((string)$m['content'], 30, '…')) ?></td>
    <td><?= e($m['status'] === 'pending' ? '<b class="draft">待回复</b>' : ($m['status'] === 'replied' ? '<b class="ok">已回复</b>' : '已关闭')) ?></td>
    <td class="muted"><?= e(dt((string)$m['created_at'])) ?></td>
    <td class="ops"><a class="btn btn-sm btn-ghost" href="/admin/messages/<?= (int)$m['id'] ?>/edit">查看/回复</a></td>
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
