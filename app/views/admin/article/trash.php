<?php /** 回收站（文章/单页共用） */ ?>
<div class="toolbar">
  <a class="btn btn-ghost" href="/admin/<?= e($type ?? 'articles') ?>">← 返回</a>
  <span class="muted">回收站内容不会被搜索引擎收录；彻底删除不可恢复</span>
</div>
<table class="table table-hover">
  <thead><tr><th>#</th><th>标题</th><th>分类</th><th>移入时间</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty">回收站为空</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= e($r['title']) ?></td>
    <td class="muted"><?= e($cats[(int)($r['category_id'] ?? 0)] ?? ($r['slug'] ?? '')) ?></td>
    <td class="muted"><?= e($r['deleted_at']) ?></td>
    <td class="ops">
      <form method="post" action="/admin/<?= e($type ?? 'articles') ?>/restore/<?= (int)$r['id'] ?>" style="display:inline"><?= csrf_field() ?>
        <button class="btn btn-sm btn-primary" type="submit">恢复</button>
      </form>
      <form method="post" action="/admin/<?= e($type ?? 'articles') ?>/purge/<?= (int)$r['id'] ?>" style="display:inline" data-confirm="永久删除后不可恢复，确认？"><?= csrf_field() ?>
        <button class="btn btn-sm btn-danger" type="submit">永久删除</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
