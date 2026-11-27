<?php
/** 共享咨询师排班编辑器
 * @var array $slotRows 每行可含 id/weekday/start_time/end_time/slot_minutes/service_mode/store_id/service_id
 * @var array $slotServices 关联项目，每项含 id/name/price_cents/price_from/price_to
 * @var array $storeNames 门店 id => name
 */
$slotRows = is_array($slotRows ?? null) ? $slotRows : [];
$slotServices = is_array($slotServices ?? null) ? $slotServices : [];
$storeNames = is_array($storeNames ?? null) ? $storeNames : [];

static $counselorSlotEditorSeq = 0;
$counselorSlotEditorSeq++;
$slotEditorId = 'counselor-slot-editor-' . $counselorSlotEditorSeq;
$slotWeekdays = [1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日'];
$slotModes = ['all' => '线上 + 线下', 'offline' => '仅面对面', 'online' => '仅视频 / 电话'];
$slotServiceById = [];
foreach ($slotServices as $slotService) {
    $slotServiceById[(int)($slotService['id'] ?? 0)] = $slotService;
}
$slotPriceLabel = static function (array $service): string {
    $cents = (int)($service['price_cents'] ?? 0);
    if ($cents > 0) return '¥' . number_format($cents / 100, 2);
    $from = $service['price_from'] ?? null;
    if ($from === null || $from === '' || (float)$from <= 0) return '面议';
    $label = '¥' . number_format((float)$from, 2);
    $to = $service['price_to'] ?? null;
    if ($to !== null && $to !== '' && (float)$to > (float)$from) {
        $label .= '–' . number_format((float)$to, 2);
    }
    return $label;
};
$slotTimeValue = static function ($value): string {
    $value = substr(trim((string)$value), 0, 5);
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
};
$renderSlotRow = static function (array $slot, string $index) use (
    $slotWeekdays, $slotModes, $slotServiceById, $slotPriceLabel, $slotTimeValue, $storeNames
): void {
    $name = static function (string $field) use ($index): string {
        return 'slots[' . $index . '][' . $field . ']';
    };
    $weekday = (int)($slot['weekday'] ?? 1);
    if ($weekday < 1 || $weekday > 7) $weekday = 1;
    $minutes = (int)($slot['slot_minutes'] ?? 50);
    if ($minutes < 10 || $minutes > 240) $minutes = 50;
    $mode = (string)($slot['service_mode'] ?? 'all');
    if (!isset($slotModes[$mode])) $mode = 'all';
    $storeId = max(0, (int)($slot['store_id'] ?? 0));
    $serviceId = max(0, (int)($slot['service_id'] ?? 0));
    $rowId = (int)($slot['id'] ?? 0);
    $control = static function (string $field, string $type, string $label, string $value, string $extra = '') use ($name): string {
        return '<input class="slot-control" type="' . $type . '" name="' . e($name($field)) . '" value="' . e($value) . '" aria-label="' . e($label) . '" ' . $extra . '>';
    };
    ?>
    <tr data-slot-row>
      <td>
        <?php if ($rowId > 0 && $index !== '__INDEX__'): ?>
        <input type="hidden" name="<?= e($name('id')) ?>" value="<?= $rowId ?>">
        <?php endif; ?>
        <select class="slot-control" name="<?= e($name('weekday')) ?>" aria-label="星期">
          <?php foreach ($slotWeekdays as $value => $label): ?>
          <option value="<?= $value ?>" <?= $weekday === $value ? 'selected' : '' ?>><?= $value ?> · <?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><?= $control('start_time', 'time', '开始时间', $slotTimeValue($slot['start_time'] ?? '')) ?></td>
      <td><?= $control('end_time', 'time', '结束时间', $slotTimeValue($slot['end_time'] ?? '')) ?></td>
      <td><?= $control('slot_minutes', 'number', '单次咨询分钟', (string)$minutes, 'min="10" max="240" step="1" inputmode="numeric"') ?></td>
      <td>
        <select class="slot-control" name="<?= e($name('service_mode')) ?>" aria-label="咨询方式" data-slot-mode>
          <?php foreach ($slotModes as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $mode === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select class="slot-control" name="<?= e($name('store_id')) ?>" aria-label="门店" data-slot-store>
          <option value="0" <?= $storeId === 0 ? 'selected' : '' ?>>0 · 沿用咨询师归属门店</option>
          <?php if ($storeId > 0 && !isset($storeNames[$storeId])): ?>
          <option value="<?= $storeId ?>" selected>门店 #<?= $storeId ?>（已停用或不可用）</option>
          <?php endif; ?>
          <?php foreach ($storeNames as $id => $storeName): ?>
          <option value="<?= (int)$id ?>" <?= $storeId === (int)$id ? 'selected' : '' ?>><?= (int)$id ?> · <?= e((string)$storeName) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select class="slot-control" name="<?= e($name('service_id')) ?>" aria-label="服务项目">
          <option value="0" <?= $serviceId === 0 ? 'selected' : '' ?>>0 · 该咨询师全部已关联项目</option>
          <?php if ($serviceId > 0 && !isset($slotServiceById[$serviceId])): ?>
          <option value="<?= $serviceId ?>" selected>项目 #<?= $serviceId ?>（已停用或未关联）</option>
          <?php endif; ?>
          <?php foreach ($slotServiceById as $id => $service): ?>
          <?php if ((int)$id <= 0) continue; ?>
          <option value="<?= (int)$id ?>" <?= $serviceId === (int)$id ? 'selected' : '' ?>><?= e((string)$service['name']) ?>（<?= e($slotPriceLabel($service)) ?>）</option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><button class="btn btn-sm btn-ghost" type="button" data-slot-remove>删除</button></td>
    </tr>
    <?php
};
?>
<style>
.counselor-slot-editor, .counselor-slot-editor * { box-sizing: border-box; }
.counselor-slot-editor { min-width: 0; }
.counselor-slot-editor__scroll { margin-top: 6px; border: 1px solid var(--border); border-radius: 8px; background: #fff; }
.counselor-slot-editor__table { min-width: 1120px; margin: 0; }
.counselor-slot-editor__table th { white-space: nowrap; }
.counselor-slot-editor__table td { vertical-align: middle; }
.counselor-slot-editor__table th:nth-child(1) { width: 104px; }
.counselor-slot-editor__table th:nth-child(2), .counselor-slot-editor__table th:nth-child(3) { width: 132px; }
.counselor-slot-editor__table th:nth-child(4) { width: 118px; }
.counselor-slot-editor__table th:nth-child(5) { width: 154px; }
.counselor-slot-editor__table th:nth-child(6) { width: 210px; }
.counselor-slot-editor__table th:nth-child(7) { width: 270px; }
.counselor-slot-editor__table th:nth-child(8) { width: 76px; }
.counselor-slot-editor .slot-control { display: block; width: 100%; min-width: 0; padding: 7px 8px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 13px; background: #fff; color: var(--text); }
.counselor-slot-editor .slot-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, .12); }
.counselor-slot-editor__actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
.counselor-slot-editor__actions .hint { margin-top: 0; }
</style>
<div class="field field-full counselor-slot-editor" id="<?= e($slotEditorId) ?>">
  <span class="label">可预约时段（每行一条）</span>
  <div class="table-wrap counselor-slot-editor__scroll">
    <table class="table counselor-slot-editor__table">
      <thead>
      <tr><th>星期</th><th>开始时间</th><th>结束时间</th><th>单次分钟</th><th>咨询方式</th><th>门店</th><th>服务项目</th><th>操作</th></tr>
      </thead>
      <tbody data-slot-rows>
      <?php foreach ($slotRows as $slotIndex => $slotRow): ?>
        <?php $renderSlotRow(is_array($slotRow) ? $slotRow : [], (string)$slotIndex); ?>
      <?php endforeach; ?>
      <?php $renderSlotRow([], (string)count($slotRows)); ?>
      </tbody>
    </table>
  </div>
  <div class="counselor-slot-editor__actions">
    <button class="btn btn-sm btn-ghost" type="button" data-slot-add>添加时段</button>
    <span class="hint">单次时长 10–240 分钟；项目 0 表示该咨询师全部已关联项目；线上咨询忽略门店。空白行不会保存，保存后实时生效。</span>
  </div>
  <noscript><span class="hint">JavaScript 未启用：仍可编辑已有时段和下方空白行。</span></noscript>
  <template data-slot-template>
    <?php $renderSlotRow([], '__INDEX__'); ?>
  </template>
</div>
<script>
(function () {
  var root = document.getElementById(<?= json_encode($slotEditorId) ?>);
  if (!root) return;
  var body = root.querySelector('[data-slot-rows]');
  var template = root.querySelector('template[data-slot-template]');
  var addButton = root.querySelector('[data-slot-add]');

  function renumberRows() {
    Array.prototype.forEach.call(body.querySelectorAll('[data-slot-row]'), function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/slots\[[^\]]+\]\[/, 'slots[' + index + '][');
      });
    });
  }

  function addRow() {
    var index = body.querySelectorAll('[data-slot-row]').length;
    var fragment = template.content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach(function (field) {
      field.name = field.name.replace('__INDEX__', String(index));
    });
    body.appendChild(fragment);
    renumberRows();
  }

  renumberRows();
  addButton.addEventListener('click', addRow);
  body.addEventListener('click', function (event) {
    var button = event.target.closest('[data-slot-remove]');
    if (!button) return;
    var row = button.closest('[data-slot-row]');
    if (!row) return;
    row.remove();
    if (!body.querySelector('[data-slot-row]')) addRow();
    renumberRows();
  });
  root.addEventListener('change', function (event) {
    if (!event.target.matches('[data-slot-mode]') || event.target.value !== 'online') return;
    var store = event.target.closest('[data-slot-row]').querySelector('[data-slot-store]');
    if (store) store.value = '0';
  });
}());
</script>
