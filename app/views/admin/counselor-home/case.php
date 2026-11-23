<?php /** 咨询师工作台 · 个案详情（P1-A） */ ?>
<?php $st = (string)$case['status']; ?>
<div class="dash-grid">
  <div class="panel">
    <h2><?= e((string)$case['title']) ?> <span class="muted" style="font-weight:400;font-size:13px"><?= e((string)$case['case_no']) ?></span></h2>
    <div class="toolbar">
      <span class="chip">状态 <b><?= e($statusLabels[$st] ?? $st) ?></b></span>
      <span class="chip<?= $st === 'active' && $case['risk_level'] === 'high' ? ' err-text' : '' ?>">风险 <?= e($riskLabels[(string)$case['risk_level']] ?? (string)$case['risk_level']) ?></span>
      <span class="chip">会次 <b><?= (int)$case['session_count'] ?></b></span>
      <span class="chip">开案 <?= e((string)($case['opened_at'] ?? '—')) ?></span>
      <?php if (!empty($case['last_session_at'])): ?><span class="chip">最近一次 <?= e((string)$case['last_session_at']) ?></span><?php endif; ?>
    </div>
    <table class="table" style="margin-top:10px">
      <tr><th style="width:120px">来访者</th><td><?= e(\app\Services\MemberService::maskPhone((string)($case['m_phone'] ?? ''))) ?><?= ($case['m_name'] ?? '') !== '' ? '（' . e((string)$case['m_name']) . '）' : '' ?></td></tr>
      <tr><th>主诉概述</th><td><?= $case['presenting'] ? nl2br(e((string)$case['presenting'])) : '<span class="muted">—</span>' ?></td></tr>
      <tr><th>目标与计划</th><td><?= $case['goals'] ? nl2br(e((string)$case['goals'])) : '<span class="muted">—</span>' ?></td></tr>
      <?php if (!empty($case['closed_at'])): ?>
      <tr><th>结案</th><td><?= e((string)$case['closed_at']) ?><?= $case['close_reason'] ? ' · ' . e((string)$case['close_reason']) : '' ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>咨询记录（<?= (int)($recordCount ?? 0) ?>）</h2>
  <div class="toolbar">
    <a class="btn btn-primary btn-sm" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records"><?= (int)($recordCount ?? 0) > 0 ? '查看 / 新增记录' : '开始写第一条记录' ?> →</a>
    <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/draft">AI 草稿</a>
    <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/care">照护（危机/作业/随访）</a>
    <span class="muted">记录可挂会次、可锁定定稿、可追溯版本；锁定后不可改，只能另开修订。</span>
  </div>
</div>

<?php if ($canWrite): ?>
<div class="panel" style="margin-top:14px">
  <h2>登记会次</h2>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/session">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">咨询日期 <em>*</em></span>
        <input type="date" name="held_at" value="<?= date('Y-m-d') ?>" required>
      </label>
      <label class="field"><span class="label">时长（分钟）</span>
        <input type="number" name="duration_min" min="5" max="600" step="5" value="50">
      </label>
      <label class="field"><span class="label">方式</span>
        <select name="method">
          <option value="面对面">面对面</option>
          <option value="视频">视频</option>
          <option value="电话">电话</option>
        </select>
      </label>
      <label class="field"><span class="label">出勤</span>
        <select name="attendance">
          <?php foreach ($attendanceLabels as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $k === 'held' ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">关联预约</span>
        <select name="booking_id">
          <option value="">不关联</option>
          <?php foreach ($bookings as $b): ?>
          <option value="<?= (int)$b['id'] ?>"><?= e((string)$b['prefer_date']) ?> <?= e((string)$b['prefer_slot']) ?> · <?= e((string)$b['code']) ?> · <?= e((string)$b['status']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full"><span class="label">本次主题</span>
        <input type="text" name="topic" maxlength="200" placeholder="一句话概括，如：第 3 次 · 回避模式与作业回顾（去标识）">
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">登记会次</button></div>
  </form>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:14px">
  <h2>会次记录（<?= count($sessions) ?>）</h2>
  <?php if ($sessions): ?>
  <table class="table">
    <tr><th>次序</th><th>日期</th><th>时长</th><th>方式</th><th>出勤</th><th>主题</th><th>关联预约</th></tr>
    <?php foreach ($sessions as $s): ?>
    <tr>
      <td>#<?= (int)$s['seq'] ?></td>
      <td><?= e((string)($s['held_at'] ?? '—')) ?></td>
      <td><?= $s['duration_min'] ? (int)$s['duration_min'] . ' 分' : '—' ?></td>
      <td><?= e((string)($s['method'] ?: '—')) ?></td>
      <td><?= e($attendanceLabels[(string)$s['attendance']] ?? (string)$s['attendance']) ?></td>
      <td class="muted"><?= $s['topic'] ? e((string)$s['topic']) : '—' ?></td>
      <td class="muted"><?= $s['booking_code'] ? e((string)$s['booking_code']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">还没有会次记录。</p>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:14px">
  <h2>个案状态</h2>
  <?php if ($st === 'active'): ?>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/close" onsubmit="return confirm('结案后不可再新增会次，且不能重开（复发请另开新案）。确定结案？');">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field field-full"><span class="label">结案原因</span>
        <input type="text" name="close_reason" maxlength="200" placeholder="如：目标达成 / 来访者中止 / 转介">
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-sm" type="submit">结案</button></div>
  </form>
  <?php elseif ($st === 'closed'): ?>
  <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/archive" onsubmit="return confirm('归档后该个案只在「已归档」中可查，确定？');">
    <?= csrf_field() ?>
    <p class="hint">个案已结案。归档后不再出现在默认列表中，数据仍保留可查。</p>
    <div class="form-actions"><button class="btn btn-sm btn-ghost" type="submit">归档个案</button></div>
  </form>
  <?php else: ?>
  <p class="muted">个案已归档，仅可查阅。</p>
  <?php endif; ?>
  <p class="hint">行为留痕：开案、登记会次、结案、归档与查阅均写入审计日志。</p>
</div>

<p style="margin:14px 0"><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases">← 返回个案列表</a></p>
