<?php /** 预约表单 */ ?>
<section class="section">
  <div class="container narrow">
    <h1 class="page-title">预约咨询</h1>
    <p class="page-sub">提交后我们会尽快与你确认时间（通常 24 小时内）。信息严格保密。</p>

    <?php $flashes = app\Core\Session::takeFlashes(); foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <?= $formTopExtras ?? '' ?>

    <form class="form card" method="post" action="<?= url('/booking') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="source" value="<?= e(in_array(($source ?? ''), ['assessment','chat','counselor'], true) ? $source : 'web') ?>">
      <div class="form-grid">
        <label class="field">
          <span class="label">姓名 <em>*</em></span>
          <input type="text" name="name" required maxlength="40" placeholder="怎么称呼你">
        </label>
        <label class="field">
          <span class="label">手机号 <em>*</em></span>
          <input type="tel" name="phone" required pattern="1[3-9]\d{9}" maxlength="11" placeholder="用于确认预约">
        </label>
        <label class="field">
          <span class="label">咨询方式</span>
          <select name="method">
            <option value="面对面">面对面</option>
            <option value="视频">视频</option>
            <option value="电话">电话</option>
          </select>
        </label>
        <label class="field">
          <span class="label">期望日期</span>
          <input type="date" name="prefer_date" min="<?= date('Y-m-d') ?>">
        </label>
        <label class="field">
          <span class="label">服务项目</span>
          <select name="service_id">
            <option value="">暂不确定</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $preService === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">指定咨询师</span>
          <select name="counselor_id">
            <option value="">由机构推荐</option>
            <?php foreach ($counselors as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $preCounselor === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['title'] ? '（' . e($c['title']) . '）' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="field field-full" id="slotPicker" hidden>
          <span class="label">该咨询师当天可约时段（点击选用，也可自由填写）</span>
          <div class="chip-row" id="slotChips"></div>
          <span class="hint" id="slotHint"></span>
        </div>
        <label class="field">
          <span class="label">期望时段</span>
          <input type="text" name="prefer_slot" id="preferSlot" maxlength="60" placeholder="如：工作日晚间 / 周六上午">
        </label>
        <?= $formExtras ?? '' ?>
        <label class="field">
          <span class="label">年龄段</span>
          <select name="age_range" id="ageRange">
            <option value="">不愿透露</option>
            <option>18岁以下</option><option>18-25岁</option><option>26-35岁</option>
            <option>36-45岁</option><option>46-60岁</option><option>60岁以上</option>
          </select>
        </label>
        <label class="field field-full">
          <span class="label">想咨询的方向（选填，严格保密）</span>
          <textarea name="issue" rows="4" maxlength="1000" placeholder="简单描述你想面对的问题即可，例如：最近情绪低落、亲子沟通困难…"></textarea>
        </label>
        <label class="field field-full checkbox-line">
          <label><input type="checkbox" name="urgency" value="1"> 情况紧急，希望尽快安排</label>
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
      <p class="form-privacy">提交即表示同意<a href="<?= url('/p/privacy') ?>">《隐私政策》</a>。我们承诺对来访信息严格保密。</p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">提交预约</button>
    </form>

    <p class="muted center">已提交过预约？<a href="<?= url('/booking/check') ?>">查询预约进度 →</a></p>
  </div>
</section>
