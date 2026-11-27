<?php
/** 通用 CRUD 列表
 * @var array $columns  name => [label, type]
 * @var array $rows
 * @var ?array $seg_guides  baseSeg => [key, title, steps]
 */
$seg_guides = $seg_guides ?? [];
$render = function ($row, $col, $type) {
    $val = $row[$col] ?? null; // 列表查询未必为每个声明列都取数（如活动 reg 统计），缺键按空值渲染
    switch ($type) {
        case 'publish_status':
            return $val === 'published' ? '<b class="ok">已发布</b>'
                : ($val === 'scheduled' ? '<b style="color:#7c3aed">定时</b>'
                : '<b class="draft">草稿</b>');
        case 'datetime': return e($val ? dt((string)$val) : '—');
        case 'number':   return (string)(int)$val;
        case 'yesno':    return ((int)$val) ? '<b class="ok">是</b>' : '<span class="muted">否</span>';
        case 'image_cell':
            return $val ? '<img src="' . e((string)$val) . '" class="thumb" alt="">' : '—';
        case 'category':
            $name = '';
            if ($val) {
                $name = (string)app\Core\Database::value('SELECT name FROM categories WHERE id=?', [$val]);
            }
            return e($name ?: '—');
        case 'role':
            $name = (string)app\Core\Database::value('SELECT name FROM roles WHERE id=?', [$val]);
            return e($name ?: ('#' . $val));
        default: return e(str_take((string)$val, 40, '…'));
    }
};
$editBase = '/admin/' . $baseSeg;
$segGuide = $seg_guides[$baseSeg] ?? null;
?>
<?php if ($segGuide): ?>
<div class="page-guide" data-guide-key="<?= e($segGuide[0]) ?>">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b><?= e($segGuide[1]) ?></b>
  <ol><?php foreach ($segGuide[2] as $g): ?><li><?= e($g) ?></li><?php endforeach; ?></ol>
</div>
<?php endif; ?>
<div class="toolbar">
  <a class="btn btn-primary" href="<?= $editBase ?>/create">＋ 新增</a>
  <?php if ($baseSeg === 'counselors'): ?><a class="btn btn-ghost" href="/admin/counselor-profile-reviews">资质审核队列</a><?php endif; ?>
  <?php if (in_array($baseSeg, ['articles', 'pages'], true)): ?>
  <a class="btn btn-ghost" href="<?= $editBase ?>/trash">回收站</a>
  <?php endif; ?>
  <form class="search-inline" method="get">
    <input type="search" name="q" value="<?= e($kw) ?>" placeholder="搜索…">
    <button class="btn btn-ghost">搜索</button>
  </form>
  <span class="muted">共 <?= (int)$total ?> 条</span>
</div>

<table class="table table-hover">
  <thead><tr>
    <?php foreach ($columns as $col => [$label, $type]): ?><th><?= e($label) ?></th><?php endforeach; ?>
    <th>操作</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 1 ?>" class="empty">暂无数据</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <?php foreach ($columns as $col => [$label, $type]): ?>
    <td><?= $render($r, $col, $type) ?></td>
    <?php endforeach; ?>
    <td class="ops">
      <?php if ($baseSeg === 'articles' && ($r['status'] ?? '') === 'published'): ?>
      <a class="btn btn-sm btn-ghost" href="<?= url('/news/' . $r['slug']) ?>" target="_blank">查看</a>
      <?php elseif ($baseSeg === 'pages' && ($r['status'] ?? '') === 'published'): ?>
      <a class="btn btn-sm btn-ghost" href="<?= url('/p/' . $r['slug']) ?>" target="_blank">查看</a>
      <?php elseif ($baseSeg === 'events' && in_array($r['status'] ?? '', ['published', 'full'], true)): ?>
      <a class="btn btn-sm btn-ghost" href="<?= url('/events/' . $r['slug']) ?>" target="_blank">查看</a>
      <?php elseif (in_array($baseSeg, ['counselors', 'services'], true) && !empty($r['status'])): ?>
      <a class="btn btn-sm btn-ghost" href="<?= url('/' . ($baseSeg === 'counselors' ? 'counselors' : 'services') . '/' . $r['slug']) ?>" target="_blank">查看</a>
      <?php endif; ?>
      <?php if ($baseSeg === 'navs' && !empty($r['page_id'])): ?>
      <a class="btn btn-sm btn-ghost" href="/admin/pages/<?= (int)$r['page_id'] ?>/edit" title="编辑该导航指向的单页内容">编辑内容</a>
      <?php endif; ?>
      <a class="btn btn-sm btn-ghost" href="<?= $editBase ?>/<?= $r['id'] ?>/edit">编辑</a>
      <?php if ($baseSeg === 'counselors'): ?>
      <a class="btn btn-sm btn-ghost" href="/admin/counselors/<?= (int)$r['id'] ?>/verification">信任核验</a>
      <?php endif; ?>
      <?php if (array_key_exists('status', $r) || array_key_exists('is_featured', $r)):
        $on = is_string($r['status'] ?? 1)
            ? (($r['status'] ?? '') === 'published' || (string)($r['status'] ?? '0') === '1')
            : (int)($r['status'] ?? 0) > 0; ?>
      <form method="post" action="<?= $editBase ?>/toggle/<?= $r['id'] ?>" style="display:inline"><?= csrf_field() ?>
        <button class="btn-sm btn-text" type="submit"><?= $on ? '下线' : '启用' ?></button>
      </form>
      <?php endif; ?>
      <form method="post" action="<?= $editBase ?>/delete/<?= $r['id'] ?>" style="display:inline" data-confirm="确认删除？此操作不可恢复"><?= csrf_field() ?>
        <button class="btn-sm btn-text danger" type="submit">删除</button>
      </form>
    </td>
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
