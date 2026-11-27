<?php
/** 咨询师工作台 · 我的排班 */
$slotCounselorId = (int)($me['id'] ?? 0);
$slotColumns = array_column(\app\Core\Database::fetchAll('SHOW COLUMNS FROM counselor_slots'), 'Field');
$slotServiceIdSelect = in_array('service_id', $slotColumns, true) ? 'service_id' : '0 AS service_id';
$slotRows = \app\Core\Database::fetchAll(
    "SELECT id, weekday, start_time, end_time, slot_minutes, service_mode, store_id, {$slotServiceIdSelect}
     FROM counselor_slots WHERE counselor_id=? ORDER BY weekday, start_time, id",
    [$slotCounselorId]
);
$slots = $slotRows;
$associationColumns = array_column(\app\Core\Database::fetchAll('SHOW COLUMNS FROM counselor_services'), 'Field');
$associationPriceSelect = in_array('price_cents', $associationColumns, true) ? 'cs.price_cents' : '0 AS price_cents';
$slotServices = \app\Core\Database::fetchAll(
    "SELECT s.id, s.name, {$associationPriceSelect}, s.price_from, s.price_to
     FROM counselor_services cs JOIN services s ON s.id=cs.service_id
     WHERE cs.counselor_id=? AND s.status=1 ORDER BY s.sort, s.id",
    [$slotCounselorId]
);
$storeNames = [];
try {
    foreach (\app\Core\Database::fetchAll('SELECT id, name FROM stores WHERE status=1 ORDER BY sort, id') as $slotStore) {
        $storeNames[(int)$slotStore['id']] = (string)$slotStore['name'];
    }
} catch (\Throwable $slotStoreError) {
    $storeNames = [];
}
?>
<div class="dash-grid">
  <div class="panel">
    <h2>每周可约时段</h2>
    <form method="post" action="/admin/counselor-home/schedule">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="slots">
      <?php require __DIR__ . '/../partials/counselor-slot-editor.php'; ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit">保存时段</button></div>
    </form>
  </div>

  <div class="panel">
    <h2>例外日期（停诊 / 限定开诊）</h2>
    <form method="post" action="/admin/counselor-home/schedule" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="override">
      <label class="field"><span class="label">日期</span><input type="date" name="date" required></label>
      <label class="field"><span class="label">类型</span>
        <select name="status"><option value="closed">停诊（当天不可约）</option><option value="open">限定开诊</option></select>
      </label>
      <label class="field"><span class="label">开始（限定开诊时填）</span><input type="time" name="start_time"></label>
      <label class="field"><span class="label">结束</span><input type="time" name="end_time"></label>
      <label class="field"><span class="label">备注</span><input type="text" name="note" maxlength="190" placeholder="如：外出学习"></label>
      <button class="btn btn-primary" type="submit">保存例外</button>
    </form>
    <?php if ($overrides): ?>
    <table class="table" style="margin-top:10px">
      <tr><th>日期</th><th>类型</th><th>时段</th><th>备注</th><th>操作</th></tr>
      <?php foreach ($overrides as $o): ?>
      <tr>
        <td><b><?= e((string)$o['date']) ?></b></td>
        <td><?php $ovSt = (string)$o['status']; ?>
            <?= in_array($ovSt, ['closed','off'], true) ? '<span class="chip err-text">停诊</span>' : '<span class="chip ok-text">限定开诊</span>' ?></td>
        <td class="muted"><?= e(trim((string)$o['start_time'] . ' - ' . (string)$o['end_time'], ' -') ?: '—') ?></td>
        <td class="muted"><?= e((string)$o['note']) ?></td>
        <td>
          <form method="post" action="/admin/counselor-home/schedule" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="act" value="override-del"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <button class="btn btn-sm btn-ghost" type="submit">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <h2>项目价格与分时定价</h2>
  <p class="hint">先设置本咨询师的项目默认价；某天/某个时段需要不同价格时，再填下方项目专属分时价。留空沿用默认价，0 表示不设限制。</p>
  <form method="post" action="/admin/counselor-home/schedule">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="fee">
    <?php if (!empty($slotServices)): ?>
    <h3>项目默认价</h3>
    <table class="table">
      <thead><tr><th>项目</th><th>全站参考价</th><th>本咨询师价格（元/次）</th></tr></thead>
      <tbody>
      <?php foreach ($slotServices as $service): ?>
      <tr>
        <td><?= e((string)$service['name']) ?></td>
        <td><?= e(\app\Services\BookingPricing::formatRange($service['price_from'] ?? null, $service['price_to'] ?? null)) ?></td>
        <td><input type="number" name="service_prices[<?= (int)$service['id'] ?>]" value="<?= (int)($service['price_cents'] ?? 0) > 0 ? e(number_format((int)$service['price_cents'] / 100, 2, '.', '')) : '' ?>" step="0.01" min="0" style="width:120px"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="muted">尚未关联服务项目，请联系机构管理员在咨询师资料中关联。</p><?php endif; ?>

    <h3 style="margin-top:18px">项目专属分时价</h3>
    <table class="table">
      <thead><tr><th>星期</th><th>时段</th><th>项目</th><th>分时价格（元/次）</th></tr></thead>
      <tbody>
      <?php
      $week = [1=>'周一',2=>'周二',3=>'周三',4=>'周四',5=>'周五',6=>'周六',7=>'周日'];
      $feeLookup = [];
      foreach (($feeArr ?? []) as $f) {
          $feeLookup[(int)($f['weekday'] ?? 0) . '|' . (string)($f['slot'] ?? '') . '|' . (int)($f['service_id'] ?? 0)] = (int)($f['price_cents'] ?? 0);
      }
      $serviceNames = [0 => '通用（未选项目）'];
      foreach ($slotServices as $service) $serviceNames[(int)$service['id']] = (string)$service['name'];
      $feeOffers = [];
      $feeSeen = [];
      foreach ($slots as $slot) {
          $slotServiceId = (int)($slot['service_id'] ?? 0);
          $offerIds = $slotServiceId > 0 ? [$slotServiceId] : array_keys($serviceNames);
          foreach ($offerIds as $offerId) {
              $key = (int)$slot['weekday'] . '|' . substr((string)$slot['start_time'], 0, 5) . '|' . (int)$offerId;
              if (isset($feeSeen[$key])) continue;
              $feeSeen[$key] = true;
              $feeOffers[] = $slot + ['offer_service_id' => (int)$offerId, 'fee_key' => $key];
          }
      }
      foreach ($feeOffers as $offerIndex => $offer):
          $feeCents = $feeLookup[$offer['fee_key']] ?? 0;
      ?>
      <tr>
        <td><?= e($week[(int)$offer['weekday']] ?? '') ?></td>
        <td><?= e(substr((string)$offer['start_time'],0,5)) ?> - <?= e(substr((string)$offer['end_time'],0,5)) ?></td>
        <td>
          <?= e($serviceNames[$offer['offer_service_id']] ?? '项目待定') ?>
          <input type="hidden" name="fee_overrides[<?= $offerIndex ?>][weekday]" value="<?= (int)$offer['weekday'] ?>">
          <input type="hidden" name="fee_overrides[<?= $offerIndex ?>][slot]" value="<?= e(substr((string)$offer['start_time'],0,5)) ?>">
          <input type="hidden" name="fee_overrides[<?= $offerIndex ?>][service_id]" value="<?= (int)$offer['offer_service_id'] ?>">
        </td>
        <td><input type="number" name="fee_overrides[<?= $offerIndex ?>][price]" value="<?= $feeCents > 0 ? e(number_format($feeCents / 100, 2, '.', '')) : '' ?>" step="0.01" min="0" style="width:120px"></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($feeOffers)): ?><tr><td colspan="4" class="empty">请在上方先设置可约时段</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if (!empty($slots) || !empty($slotServices)): ?><button class="btn btn-primary" type="submit">保存价格</button><?php endif; ?>
  </form>
  <p class="hint">优先级：项目专属分时价 → 本咨询师项目默认价 → 通用分时价 → 全站服务价。未选项目时才使用咨询师通用收费。</p>
