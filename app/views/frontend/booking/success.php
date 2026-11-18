<?php /** 预约结果页（v2.17.0：时段预约免确认——无需支付即「预约成功」；需支付时引导付款，支付完成自动生效） */ ?>
<section class="section">
  <div class="container narrow center">
    <div class="card success-card">
      <div class="success-icon">✓</div>
      <?php $slotConfirmed = !empty($b['confirmed']); ?>
      <h1><?= $slotConfirmed ? '预约成功' : '预约已提交' ?></h1>
      <?php if ($slotConfirmed): ?>
      <p>感谢信任，<?= e($b['name']) ?>。您的预约<b>已确认生效</b>，无需机构再确认；咨询开始前将收到微信/短信提醒。</p>
      <?php else: ?>
      <p>感谢信任，<?= e($b['name']) ?>。我们已收到你的预约，会尽快与你电话或短信确认时间。</p>
      <?php endif; ?>
      <div class="code-box">
        <p>你的预约查询码（请妥善保存）</p>
        <strong><?= e($b['code']) ?></strong>
      </div>
      <p class="muted">凭查询码 + 手机号可随时<a href="<?= url('/booking/check') ?>">查询预约进度</a></p>
      <?php if (($payBooking ?? '') === 'unpaid' && (int)($payAmount ?? 0) > 0): $bid = (int)(\app\Core\Database::value('SELECT id FROM bookings WHERE code=?', [$b['code']] ?? [''])); ?>
      <div class="alert alert-warning" style="margin-top:14px">
        本次为<b>线上视频咨询</b>，费用 <b><?= number_format(((int)$payAmount) / 100, 2) ?> 元</b>，<b>支付完成后预约即自动生效</b>（无需等待确认）。
      </div>
      <?php if ($isMember): ?>
      <p><a class="btn btn-primary" href="<?= url('/plugin/wechat-pay/pay-booking/' . $bid) ?>">立即支付咨询费</a></p>
      <p class="hint">支付成功后预约自动确认；咨询开始前 30 分钟可在「会员中心 → 我的预约」进入视频咨询室。</p>
      <?php else: ?>
      <p class="hint"><a href="<?= url('/member/login') ?>">登录会员</a> 后即可在线支付；非会员可电话联系机构线下办理。</p>
      <?php endif; ?>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= url('/') ?>">返回首页</a>
    </div>
  </div>
</section>
