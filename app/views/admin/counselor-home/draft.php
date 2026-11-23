<?php /** 个案 · AI 记录草稿（P1-C） */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2><?= e((string)$case['title']) ?> <span class="muted" style="font-weight:400;font-size:13px"><?= e((string)$case['case_no']) ?></span></h2>
    <div class="toolbar">
      <span class="chip">AI 草稿 <?= $available ? '<b class="ok">可用</b>' : '未启用' ?></span>
      <span class="chip">外发策略 <?= $scope === 'none' ? '零外发' : '仅对话（脱敏后）' ?></span>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>">← 返回个案</a>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records">咨询记录</a>
    </div>
    <p class="hint">AI 只负责把要点整理成 SOAP 草稿；草稿必须由您核对、修改并保存，锁定才生效。生成前按设置脱敏，库里只存脱敏后的输入。</p>
  </div>
</div>

<?php if (!$available): ?>
<div class="panel" style="margin-top:14px">
  <p class="muted"><?= e($blockReason) ?></p>
  <p class="hint">配置位置：后台「系统设置 → 咨询记录 AI 草稿」。默认关闭，需管理员显式开启；「零外发」模式下本功能不可用。</p>
</div>
<?php elseif (!$canWrite): ?>
<div class="panel" style="margin-top:14px"><p class="muted">个案已结案，不能再生成草稿。</p></div>
<?php else: ?>
<div class="panel" style="margin-top:14px">
  <h2>粘贴会谈要点 / 逐字稿</h2>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/draft">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">关联会次</span>
        <select name="session_id">
          <option value="">不关联</option>
          <?php foreach ($sessions as $s): ?>
          <option value="#<?= (int)$s['seq'] ?> · <?= e((string)($s['held_at'] ?? '')) ?>" value="<?= (int)$s['id'] ?>">#<?= (int)$s['seq'] ?> · <?= e((string)($s['held_at'] ?? '')) ?><?= $s['topic'] ? ' · ' . e((string)$s['topic']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full"><span class="label">会谈要点 <em>*</em></span>
        <textarea name="input_text" rows="10" maxlength="12000" required placeholder="例如：来访者本周睡眠 5-6 小时，入睡困难；谈到与母亲冲突时明显回避；作业完成 2/3；下次拟做角色扮演……"></textarea>
        <span class="hint">建议只写专业要点，不要粘贴姓名、单位、身份证号（即使开了脱敏，也少传为好）。</span>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">生成 SOAP 草稿（外发一次）</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($task): ?>
<div class="panel" style="margin-top:14px">
  <h2>草稿结果 #<?= (int)$task['id'] ?></h2>
  <div class="toolbar">
    <span class="chip"><?= e((string)$task['status']) ?></span>
    <span class="chip">模型 <?= e((string)($task['model'] ?: '—')) ?></span>
    <span class="chip">脱敏 <?= (int)$task['redacted_count'] ?> 处</span>
    <span class="chip">输入 <?= (int)$task['input_chars'] ?> 字</span>
  </div>
  <?php if ((string)$task['status'] === 'failed'): ?>
  <p class="err-text">生成失败：<?= e((string)$task['error']) ?></p>
  <?php elseif ((string)$task['output_text'] !== ''): ?>
  <div class="note-card" style="margin-top:12px;white-space:pre-wrap;line-height:1.9"><?= e((string)$task['output_text']) ?></div>
  <?php if ((string)$task['status'] === 'done'): ?>
  <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/draft/<?= (int)$task['id'] ?>/adopt"
        onsubmit="return confirm('将把这份草稿复制成一条咨询记录草稿（仍需您核对后锁定）。继续？');" style="margin-top:12px">
    <?= csrf_field() ?>
    <button class="btn btn-primary btn-sm" type="submit">采用为记录草稿</button>
    <span class="muted">采用后进入编辑页，可继续修改；不点锁定不算定稿。</span>
  </form>
  <?php elseif ((string)$task['status'] === 'adopted'): ?>
  <p class="hint">已采用 → 记录 #<?= (int)$task['adopted_record_id'] ?>（
    <a href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records/<?= (int)$task['adopted_record_id'] ?>">查看</a>）</p>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:14px">
  <h2>本个案的 AI 任务（<?= count($tasks) ?>）</h2>
  <?php if ($tasks): ?>
  <table class="table">
    <tr><th>#</th><th>时间</th><th>输入字数</th><th>脱敏</th><th>模型</th><th>状态</th><th>记录</th><th>操作</th></tr>
    <?php foreach ($tasks as $t): ?>
    <tr>
      <td><?= (int)$t['id'] ?></td>
      <td class="muted"><?= e((string)$t['created_at']) ?></td>
      <td><?= (int)$t['input_chars'] ?></td>
      <td><?= (int)$t['redacted_count'] ?></td>
      <td class="muted"><?= e((string)($t['model'] ?: '—')) ?></td>
      <td><?= e((string)$t['status']) ?></td>
      <td class="muted"><?= !empty($t['adopted_record_id']) ? '#' . (int)$t['adopted_record_id'] : '—' ?></td>
      <td><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/draft?task=<?= (int)$t['id'] ?>">查看</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">还没有生成过草稿。</p>
  <?php endif; ?>
  <p class="hint">每一次外发都记审计（个案、脱敏处数、操作人）。若机构要求"零外发"，在系统设置里把外发策略改为「零外发」，本页会直接停用。</p>
</div>
