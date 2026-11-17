<?php /** 审计日志 */ ?>
<?php $chain = $chain ?? ['unknown', 0]; ?>
<?php if ($chain[0] === 'ok'): ?>
<div class="alert alert-success">🔒 审计链完整性校验通过（最近 500 条记录哈希连续，未被篡改）</div>
<?php elseif ($chain[0] === 'broken'): ?>
<div class="alert alert-error">⚠ 审计链断裂于记录 #<?= (int)$chain[1] ?>（疑似记录被删除）</div>
<?php elseif ($chain[0] === 'tampered'): ?>
<div class="alert alert-error">⚠ 记录 #<?= (int)$chain[1] ?> 内容与哈希不符（疑似被篡改）</div>
<?php elseif ($chain[0] === 'empty'): ?>
<div class="alert alert-warning">暂无带哈希的审计记录（升级前数据不计入校验）</div>
<?php endif; ?>

<div class="toolbar">
  <form class="search-inline" method="get">
    <input type="search" name="q" value="<?= e($kw) ?>" placeholder="用户名 / 动作">
    <button class="btn btn-ghost">搜索</button>
  </form>
  <span class="muted">共 <?= (int)$total ?> 条（保留 180 天）</span>
</div>
<table class="table table-hover">
  <thead><tr><th>#</th><th>用户</th><th>动作</th><th>对象</th><th>详情</th><th>IP</th><th>时间</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无记录</td></tr><?php endif; ?>
  <?php foreach ($rows as $a): ?>
  <tr>
    <td><?= (int)$a['id'] ?></td>
    <td><?= e($a['username'] ?: ('#' . $a['user_id'])) ?></td>
    <td><code><?= e($a['action']) ?></code></td>
    <td><?= e($a['target_type'] ?: '—') ?><?= $a['target_id'] ? '#' . e($a['target_id']) : '' ?></td>
    <td class="muted"><?= e(str_take((string)$a['detail'], 50, '…')) ?></td>
    <td class="muted"><?= e($a['ip']) ?></td>
    <td class="muted"><?= e($a['created_at']) ?></td>
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
