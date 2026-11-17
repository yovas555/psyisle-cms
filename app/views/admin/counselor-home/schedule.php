<?php /** 咨询师工作台 · 我的排班 */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2>每周可约时段</h2>
    <form method="post" action="/admin/counselor-home/schedule">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="slots">
      <label class="field field-full"><span class="label">可约时段（每行一条：星期|开始|结束，星期 1-7，如 6|09:00|12:00）</span>
        <textarea name="slots_text" rows="5" placeholder="6|09:00|12:00&#10;6|14:00|17:00&#10;3|19:00|21:00"><?= e((string)$slotsText) ?></textarea>
        <span class="hint">这些时段决定前台预约页的可约时间；保存实时生效。咨询方式（面对面/视频/电话）共用同一套时段。</span>
      </label>
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
        <select name="status"><option value="off">停诊（当天不可约）</option><option value="on">限定开诊</option></select>
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
        <td><?= $o['status'] === 'off' ? '<span class="chip err-text">停诊</span>' : '<span class="chip ok-text">限定开诊</span>' ?></td>
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
  <h2>💰 分时定价（按每个可约时段设置费用）</h2>
  <form method="post" action="/admin/counselor-home/schedule">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="fee">
    <table class="table">
      <thead><tr><th>星期</th><th>时段</th><th>费用（元/次）</th></tr></thead>
      <tbody>
      <?php
      $week = [1=>'周一',2=>'周二',3=>'周三',4=>'周四',5=>'周五',6=>'周六',7=>'周日'];
      $feeLookup = [];
      foreach (($feeArr ?? []) as $f) $feeLookup[$f['weekday'] . '_' . $f['slot']] = $f['price_cents'] ?? 0;
      foreach (($slots ?? []) as $s):
        $feeKey = $s['weekday'] . '_' . substr((string)$s['start_time'], 0, 5);
        $feeVal = isset($feeLookup[$feeKey]) ? round($feeLookup[$feeKey] / 100, 2) : '';
      ?>
      <tr>
        <td><?= e($week[$s['weekday']] ?? '') ?></td>
        <td><?= e(substr((string)$s['start_time'],0,5)) ?> - <?= e(substr((string)$s['end_time'],0,5)) ?></td>
        <td><input type="number" name="fee_<?= (int)$s['weekday'] ?>_<?= e(substr((string)$s['start_time'],0,5)) ?>" value="<?= $feeVal ?>" step="0.01" min="0" style="width:100px"></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($slots)): ?><tr><td colspan="3" class="empty">请在上方先设置可约时段</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if (!empty($slots)): ?>
    <button class="btn btn-primary" type="submit">保存分时定价</button>
    <?php endif; ?>
  </form>
  <p class="hint">预约时按所选时段的费用自动定价（视频咨询在线支付；面对面可线下）。</p>
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

