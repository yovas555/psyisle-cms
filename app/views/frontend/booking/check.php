<?php /** 预约进度查询 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">预约进度查询</h1>
    <form class="form card" method="post" action="<?= url('/booking/check') ?>">
      <?= csrf_field() ?>
      <label class="field">
        <span class="label">查询码 <em>*</em></span>
        <input type="text" name="code" required maxlength="8" placeholder="提交预约时生成的查询码">
      </label>
      <label class="field">
        <span class="label">手机号 <em>*</em></span>
        <input type="tel" name="phone" required maxlength="11" placeholder="预约时填写的手机号">
      </label>
      <button class="btn btn-primary" type="submit">查询</button>
    </form>

    <?php if ($result): ?>
      <?php if (!empty($result['error'])): ?>
      <div class="alert alert-error"><?= e($result['error']) ?></div>
      <?php else: $b = $result['booking']; ?>
      <div class="card result-card">
        <h2>预约状态：<?= e($b['status']) ?></h2>
        <table class="table">
          <tr><th>查询码</th><td><?= e($b['code']) ?></td></tr>
          <tr><th>提交时间</th><td><?= e($b['created_at']) ?></td></tr>
          <tr><th>期望时间</th><td><?= e(($b['prefer_date'] ?: '不限') . ' ' . ($b['prefer_slot'] ?: '')) ?></td></tr>
          <tr><th>机构备注</th><td><?= $b['note'] ? e($b['note']) : '—' ?></td></tr>
        </table>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
