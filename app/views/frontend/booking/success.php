<?php /** 预约成功 */ ?>
<section class="section">
  <div class="container narrow center">
    <div class="card success-card">
      <div class="success-icon">✓</div>
      <h1>预约已提交</h1>
      <p>感谢信任，<?= e($b['name']) ?>。我们已收到你的预约，会尽快与你电话或短信确认时间。</p>
      <div class="code-box">
        <p>你的预约查询码（请妥善保存）</p>
        <strong><?= e($b['code']) ?></strong>
      </div>
      <p class="muted">凭查询码 + 手机号可随时<a href="<?= url('/booking/check') ?>">查询预约进度</a></p>
      <?php if (($payBooking ?? '') === 'unpaid' && (int)($payAmount ?? 0) > 0): $bid = (int)(\app\Core\Database::value('SELECT id FROM bookings WHERE code=?', [$b['code']] ?? [''])); ?>
      <div class="alert alert-warning" style="margin-top:14px">
        本次为<b>线上视频咨询</b>，费用 <b><?= number_format(((int)$payAmount) / 100, 2) ?> 元</b>，请完成支付后由机构确认排期。
      </div>
      <?php if ($isMember): ?>
      <p><a class="btn btn-primary" href="<?= url('/plugin/wechat-pay/pay-booking/' . $bid) ?>">立即支付咨询费</a></p>
      <p class="hint">支付完成后，机构确认排期；咨询开始前 30 分钟可在「会员中心 → 我的预约」进入视频咨询室。</p>
      <?php else: ?>
      <p class="hint"><a href="<?= url('/member/login') ?>">登录会员</a> 后即可在线支付；非会员可电话联系机构线下办理。</p>
      <?php endif; ?>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= url('/') ?>">返回首页</a>
    </div>
  </div>
</section>
