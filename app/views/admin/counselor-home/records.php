<?php /** 个案 · 咨询记录（P1-B） */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2><?= e((string)$case['title']) ?> <span class="muted" style="font-weight:400;font-size:13px"><?= e((string)$case['case_no']) ?></span></h2>
    <div class="toolbar">
      <span class="chip">状态 <b><?= e(\app\Services\CasePolicy::statusLabel((string)$case['status'])) ?></b></span>
      <span class="chip">会次 <?= (int)$case['session_count'] ?></span>
      <span class="chip">记录 <?= count($rows) ?></span>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>">← 返回个案</a>
    </div>
  </div>
</div>

<?php if ($canWrite): ?>
<div class="panel" style="margin-top:14px">
  <h2><?= $edit ? '编辑记录 #' . (int)$edit['id'] . '（第 ' . (int)$edit['version'] . ' 版）' : '新建咨询记录' ?></h2>
  <form class="form" method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records">
    <?= csrf_field() ?>
    <input type="hidden" name="record_id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <input type="hidden" name="amends_id" value="<?= (int)($edit['amends_id'] ?? 0) ?>">
    <div class="form-grid">
      <label class="field"><span class="label">记录类型</span>
        <select name="record_type">
          <?php foreach ($recordTypes as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= (string)($edit['record_type'] ?? 'summary') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">关联会次</span>
        <select name="session_id">
          <option value="">不关联</option>
          <?php foreach ($sessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>"<?= (int)($edit['session_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>>#<?= (int)$s['seq'] ?> · <?= e((string)($s['held_at'] ?? '')) ?><?= $s['topic'] ? ' · ' . e((string)$s['topic']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full"><span class="label">标题 <em>*</em></span>
        <input type="text" name="title" required maxlength="120" value="<?= e((string)($edit['title'] ?? '')) ?>" placeholder="如：第 3 次 · 回避模式与作业回顾">
      </label>
      <label class="field field-full"><span class="label">正文 <em>*</em></span>
        <textarea class="richtext" name="content" rows="12" id="ed-record"><?= e((string)($edit['content'] ?? '')) ?></textarea>
        <div class="rt-toolbar">
          <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
          <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
          <button type="button" data-cmd="formatBlock" data-val="h3" title="小标题">H3</button>
          <button type="button" data-cmd="formatBlock" data-val="p" title="正文段落">P</button>
          <button type="button" data-cmd="insertUnorderedList" title="无序列表">• 列表</button>
          <button type="button" data-cmd="insertOrderedList" title="有序列表">1. 列表</button>
        </div>
        <span class="hint">SOAP 写法建议：主观（S）/ 客观（O）/ 评估（A）/ 计划（P）四段小标题。请勿写入可识别身份的信息。</span>
      </label>
      <label class="field switch-field"><span class="label">对咨客可见</span>
        <label class="switch"><input type="checkbox" name="visible" value="1" <?= !empty($edit['visible']) ? 'checked' : '' ?>><i></i></label>
        <span class="hint">默认不可见。仅勾选后咨客才能在会员中心看到（建议只公开「小结/作业」类内容）</span>
      </label>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= $edit ? '保存（生成新版本）' : '保存草稿' ?></button>
      <?php if ($edit): ?><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records">取消编辑</a><?php endif; ?>
    </div>
  </form>
  <p class="hint">草稿可反复修改，每次保存都会留一个版本快照；点「锁定」后才算定稿，锁定后不可改，只能另开修订。</p>
</div>
<?php else: ?>
<div class="panel" style="margin-top:14px"><p class="muted">个案已<?= e(\app\Services\CasePolicy::statusLabel((string)$case['status'])) ?>，不能新增或修改咨询记录；历史记录仍可查阅。</p></div>
<?php endif; ?>

<div class="panel" style="margin-top:14px">
  <h2>记录列表（<?= count($rows) ?>）</h2>
  <?php if ($rows): ?>
  <table class="table">
    <tr><th>类型</th><th>标题</th><th>会次</th><th>版本</th><th>状态</th><th>对咨客</th><th>更新</th><th>操作</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($recordTypes[(string)$r['record_type']] ?? (string)$r['record_type']) ?></td>
      <td><b><?= e((string)$r['title']) ?></b><?= !empty($r['amends_id']) ? ' <span class="muted">（修订 #' . (int)$r['amends_id'] . '）</span>' : '' ?></td>
      <td class="muted"><?= !empty($r['session_seq']) ? '#' . (int)$r['session_seq'] : '—' ?></td>
      <td>v<?= (int)$r['version'] ?></td>
      <td><?= (string)$r['status'] === 'locked' ? '<b>已锁定</b>' : '草稿' ?></td>
      <td class="muted"><?= !empty($r['visible']) ? '可见' : '不可见' ?></td>
      <td class="muted"><?= e((string)($r['updated_at'] ?? $r['created_at'])) ?></td>
      <td>
        <a class="btn btn-sm" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records/<?= (int)$r['id'] ?>">查看</a>
        <?php if ($canWrite && (string)$r['status'] === 'draft'): ?>
        <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records?edit=<?= (int)$r['id'] ?>">编辑</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">还没有咨询记录。</p>
  <?php endif; ?>
  <p class="hint">保密提醒：咨询记录属敏感个人信息，查阅、创建、修改、锁定、修订均写入审计日志（哈希链）。</p>
</div>
