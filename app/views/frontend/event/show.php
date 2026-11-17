<?php /** 活动详情 + 报名表单 */ $full = $e['status'] === 'full' || ($left !== null && $left <= 0); ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title"><?= e($e['title']) ?></h1>

    <div class="card service-info">
      <div class="card-body">
        <?php if ($e['event_date']): ?><p><strong>活动时间：</strong><?= e(dt($e['event_date'], 'Y年m月d日 H:i')) ?><?= $e['duration_hours'] ? '（约 ' . rtrim(rtrim((string)$e['duration_hours'], '0'), '.') . ' 小时）' : '' ?></p><?php endif; ?>
        <?php if ($e['location']): ?><p><strong>活动地点：</strong><?= e($e['location']) ?></p><?php endif; ?>
        <?php if ($e['fee']): ?><p><strong>活动费用：</strong><?= e($e['fee']) ?></p><?php endif; ?>
        <?php if ($e['capacity']): ?><p><strong>报名名额：</strong><?= (int)$registered ?> / <?= (int)$e['capacity'] ?> 人<?= $left !== null ? '（剩 ' . $left . ' 个）' : '' ?></p><?php endif; ?>
      </div>
    </div>

    <div class="article-content"><?= eraw($e['content']) ?></div>

    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <?php if (!$full): ?>
    <h2 class="section-title" style="margin-top:28px">立即报名</h2>
    <form class="form card" method="post" action="<?= url('/events/' . $e['slug'] . '/register') ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label class="field">
          <span class="label">姓名 <em>*</em></span>
          <input type="text" name="name" required maxlength="40">
        </label>
        <label class="field">
          <span class="label">手机号 <em>*</em></span>
          <input type="tel" name="phone" required pattern="1[3-9]\d{9}" maxlength="11">
        </label>
        <label class="field">
          <span class="label">参加人数</span>
          <select name="people_count">
            <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?> 人</option><?php endfor; ?>
          </select>
        </label>
        <label class="field field-full">
          <span class="label">想了解的问题（选填）</span>
          <textarea name="note" rows="2" maxlength="300"></textarea>
        </label>
        <label class="field">
          <span class="label">验证码 <em>*</em></span>
          <div class="captcha-row">
            <input type="text" name="captcha" required maxlength="4" autocomplete="off">
            <img src="<?= url('/captcha') ?>" alt="验证码" onclick="this.src='<?= url('/captcha') ?>?t='+Date.now()" title="点击刷新">
          </div>
        </label>
      </div>
      <p class="form-privacy">提交即同意<a href="<?= url('/p/privacy') ?>">《隐私政策》</a>，信息仅用于本次活动联系。</p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">提交报名</button>
    </form>
    <?php else: ?>
    <div class="alert alert-warning">本期名额已满，可<a href="<?= url('/message') ?>">留言</a>预约下期或关注「心理资讯」。</div>
    <?php endif; ?>
  </div>
</section>
