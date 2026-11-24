<?php /** 咨询师工作台 · 照护（P1-D：危机 / 作业 / 随访） */ ?>
<?php
$openCrises = 0;
foreach ($crises as $c) { if ((string)$c['status'] === 'open') $openCrises++; }
$pendingFu = 0;
foreach ($followups as $f) { if ((string)$f['status'] === 'pending' && (string)$f['due_at'] <= date('Y-m-d')) $pendingFu++; }
$overdueHw = 0;
foreach ($homeworks as $h) {
    if ((string)$h['status'] === 'assigned' && !empty($h['due_at']) && (string)$h['due_at'] < date('Y-m-d')) $overdueHw++;
}
$levelCls = ['urgent' => 'err-text', 'high' => 'err-text'];
?>
<div class="dash-grid">
  <div class="panel">
    <h2><?= e((string)$case['title']) ?> <span class="muted" style="font-weight:400;font-size:13px"><?= e((string)$case['case_no']) ?></span></h2>
    <div class="toolbar">
      <span class="chip">状态 <b><?= e($statusLabels[(string)$case['status']] ?? (string)$case['status']) ?></b></span>
      <span class="chip<?= $openCrises > 0 ? ' err-text' : '' ?>">未闭环危机 <b><?= $openCrises ?></b></span>
      <span class="chip<?= $overdueHw > 0 ? ' err-text' : '' ?>">逾期作业 <b><?= $overdueHw ?></b></span>
      <span class="chip<?= $pendingFu > 0 ? ' err-text' : '' ?>">到期随访 <b><?= $pendingFu ?></b></span>
      <span class="chip">随访计划 <b><?= $activePlans ?></b></span>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>">← 返回个案</a>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records">咨询记录</a>
    </div>
    <p class="hint">危机事件不闭环就会一直在工作台首页提醒；关闭必须写处置结论。作业与随访只前进一步，不做回退。</p>
  </div>
</div>

