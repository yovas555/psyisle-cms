<?php /** 错误页 */ ?>
<section class="section">
  <div class="container narrow center">
    <h1 class="page-title"><?= e($code ?? 404) ?></h1>
    <p class="page-sub"><?= e($msg ?? '页面不存在') ?></p>
    <p><a class="btn btn-primary" href="<?= url('/') ?>">返回首页</a></p>
    <p class="muted">或查看 <a href="<?= url('/news') ?>">心理资讯</a> / <a href="<?= url('/counselors') ?>">咨询师团队</a></p>
  </div>
</section>
