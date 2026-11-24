<?php /** 量表答题页 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title"><?= e($a['name']) ?></h1>
    <p class="page-sub"><?= e($a['description']) ?></p>
    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <form class="form card assess-form" method="post" action="<?= url('/assessment/' . $a['slug']) ?>">
      <?= csrf_field() ?>
      <?php foreach ($items as $i => $item): ?>
      <fieldset class="assess-item">
        <legend><span class="q-no"><?= $i + 1 ?></span><?= e($item['q']) ?></legend>
        <div class="assess-options">
          <?php foreach ($item['options'] as $oi => $opt): ?>
          <?php
            // 兼容两种选项结构：内置量表 [文字,分值] / 专业量表 {label,score} / 纯文本
            $optText = is_array($opt)
              ? (isset($opt['label']) ? (string)$opt['label'] : (string)($opt[0] ?? ''))
              : (string)$opt;
          ?>
          <label class="assess-opt">
            <input type="radio" name="q<?= $i ?>" value="<?= (int)$oi ?>" required>
            <span><?= e($optText) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <p class="form-privacy"><?= e($a['disclaimer']) ?></p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">提交并查看结果</button>
    </form>
  </div>
</section>
