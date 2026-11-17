<?php /** 插件前台页通用壳（v2.7.0）：插件输出包进主题布局，含 flash */ ?>
<div class="container member-wrap">
  <?php foreach (($flashes ?? []) as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?= $content ?>
</div>
