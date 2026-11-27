<?php /** 预约申请表单（非固定时段；固定时段请从咨询师日历点击选择） */ ?>
<section class="section booking-page">
  <div class="container">
    <header class="booking-hero">
      <div>
        <span class="booking-eyebrow">预约咨询</span>
        <h1 class="page-title">从一次被认真倾听开始</h1>
        <p class="page-sub">选择适合你的咨询方式与时间。我们会保护你的隐私，并由咨询师或机构与你确认后续安排。</p>
      </div>
      <div class="booking-steps" aria-label="预约步骤">
        <span class="is-current"><b>1</b>填写预约</span>
        <i></i>
        <span><b>2</b>确认安排</span>
        <i></i>
        <span><b>3</b>开始咨询</span>
      </div>
    </header>

    <div class="booking-path-note">
      <div><b>想直接选定时间？</b><span>咨询师详情页会显示真实可约时段，点击即可进入确认页。</span></div>
      <a class="btn btn-ghost btn-sm" href="<?= url('/counselors') ?>">查看咨询师与可约时间 →</a>
    </div>

    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <?= $formTopExtras ?? '' ?>

    <div class="booking-layout">
      <form class="form card booking-form" method="post" action="<?= url('/booking') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="source" value="<?= e(in_array(($source ?? ''), ['assessment','chat','counselor'], true) ? $source : 'web') ?>">

        <section class="booking-form-section">
          <div class="booking-section-heading"><span>01</span><div><h2>怎么联系你</h2><p>仅用于本次预约确认与提醒。</p></div></div>
          <div class="form-grid">
            <label class="field">
              <span class="label">姓名 <em>*</em></span>
              <input type="text" name="name" required maxlength="40" placeholder="怎么称呼你" autocomplete="name">
            </label>
            <label class="field">
              <span class="label">手机号 <em>*</em></span>
              <input type="tel" name="phone" required pattern="1[3-9]\d{9}" maxlength="11" placeholder="用于确认预约" autocomplete="tel">
            </label>
          </div>
        </section>

        <section class="booking-form-section">
          <div class="booking-section-heading"><span>02</span><div><h2>选择咨询安排</h2><p>固定时段会再次核验；申请时间以机构确认为准。</p></div></div>
          <div class="form-grid">
            <label class="field">
              <span class="label">咨询方式</span>
              <select name="method" id="methodSel">
                <?php foreach (\app\Services\CounselorProfile::MODES as $mode): ?>
                <option value="<?= e($mode) ?>" <?= ($preMethod ?? '') === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint" id="methodHint">选择咨询师后将仅显示其支持的方式</span>
            </label>
            <label class="field">
              <span class="label">服务项目</span>
              <select name="service_id" id="serviceSel">
                <option value="">暂不确定</option>
                <?php foreach ($services as $s): ?>
                <option value="<?= (int)$s['id'] ?>" data-price="<?= e(\app\Services\BookingPricing::formatRange((float)($s['price_from'] ?? 0) ?: null, (float)($s['price_to'] ?? 0) ?: null)) ?>" <?= $preService === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint" id="serviceHint">仅可选择该咨询师实际提供的服务；选择项目后，时段与价格均按该项目显示</span>
            </label>
            <label class="field">
              <span class="label">指定咨询师</span>
              <select name="counselor_id" id="counselorSel">
                <option value="">由机构推荐</option>
                <?php foreach ($counselors as $c): ?>
                <?php $cModes = \app\Services\CounselorProfile::modes($c['service_modes'] ?? ''); ?>
                <option value="<?= (int)$c['id'] ?>" data-modes="<?= e(implode(',', $cModes)) ?>" data-services="<?= e(implode(',', $counselorServices[(int)$c['id']] ?? [])) ?>" <?= $preCounselor === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['title'] ? '（' . e($c['title']) . '）' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field">
              <span class="label">期望日期</span>
              <input type="date" name="prefer_date" min="<?= date('Y-m-d') ?>">
            </label>
            <div class="field field-full" id="slotPicker" hidden>
              <span class="label">该咨询师当天可约时段</span>
              <div class="chip-row" id="slotChips"></div>
              <span class="hint" id="slotHint"></span>
            </div>
            <label class="field field-full">
              <span class="label">期望时段</span>
              <input type="text" name="prefer_slot" id="preferSlot" maxlength="60" placeholder="如：工作日晚间 / 周六上午；也可点击上方可约时段">
            </label>
            <?= $formExtras ?? '' ?>
          </div>
        </section>

        <section class="booking-form-section">
          <div class="booking-section-heading"><span>03</span><div><h2>让我们更了解你</h2><p>选填内容会严格保密，可只写你愿意分享的部分。</p></div></div>
          <div class="form-grid">
            <label class="field">
              <span class="label">年龄段</span>
              <select name="age_range" id="ageRange">
                <option value="">不愿透露</option>
                <option>18岁以下</option><option>18-25岁</option><option>26-35岁</option>
                <option>36-45岁</option><option>46-60岁</option><option>60岁以上</option>
              </select>
            </label>
            <label class="field field-full">
              <span class="label">想咨询的方向（选填）</span>
              <textarea name="issue" rows="4" maxlength="1000" placeholder="例如：最近情绪低落、亲子沟通困难，或只是想找人聊聊。"></textarea>
            </label>
            <label class="field field-full checkbox-line">
              <label><input type="checkbox" name="urgency" value="1"> 情况紧急，希望尽快安排</label>
            </label>
            <div class="field field-full checkbox-line" id="guardianRow" hidden>
              <label><input type="checkbox" name="guardian_consent" value="1" id="guardianConsent"> 未满 18 岁由监护人代为预约，并已取得监护人知情同意</label>
            </div>
          </div>
        </section>

        <section class="booking-form-section booking-submit-section">
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
          <button class="btn btn-primary btn-lg btn-block" type="submit">提交预约申请</button>
          <p class="form-privacy">提交即表示同意<a href="<?= url('/p/privacy') ?>">《隐私政策》</a>。我们承诺对来访信息严格保密。</p>
        </section>
      </form>

      <aside class="booking-aside">
        <div class="booking-aside-card booking-summary-card">
          <span class="booking-aside-kicker">本次预约</span>
          <h2 id="bookingSummaryTitle">由机构推荐咨询师</h2>
          <dl>
            <div><dt>咨询方式</dt><dd id="bookingSummaryMethod">面对面</dd></div>
            <div><dt>服务项目</dt><dd id="bookingSummaryService">暂不确定</dd></div>
            <div><dt>期望日期</dt><dd id="bookingSummaryDate">待选择</dd></div>
          </dl>
          <p class="hint">固定时段预约提交后直接锁定；申请通道将由机构与你确认。</p>
        </div>
        <div class="booking-aside-card">
          <h3>预约之后</h3>
          <ol class="booking-next-list">
            <li><b>核对信息</b><span>咨询师或机构确认时间与咨询方式。</span></li>
            <li><b>完成支付</b><span>需要在线支付的视频咨询，付款后预约正式生效。</span></li>
            <li><b>按时咨询</b><span>可在会员中心查看预约、改期与取消规则。</span></li>
          </ol>
        </div>
        <div class="booking-aside-card booking-privacy-card">
          <b>你的信息会被保护</b>
          <p>咨询内容仅用于匹配与提供服务。未经你的授权，不会向无关第三方披露。</p>
          <a href="<?= url('/booking/prepare') ?>" target="_blank" rel="noopener">查看咨询前准备与取消改期规则 →</a>
        </div>
      </aside>
    </div>

    <p class="muted center booking-member-link">已提交过预约？<a href="<?= url('/member/bookings') ?>">登录会员中心查看进度 →</a></p>
  </div>
</section>
