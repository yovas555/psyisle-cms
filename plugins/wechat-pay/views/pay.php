<?php /** 微信支付 · 收银台页（Native 二维码 / JSAPI 直接拉起） */ ?>
<div class="member-login-card" style="text-align:center">
  <h1>微信支付</h1>

  <div id="payStatus" class="alert alert-info" style="max-width:420px;margin:10px auto">等待支付中…</div>

  <?php if (!empty($jsapi)): ?>
  <p class="muted" id="jsapiHint">正在拉起微信支付…若未响应，请点击下方按钮重试。</p>
  <button class="btn btn-primary" id="jsapiBtn" type="button" style="margin:10px auto;background:#07c160;color:#fff">重新拉起微信支付</button>
  <?php else: ?>
  <div id="qrcode" style="display:inline-block;margin:10px auto;background:#fff;padding:12px;border-radius:12px;border:1px solid #e2e8f0"></div>
  <p class="muted">请使用微信「扫一扫」支付<br><span style="font-size:12px">二维码 2 小时内有效</span></p>
  <?php endif; ?>

  <p style="margin-top:14px">
    <a class="btn btn-ghost" href="<?= url($backUrl ?? '/plugin/packages-pro/my') ?>"><?= e($backLabel ?? '我的咨询套餐') ?></a>
    <a class="btn btn-ghost" href="<?= e($storeUrl) ?>">返回<?= e($backLabel2 ?? '套餐列表') ?></a>
  </p>
</div>

<?php if (!empty($jsapi)): ?>
<script>
function callPay() {
  if (typeof WeixinJSBridge === 'undefined') {
    if (document.addEventListener) {
      document.addEventListener('WeixinJSBridgeReady', function () { WeixinJSBridge.invoke('getBrandWcpayRequest', <?= json_encode($jsapi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, onPayResult); }, false);
    }
    return;
  }
  WeixinJSBridge.invoke('getBrandWcpayRequest', <?= json_encode($jsapi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, onPayResult);
}
function onPayResult(res) {
  if (res && res.err_msg === 'get_brand_wcpay_request:ok') {
    document.getElementById('payStatus').textContent = '支付成功，正在确认咨询次数到账…';
    pollOnce();
  } else if (res && res.err_msg === 'get_brand_wcpay_request:cancel') {
    document.getElementById('payStatus').textContent = '已取消支付，可点击按钮重新拉起。';
  }
}
document.getElementById('jsapiBtn').addEventListener('click', callPay);
callPay();
</script>
<?php else: ?>
<script src="<?= e($assetUrl) ?>"></script>
<script>
new QRCode(document.getElementById('qrcode'), { text: <?= json_encode($codeUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
</script>
<?php endif; ?>

<script>
var statusUrl = <?= json_encode($statusUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var stop = false;
function pollOnce() {
  if (stop) return;
  fetch(statusUrl, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.ok && d.paid) {
        stop = true;
        document.getElementById('payStatus').className = 'alert alert-success';
        document.getElementById('payStatus').textContent = '支付成功！咨询次数已入账' + (d.remain !== null && d.remain !== undefined ? '（当前剩余 ' + d.remain + ' 次）' : '') + '。';
        setTimeout(function () { location.href = <?= json_encode(url('/plugin/packages-pro/my'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; }, 1800);
      } else if (d && d.ok && d.status === 'error') {
        stop = true;
        document.getElementById('payStatus').className = 'alert alert-error';
        document.getElementById('payStatus').textContent = '订单已关闭或支付失败，请返回套餐列表重新下单。';
      }
    })
    .catch(function () {});
}
setInterval(pollOnce, 2500);
pollOnce();
</script>
