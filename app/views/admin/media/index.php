<?php /** 媒体库 */ ?>
<div class="page-guide" data-guide-key="media">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>媒体库怎么用</b>
  <ol><li>点击图片复制路径（/uploads/…），可粘贴到轮播图/二维码等字段</li><li>文章编辑器「插图」按钮会直接弹出本媒体库</li><li>删除前确认前台无引用</li></ol>
</div>
<div class="toolbar">
  <button class="btn btn-primary" id="mediaUploadBtn" type="button">＋ 上传图片</button>
  <input type="file" id="mediaFileInput" accept="image/*" multiple hidden>
  <span class="muted">共 <?= (int)$total ?> 个文件 · 点击图片复制路径</span>
</div>
<div class="media-grid">
  <?php if (!$rows): ?><p class="empty">媒体库为空，点击右上角上传。</p><?php endif; ?>
  <?php foreach ($rows as $a): ?>
  <div class="media-item">
    <img src="<?= e($a['path']) ?>" alt="<?= e($a['original_name']) ?>" loading="lazy" data-path="<?= e($a['path']) ?>" title="点击复制路径：<?= e($a['path']) ?>">
    <div class="media-meta">
      <span title="<?= e($a['original_name']) ?>"><?= e(str_take((string)$a['original_name'], 16, '…')) ?></span>
      <span class="muted"><?= round((int)$a['size'] / 1024) ?>KB</span>
      <form method="post" action="/admin/media/delete/<?= (int)$a['id'] ?>" data-confirm="删除该文件？"><?= csrf_field() ?>
        <button class="btn btn-sm btn-danger" type="submit">删除</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="pagination">
  <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
    <?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
</nav>
<?php endif; ?>