<!-- ==================== 危机事件 ==================== -->
<div class="panel" style="margin-top:14px">
  <h2>危机事件（<?= count($crises) ?>）</h2>
  <?php if ($crises): ?>
  <table class="table">
    <tr><th>发现时间</th><th>级别</th><th>事件与表现</th><th>已处置</th><th>状态</th><th style="width:280px">闭环</th></tr>
    <?php foreach ($crises as $c): ?>
    <?php $open = (string)$c['status'] === 'open'; ?>
    <tr>
      <td><?= e((string)($c['occurred_at'] ?? '—')) ?></td>
      <td class="<?= e($levelCls[(string)$c['level']] ?? '') ?>"><b><?= e($levels[(string)$c['level']] ?? (string)$c['level']) ?></b></td>
      <td><?= nl2br(e((string)$c['summary'])) ?></td>
      <td class="muted"><?= $c['action_taken'] ? nl2br(e((string)$c['action_taken'])) : '—' ?></td>
      <td><?= $open ? '<b class="err-text">未闭环</b>' : '已闭环' ?>
        <?php if (!$open && !empty($c['closure_note'])): ?><div class="muted" style="font-size:12px"><?= e((string)$c['closure_note']) ?></div><?php endif; ?>
      </td>
      <td>
        <?php if ($open): ?>
        <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/crisis/<?= (int)$c['id'] ?>/close">
          <?= csrf_field() ?>
          <input type="text" name="closure_note" maxlength="500" required placeholder="处置结论（必填）" style="width:100%">
          <div class="form-actions" style="margin-top:6px"><button class="btn btn-sm" type="submit">闭环</button></div>
        </form>
        <?php else: ?>
        <span class="muted"><?= e((string)($c['closed_at'] ?? '')) ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无危机事件记录。</p>
  <?php endif; ?>

  <h3 style="margin-top:16px">登记危机事件</h3>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/crisis">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">级别 <em>*</em></span>
        <select name="level">
          <?php foreach ($levels as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $k === 'medium' ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full"><span class="label">事件与表现 <em>*</em></span>
        <textarea name="summary" rows="3" maxlength="500" required placeholder="去标识描述：如「第 5 次会谈中表达无望感，提及自伤念头，无计划」"></textarea>
      </label>
      <label class="field field-full"><span class="label">已采取的处置</span>
        <textarea name="action_taken" rows="2" maxlength="1000" placeholder="如：完成风险评估、告知紧急联系人、提供热线、约定 48 小时内回访"></textarea>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">登记并置为未闭环</button></div>
  </form>
</div>

<!-- ==================== 作业 ==================== -->
<div class="panel" style="margin-top:14px">
  <h2>作业（<?= count($homeworks) ?>）</h2>
  <?php if ($homeworks): ?>
  <table class="table">
    <tr><th>布置</th><th>标题</th><th>说明</th><th>会次</th><th>截止</th><th>状态</th><th style="width:260px">处理</th></tr>
    <?php foreach ($homeworks as $h): ?>
    <?php
      $hs = (string)$h['status'];
      $late = $hs === 'assigned' && !empty($h['due_at']) && (string)$h['due_at'] < date('Y-m-d');
    ?>
    <tr>
      <td><?= e((string)($h['assigned_at'] ?? '—')) ?></td>
      <td><b><?= e((string)$h['title']) ?></b></td>
      <td class="muted"><?= $h['content'] ? nl2br(e((string)$h['content'])) : '—' ?></td>
      <td class="muted"><?= !empty($h['session_seq']) ? '#' . (int)$h['session_seq'] : '—' ?></td>
      <td class="<?= $late ? 'err-text' : '' ?>"><?= $h['due_at'] ? e((string)$h['due_at']) . ($late ? ' · 逾期' : '') : '—' ?></td>
      <td><?= e($hwStatus[$hs] ?? $hs) ?>
        <?php if (!empty($h['feedback'])): ?><div class="muted" style="font-size:12px"><?= e((string)$h['feedback']) ?></div><?php endif; ?>
      </td>
      <td>
        <?php if ($hs === 'assigned'): ?>
        <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/homework/<?= (int)$h['id'] ?>/done">
          <?= csrf_field() ?>
          <input type="text" name="feedback" maxlength="500" placeholder="完成情况" style="width:100%">
          <div class="form-actions" style="margin-top:6px">
            <button class="btn btn-sm" type="submit" name="act" value="done">完成</button>
            <button class="btn btn-sm btn-ghost" type="submit" name="act" value="cancel">取消</button>
          </div>
        </form>
        <?php else: ?>
        <span class="muted"><?= e((string)($h['completed_at'] ?? '')) ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无作业。</p>
  <?php endif; ?>

  <?php if ($canWrite): ?>
  <h3 style="margin-top:16px">布置作业</h3>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/homework">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field field-full"><span class="label">标题 <em>*</em></span>
        <input type="text" name="title" maxlength="120" required placeholder="如：情绪日记（每天 1 次，记录触发情境与强度）">
      </label>
      <label class="field field-full"><span class="label">练习说明</span>
        <textarea name="content" rows="2" maxlength="1000" placeholder="具体做法、频次、注意事项"></textarea>
      </label>
      <label class="field"><span class="label">截止日期</span>
        <input type="date" name="due_at">
      </label>
      <label class="field"><span class="label">关联会次</span>
        <select name="session_id">
          <option value="">不关联</option>
          <?php foreach ($sessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>">#<?= (int)$s['seq'] ?> · <?= e((string)($s['held_at'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">布置</button></div>
  </form>
  <?php else: ?>
  <p class="hint">个案已<?= (string)$case['status'] === 'closed' ? '结案' : '归档' ?>，不再布置新作业；随访仍可继续。</p>
  <?php endif; ?>
</div>

<!-- ==================== 随访计划（P2-A） ==================== -->
<div class="panel" style="margin-top:14px">
  <h2>随访计划（<?= count($plans) ?>）</h2>
  <?php if ($plans): ?>
  <table class="table">
    <tr><th>计划名</th><th>间隔</th><th>次数</th><th>方式</th><th>已生成</th><th>下次</th><th>状态</th><th style="width:120px">操作</th></tr>
    <?php foreach ($plans as $p): ?>
    <?php $ps = (string)$p['status']; ?>
    <tr>
      <td><b><?= e((string)$p['title']) ?></b>
        <?php if (!empty($p['purpose'])): ?><div class="muted" style="font-size:12px"><?= e((string)$p['purpose']) ?></div><?php endif; ?>
      </td>
      <td><?= (int)$p['interval_days'] ?> 天</td>
      <td><?= (int)$p['generated_count'] ?> / <?= (int)$p['times'] ?></td>
      <td><?= e($fuChannel[(string)$p['channel']] ?? (string)$p['channel']) ?></td>
      <td><?= (int)$p['generated_count'] ?></td>
      <td><?= $ps === 'active' ? e((string)($p['next_due_at'] ?? '—')) : '—' ?></td>
      <td><?= e($planStatus[$ps] ?? $ps) ?></td>
      <td>
        <?php if ($ps === 'active'): ?>
        <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/followup-plan/<?= (int)$p['id'] ?>/stop" onsubmit="return confirm('停止后不再生成新的随访待办，已生成的保留。确认？')">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-ghost" type="submit">停止</button>
        </form>
        <?php else: ?>
        <span class="muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无随访计划。可建一个「周期性随访」，到期会自动生成随访待办。</p>
  <?php endif; ?>

  <h3 style="margin-top:16px">新建随访计划</h3>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/followup-plan">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field field-full"><span class="label">计划名</span>
        <input type="text" name="title" maxlength="120" placeholder="如：结案后随访">
      </label>
      <label class="field"><span class="label">间隔天数</span>
        <input type="number" name="interval_days" min="1" max="365" value="7">
      </label>
      <label class="field"><span class="label">随访次数</span>
        <input type="number" name="times" min="1" max="52" value="3">
      </label>
      <label class="field"><span class="label">方式</span>
        <select name="channel">
          <?php foreach ($fuChannel as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $k === 'phone' ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full"><span class="label">目的</span>
        <input type="text" name="purpose" maxlength="200" placeholder="如：评估情绪与睡眠、了解社会功能恢复">
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">建立计划</button></div>
    <p class="hint">首次随访在「间隔天数」后生成，之后每满一个间隔生成一次，直到达到次数自动完成。</p>
  </form>
</div>

<!-- ==================== 随访待办（P1-D） ==================== -->
<div class="panel" style="margin-top:14px">
  <h2>随访待办（<?= count($followups) ?>）</h2>
  <?php if ($followups): ?>
  <table class="table">
    <tr><th>计划日期</th><th>方式</th><th>目的</th><th>状态</th><th style="width:280px">处理</th></tr>
    <?php foreach ($followups as $f): ?>
    <?php
      $fs = (string)$f['status'];
      $due = $fs === 'pending' && (string)$f['due_at'] <= date('Y-m-d');
    ?>
    <tr>
      <td class="<?= $due ? 'err-text' : '' ?>"><b><?= e((string)$f['due_at']) ?></b><?= $due ? ' · 到期' : '' ?></td>
      <td><?= e($fuChannel[(string)$f['channel']] ?? (string)$f['channel']) ?></td>
      <td class="muted"><?= $f['purpose'] ? e((string)$f['purpose']) : '—' ?></td>
      <td><?= e($fuStatus[$fs] ?? $fs) ?>
        <?php if (!empty($f['result'])): ?><div class="muted" style="font-size:12px"><?= e((string)$f['result']) ?></div><?php endif; ?>
      </td>
      <td>
        <?php if ($fs === 'pending'): ?>
        <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/followup/<?= (int)$f['id'] ?>/done">
          <?= csrf_field() ?>
          <input type="text" name="result" maxlength="500" placeholder="随访结果（完成时必填）" style="width:100%">
          <div class="form-actions" style="margin-top:6px">
            <button class="btn btn-sm" type="submit" name="act" value="done">已随访</button>
            <button class="btn btn-sm btn-ghost" type="submit" name="act" value="skip">跳过</button>
          </div>
        </form>
        <?php else: ?>
        <span class="muted"><?= e((string)($f['done_at'] ?? '')) ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无随访计划。</p>
  <?php endif; ?>

  <h3 style="margin-top:16px">安排随访</h3>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/followup">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">计划日期 <em>*</em></span>
        <input type="date" name="due_at" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
      </label>
      <label class="field"><span class="label">方式</span>
        <select name="channel">
          <?php foreach ($fuChannel as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $k === 'phone' ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full"><span class="label">目的</span>
        <input type="text" name="purpose" maxlength="200" placeholder="如：结案后 2 周回访，评估情绪与睡眠">
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">安排随访</button></div>
  </form>
</div>

<p style="margin:14px 0"><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases">← 返回个案列表</a></p>
