<?php /** 预约确认+支付页（v2.17.0）：从咨询师日历点击时段进入，时段锁定 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">预约确认</h1>
    <p class="page-sub">时段已锁定，提交后即时生效（机构确认后正式成立）。信息严格保密。</p>

    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <?= $formTopExtras ?? '' ?>

    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;gap:16px;align-items:center">
        <?php if (!empty($c['avatar'])): ?>
        <img src="<?= e(thumb((string)$c['avatar'])) ?>" alt="<?= e($c['name']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover">
        <?php endif; ?>
        <div>
          <b style="font-size:16px"><?= e($c['name']) ?></b>
          <span class="muted"><?= e((string)($c['title'] ?? '')) ?></span>
          <div style="margin-top:6px;font-size:15px">
            <b style="color:#0d9488"><?= e((string)$date) ?>（<?= e($week) ?>）<?= e($slotLabel) ?></b>
            <a class="hint" style="margin-left:8px" href="<?= url('/booking?counselor=' . (int)$c['id'] . '&source=counselor') ?>">换时段</a>
          </div>
        </div>
      </div>
    </div>

    <form class="form card" method="post" action="<?= url('/booking') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="booking_mode" value="slot">
      <input type="hidden" name="source" value="slot">
      <input type="hidden" name="counselor_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="prefer_date" value="<?= e($date) ?>">
      <input type="hidden" name="slot_start" value="<?= e($slotStart) ?>">
      <input type="hidden" name="prefer_slot" value="<?= e($slotLabel) ?>">
      <div class="form-grid">
        <label class="field">
          <span class="label">姓名 <em>*</em></span>
          <input type="text" name="name" required maxlength="40" value="<?= e((string)($member['name'] ?? '')) ?>" placeholder="怎么称呼你">
        </label>
        <label class="field">
          <span class="label">手机号 <em>*</em></span>
          <?php $mPhone = (string)($member['phone'] ?? ''); ?>
          <input type="tel" name="phone" required pattern="1[3-9]\d{9}" maxlength="11" value="<?= e($mPhone) ?>" <?= $mPhone !== '' ? 'readonly' : '' ?> placeholder="用于确认预约">
        </label>
        <label class="field">
          <span class="label">咨询方式</span>
          <select name="method" id="methodSel">
            <option value="视频">视频（线上）</option>
            <option value="面对面">面对面</option>
            <option value="电话">电话</option>
          </select>
        </label>
        <label class="field">
          <span class="label">服务项目</span>
          <select name="service_id">
            <option value="">暂不确定</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
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
          <input type="email" name="email" maxlength="120" value="<?= e((string)($member['email'] ?? '')) ?>">
        </label>
        <?= $formExtras ?? '' ?>
        <label class="field field-full">
          <span class="label">想咨询的方向（选填，严格保密）</span>
          <textarea name="issue" rows="3" maxlength="1000" placeholder="简单描述你想面对的问题即可"></textarea>
        </label>
        <div class="field field-full checkbox-line" id="guardianRow" hidden>
          <label><input type="checkbox" name="guardian_consent" value="1" id="guardianConsent"> 未满 18 岁由监护人代为预约，并已取得监护人知情同意</label>
        </div>
        <label class="field field-full checkbox-line">
          <label><input type="checkbox" name="consent" value="1" required> 我已阅读并同意<a href="<?= url('/p/consent') ?>" target="_blank">《咨询知情同意书》</a>与<a href="<?= url('/p/privacy') ?>" target="_blank">《隐私政策》</a></label>
        </label>
        <label class="field">
          <span class="label">验证码 <em>*</em></span>
          <div class="captcha-row">
            <input type="text" name="captcha" required maxlength="4" autocomplete="off" placeholder="请输入右侧字符">
            <img src="<?= url('/captcha') ?>" alt="验证码" id="captchaImg" title="点击刷新" onclick="this.src='<?= url('/captcha') ?>?t='+Date.now()">
          </div>
        </label>
      </div>

      <div id="feeBox" class="alert alert-info" style="display:none;margin:12px 0">
        视频咨询费用：<b id="feeAmt"><?= $feeCents > 0 ? number_format($feeCents / 100, 2) : '' ?></b> 元，提交后在线支付（微信扫码/微信内支付）。
        <?php if ($feeCents <= 0): ?><span class="hint">该咨询师未设置线上价格，机构确认后线下沟通结算。</span><?php endif; ?>
      </div>
      <p class="form-privacy">面对面/电话咨询不在线收取费用；提交后在「会员中心 → 我的预约」管理，咨询开始前将收到微信/短信提醒。</p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">确认预约</button>
    </form>

    <?php if (!$member): ?>
    <p class="muted center" style="margin-top:10px">
      <a href="<?= url('/member/login') ?>">登录会员</a> 可在线支付与接收微信提醒；不登录也可提交预约（视频咨询费需登录后支付）。
    </p>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var sel = document.getElementById('methodSel'), box = document.getElementById('feeBox');
  function sync() {
    var v = sel.value;
    box.style.display = (v === '视频' && '<?= $feeCents > 0 ? '1' : '0' ?>' === '1') ? 'block' : 'none';
  }
  sel.addEventListener('change', sync); sync();
  var ager = document.getElementById('ageRange'), g = document.getElementById('guardianRow');
  ager.addEventListener('change', function () { g.hidden = ager.value !== '18岁以下'; });
})();
</script>