</div>

<div class="panel" style="margin-top:16px">
  <h2>请假管理</h2>
  <form method="post" action="/admin/counselor-home/schedule">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="leave">
    <label class="field switch-field"><span class="label">请假（暂停接单）</span>
      <label class="switch"><input type="checkbox" name="on_leave" value="1" <?= (int)($onLeave ?? 0) ? 'checked' : '' ?>><i></i></label>
    </label>
    <label class="field"><span class="label">请假说明</span>
      <input type="text" name="leave_note" value="<?= e($leaveNote ?? '') ?>" placeholder="如：外出培训 3 天">
    </label>
    <button class="btn btn-primary" type="submit"><?= (int)($onLeave ?? 0) ? '恢复接单' : '标记请假' ?></button>
  </form>
  <p class="hint">请假后前台不可选择该咨询师的新预约；已确认预约不受影响。</p>
</div>

<div class="panel" style="margin-top:16px">
  <h2>按日期请假</h2>
  <p class="hint">请假优先于每周排班与例外开诊；只影响当前咨询师的预约。</p>
  <div class="dash-grid">
    <form method="post" action="/admin/counselor-home/schedule">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="leave-range">
      <input type="hidden" name="redirect" value="schedule">
      <label class="field"><span class="label">开始日期</span><input type="date" name="start_date" required value="<?= e(date('Y-m-d')) ?>"></label>
      <label class="field"><span class="label">结束日期</span><input type="date" name="end_date" required value="<?= e(date('Y-m-d')) ?>"></label>
      <label class="field"><span class="label">请假原因</span><input type="text" name="note" maxlength="120" placeholder="例如：年假、培训、出差"></label>
      <button class="btn btn-primary" type="submit">新增请假</button>
    </form>
    <div>
      <?php if (empty($leaveRanges)): ?>
        <p class="muted">当前没有未来的请假记录。</p>
      <?php else: foreach ($leaveRanges as $lv): ?>
        <div class="quick" style="display:flex;justify-content:space-between;gap:10px;align-items:center">
          <div><b><?= e((string)$lv['start_date']) ?> → <?= e((string)$lv['end_date']) ?></b><div class="muted"><?= e((string)($lv['note'] ?? '')) ?></div></div>
          <form method="post" action="/admin/counselor-home/schedule" onsubmit="return confirm('删除这条请假记录？')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="leave-range-del">
            <input type="hidden" name="redirect" value="schedule">
            <input type="hidden" name="id" value="<?= (int)$lv['id'] ?>">
            <button class="btn btn-sm btn-ghost" type="submit">删除</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <h2>门店与咨询方式</h2>
  <p class="hint">面对面咨询会使用排班门店；门店ID为0时沿用咨询师归属门店。视频、电话咨询不要求门店。</p>
  <?php if (empty($storeNames)): ?>
    <p class="muted">当前没有启用门店，面对面预约将由机构安排。</p>
  <?php else: ?>
    <div class="chips">
      <?php foreach ($storeNames as $sid => $sname): ?><span class="chip"><?= (int)$sid ?> · <?= e((string)$sname) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

