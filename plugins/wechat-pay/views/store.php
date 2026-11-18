<?php /** 微信支付 · 套餐列表（会员前台） */ ?>
<div class="member-head">
  <h1>购买套餐</h1>
  <p class="muted">在线支付后自动入账 · <?= e(\app\Services\MemberService::maskPhone($m['phone'])) ?></p>
</div>

<?php if (!$pkgs): ?>
<p class="muted">暂无在售咨询套餐，请稍后再看或联系机构。</p>
<p style="margin-top:12px"><a class="btn btn-ghost" href="<?= url('/member') ?>">返回会员中心</a></p>
<?php else: ?>
<div class="member-grid">
  <?php foreach ($pkgs as $p): ?>
  <div class="member-card" style="cursor:default">
    <b><?= e((string)$p['name']) ?></b>
    <span class="muted"><?= (int)$p['sessions'] ?> 次咨询 · 有效期 <?= (int)$p['valid_days'] ?> 天</span>
    <?php if ((string)$p['bonus_text'] !== ''): ?><span class="muted"><?= e((string)$p['bonus_text']) ?></span><?php endif; ?>
    <strong style="font-size:20px;color:var(--primary,#0d9488)">¥<?= number_format((int)$p['price_cents'] / 100, 2) ?></strong>
    <form method="post" action="<?= url('/plugin/wechat-pay/buy') ?>" style="margin-top:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="package_id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-primary" type="submit" style="width:100%">微信支付购买</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<p class="hint">PC 浏览器弹出二维码扫码支付；在微信中打开则直接拉起微信支付。支付成功咨询次数立即到账，可在「我的咨询套餐」查看。</p>
<p style="margin-top:12px"><a class="btn btn-ghost" href="<?= url('/member') ?>">返回会员中心</a></p>
<?php endif; ?>
