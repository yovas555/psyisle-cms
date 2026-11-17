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
