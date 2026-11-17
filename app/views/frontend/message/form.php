<?php /** 留言表单 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">咨询留言</h1>
    <p class="page-sub">不确定要不要预约？可以先留言问问。我们会在 1-2 个工作日内回复。</p>

    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <form class="form card" method="post" action="<?= url('/message') ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label class="field">
          <span class="label">怎么称呼</span>
          <input type="text" name="name" maxlength="40" placeholder="可匿名">
        </label>
        <label class="field">
          <span class="label">联系方式 <em>*</em></span>
          <input type="text" name="contact" required maxlength="120" placeholder="手机号 / 微信号 / 邮箱">
        </label>
        <label class="field field-full">
          <span class="label">主题</span>
          <input type="text" name="subject" maxlength="120" placeholder="一句话概括你的问题">
        </label>
        <label class="field field-full">
          <span class="label">留言内容 <em>*</em></span>
          <textarea name="content" rows="5" required maxlength="2000" placeholder="想了解什么、想面对什么，都可以写下来…"></textarea>
        </label>
        <label class="field">
          <span class="label">验证码 <em>*</em></span>
          <div class="captcha-row">
            <input type="text" name="captcha" required maxlength="4" autocomplete="off">
            <img src="<?= url('/captcha') ?>" alt="验证码" onclick="this.src='<?= url('/captcha') ?>?t='+Date.now()" title="点击刷新">
          </div>
        </label>
      </div>
      <p class="form-privacy">留言与回复内容仅工作人员可见。回复后凭「查询码」<a href="<?= url('/message/reply') ?>">查看回复</a>。</p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">提交留言</button>
    </form>

    <p class="muted center">已留言？<a href="<?= url('/message/reply') ?>">凭查询码查看回复 →</a></p>
  </div>
</section>
