<?php /** 预约确认+支付页：从咨询师日历点击时段进入，时段锁定 */ ?>
<section class="section booking-page booking-confirm-page">
  <div class="container">
    <header class="booking-hero booking-confirm-hero">
      <div>
        <span class="booking-eyebrow">预约确认</span>
        <h1 class="page-title">核对你的咨询安排</h1>
        <p class="page-sub">提交时会再次核验时段。无需支付的时段预约直接生效；需要线上支付的，完成付款后正式成立。</p>
      </div>
      <div class="booking-steps" aria-label="预约步骤">
        <span class="is-done"><b>✓</b>选择时段</span>
        <i></i>
        <span class="is-current"><b>2</b>确认预约</span>
        <i></i>
        <span><b>3</b>开始咨询</span>
      </div>
    </header>

    <p class="booking-prepare-link">第一次咨询？可先查看<a href="<?= url('/booking/prepare') ?>" target="_blank" rel="noopener">咨询前准备与取消改期规则</a>。</p>

    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <?= $formTopExtras ?? '' ?>

    <div class="booking-layout">
      <form class="form card booking-form" method="post" action="<?= url('/booking') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_mode" value="slot">
        <input type="hidden" name="source" value="slot">
        <input type="hidden" name="counselor_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="prefer_date" value="<?= e($date) ?>">
        <input type="hidden" name="slot_start" value="<?= e($slotStart) ?>">
        <input type="hidden" name="prefer_slot" value="<?= e($slotLabel) ?>">

        <section class="booking-appointment-card">
          <div class="booking-counselor">
            <?php if (!empty($c['avatar'])): ?>
            <img src="<?= e(thumb((string)$c['avatar'])) ?>" alt="<?= e($c['name']) ?>">
            <?php else: ?><span class="booking-avatar-fallback"><?= e(mb_substr((string)$c['name'], 0, 1)) ?></span><?php endif; ?>
            <div><span>你的咨询师</span><h2><?= e($c['name']) ?></h2><p><?= e((string)($c['title'] ?? '')) ?></p></div>
          </div>
          <div class="booking-appointment-time">
            <span>已选时段</span>
            <b><?= e((string)$date) ?>（<?= e($week) ?>）</b>
            <strong><?= e($slotLabel) ?></strong>
            <a id="changeSlotLink" href="<?= url('/booking?counselor=' . (int)$c['id'] . '&source=counselor') ?>">更换时段</a>
          </div>
        </section>

        <section class="booking-form-section">
          <div class="booking-section-heading"><span>01</span><div><h2>确认咨询方式与服务</h2><p>仅显示该咨询师在当前时段可提供的咨询方式。</p></div></div>
          <div class="form-grid">
            <label class="field">
              <span class="label">咨询方式</span>
              <select name="method" id="methodSel">
                <?php foreach ($slotMethods as $mode): ?>
                <option value="<?= e($mode) ?>" <?= (string)($preMethod ?? '') === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint" id="methodHint">视频与电话属于线上咨询，无需到访门店。</span>
            </label>
            <label class="field">
              <span class="label">服务项目</span>
              <select name="service_id" id="serviceSel">
                <option value="" data-price-cents="<?= (int)($genericFeeCents ?? 0) ?>">暂不确定</option>
                <?php foreach ($services as $s): ?>
                <option value="<?= (int)$s['id'] ?>" data-price-cents="<?= (int)($servicePrices[(int)$s['id']] ?? 0) ?>" <?= $preService === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint">价格以所选服务为基准；机构设置的分时定价优先。</span>
            </label>
            <?= $formExtras ?? '' ?>
          </div>
        </section>

        <section class="booking-form-section">
          <div class="booking-section-heading"><span>02</span><div><h2>你的联系方式</h2><p>用于确认预约、付款提醒与咨询前通知。</p></div></div>
          <div class="form-grid">
            <label class="field">
              <span class="label">姓名 <em>*</em></span>
              <input type="text" name="name" required maxlength="40" value="<?= e((string)($member['name'] ?? '')) ?>" placeholder="怎么称呼你" autocomplete="name">
            </label>
            <label class="field">
              <span class="label">手机号 <em>*</em></span>
              <?php $mPhone = (string)($member['phone'] ?? ''); ?>
              <input type="tel" name="phone" required pattern="1[3-9]\d{9}" maxlength="11" value="<?= e($mPhone) ?>" <?= $mPhone !== '' ? 'readonly' : '' ?> placeholder="用于确认预约" autocomplete="tel">
            </label>
            <label class="field">
              <span class="label">年龄段</span>
              <select name="age_range" id="ageRange">
                <option value="">不愿透露</option>
                <option>18岁以下</option><option>18-25岁</option><option>26-35岁</option>
                <option>36-45岁</option><option>46-60岁</option><option>60岁以上</option>
              </select>
            </label>
            <label class="field">
              <span class="label">电子邮箱（接收确认邮件，选填）</span>
              <input type="email" name="email" maxlength="120" value="<?= e((string)($member['email'] ?? '')) ?>" autocomplete="email">
            </label>
            <label class="field field-full">
              <span class="label">想咨询的方向（选填，严格保密）</span>
              <textarea name="issue" rows="3" maxlength="1000" placeholder="简单描述你想面对的问题即可。"></textarea>
            </label>
            <div class="field field-full checkbox-line" id="guardianRow" hidden>
              <label><input type="checkbox" name="guardian_consent" value="1" id="guardianConsent"> 未满 18 岁由监护人代为预约，并已取得监护人知情同意</label>
            </div>
          </div>
        </section>

        <section class="booking-form-section booking-submit-section">
          <div id="feeBox" class="booking-fee-box">
            <div><span>视频咨询费用</span><b id="feeAmount"><?= $feeCents > 0 ? '¥' . number_format($feeCents / 100, 2) : '待机构确认' ?></b></div>
            <p id="feeNote"><?= $feeCents > 0 ? '提交后在线支付，付款成功后预约正式生效。' : '当前服务尚未设置线上价格，提交后由机构沟通结算。' ?></p>
          </div>
          <label class="field field-full checkbox-line">
            <label><input type="checkbox" name="consent" value="1" required> 我已阅读并同意<a href="<?= url('/p/consent') ?>" target="_blank" rel="noopener">《咨询知情同意书》</a>与<a href="<?= url('/p/privacy') ?>" target="_blank" rel="noopener">《隐私政策》</a></label>
          </label>
          <label class="field">
            <span class="label">验证码 <em>*</em></span>
            <div class="captcha-row">
              <input type="text" name="captcha" required maxlength="4" autocomplete="off" placeholder="请输入右侧字符">
              <img src="<?= url('/captcha') ?>" alt="验证码" id="captchaImg" title="点击刷新" onclick="this.src='<?= url('/captcha') ?>?t='+Date.now()">
            </div>
          </label>
          <button class="btn btn-primary btn-lg btn-block" type="submit">确认预约</button>
          <p class="form-privacy" id="bookingSubmitNote">面对面与电话咨询不在线收取费用；预约可在会员中心管理。</p>
        </section>
      </form>

      <aside class="booking-aside">
        <div class="booking-aside-card booking-summary-card">
          <span class="booking-aside-kicker">费用与状态</span>
          <h2 id="bookingStatusTitle">提交后确认</h2>
          <dl>
            <div><dt>咨询师</dt><dd><?= e($c['name']) ?></dd></div>
            <div><dt>时间</dt><dd><?= e($slotLabel) ?></dd></div>
            <div><dt>当前方式</dt><dd id="bookingSummaryMethod"><?= e($preMethod) ?></dd></div>
            <div><dt>费用</dt><dd id="bookingSummaryFee"><?= $feeCents > 0 ? '¥' . number_format($feeCents / 100, 2) : '待确认' ?></dd></div>
          </dl>
          <p class="hint" id="bookingStatusHint">无需在线支付的时段预约，提交后直接生效。</p>
        </div>
        <div class="booking-aside-card">
          <h3>改期与取消</h3>
          <p>开始前 24 小时以上可在会员中心申请改期或取消。临近开始的变更，请及时联系机构协助处理。</p>
          <a href="<?= url('/booking/prepare') ?>" target="_blank" rel="noopener">查看完整规则 →</a>
        </div>
        <div class="booking-aside-card booking-privacy-card">
          <b>安全与保密</b>
          <p>付款成功后，管理员会收到预约邮件，你的咨询师会收到站内、短信与服务号提醒（以咨询师已绑定的接收方式为准）。</p>
        </div>
      </aside>
    </div>

    <?php if (!$member): ?>
    <p class="muted center booking-member-link"><a href="<?= url('/member/login') ?>">登录会员</a> 可在线支付与接收微信提醒；不登录也可提交预约（视频咨询费需登录后支付）。</p>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var sel = document.getElementById('methodSel'), serviceSel = document.getElementById('serviceSel');
  var box = document.getElementById('feeBox'), amount = document.getElementById('feeAmount'), note = document.getElementById('feeNote');
  var summaryMethod = document.getElementById('bookingSummaryMethod'), summaryFee = document.getElementById('bookingSummaryFee');
  var statusTitle = document.getElementById('bookingStatusTitle'), statusHint = document.getElementById('bookingStatusHint');
  var submitNote = document.getElementById('bookingSubmitNote'), changeLink = document.getElementById('changeSlotLink');
  function sync() {
    var option = serviceSel.options[serviceSel.selectedIndex];
    var cents = option ? parseInt(option.getAttribute('data-price-cents') || '0', 10) : 0;
    var online = sel.value === '视频';
    var price = cents > 0 ? '¥' + (cents / 100).toFixed(2) : '待机构确认';
    amount.textContent = online ? price : '不在线支付';
    note.textContent = online
      ? (cents > 0 ? '提交后在线支付，付款成功后预约正式生效。' : '当前服务尚未设置线上价格，提交后由机构沟通结算。')
      : '面对面与电话咨询由机构按现场或约定方式结算。';
    note.hidden = !online;
    box.classList.toggle('is-offline', !online);
    summaryMethod.textContent = sel.value;
    summaryFee.textContent = online ? price : '线下结算';
    statusTitle.textContent = online && cents > 0 ? '付款后生效' : '提交后生效';
    statusHint.textContent = online && cents > 0
      ? '提交后进入支付页，付款成功才算正式预约。'
      : '无需在线支付，提交后预约直接生效。';
    submitNote.textContent = online && cents > 0
      ? '点击确认后将进入支付流程；付款成功后预约正式生效。'
      : '面对面与电话咨询不在线收取费用；预约可在会员中心管理。';
    if (changeLink) changeLink.href = '<?= url('/booking?counselor=' . (int)$c['id'] . '&source=counselor') ?>' + (serviceSel.value ? '&service=' + encodeURIComponent(serviceSel.value) : '') + '&method=' + encodeURIComponent(sel.value);
  }
  sel.addEventListener('change', sync); serviceSel.addEventListener('change', sync); sync();
  var ager = document.getElementById('ageRange'), g = document.getElementById('guardianRow');
  ager.addEventListener('change', function () { g.hidden = ager.value !== '18岁以下'; });
})();
</script>
