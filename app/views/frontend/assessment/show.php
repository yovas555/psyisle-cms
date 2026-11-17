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
          <label class="assess-opt">
            <input type="radio" name="q<?= $i ?>" value="<?= (int)$opt[1] ?>" required>
            <span><?= e($opt[0]) ?></span>
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
