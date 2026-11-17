<?php /** 咨询师工作台 · 咨询小结与作业训练 */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2><?= $edit ? '编辑记录 #' . (int)$edit['id'] : '新建咨询小结 / 作业' ?></h2>
    <form class="form" method="post" action="/admin/counselor-home/notes">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="form-grid">
        <label class="field"><span class="label">类型</span>
          <select name="kind">
            <option value="summary" <?= ($edit['kind'] ?? '') === 'homework' ? '' : 'selected' ?>>咨询小结</option>
            <option value="homework" <?= ($edit['kind'] ?? '') === 'homework' ? 'selected' : '' ?>>作业训练</option>
          </select>
        </label>
        <label class="field"><span class="label">咨客（会员）<em>*</em></span>
          <select name="member_id" required>
            <option value="">选择咨客</option>
            <?php foreach ($members as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)($edit['member_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e(\app\Services\MemberService::maskPhone((string)$m['phone'])) ?><?= ($m['name'] ?? '') !== '' ? '（' . e((string)$m['name']) . '）' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">仅显示近 30 天与您有预约关联的咨客</span>
        </label>
        <label class="field"><span class="label">标题 <em>*</em></span>
          <input type="text" name="title" required maxlength="120" value="<?= e((string)($edit['title'] ?? '')) ?>" placeholder="如：第 3 次咨询小结 / 本周放松练习">
        </label>
        <label class="field field-full"><span class="label">正文 <em>*</em></span>
          <textarea class="richtext" name="content" rows="10" id="ed-content"><?= e((string)($edit['content'] ?? '')) ?></textarea>
          <div class="rt-toolbar">
            <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
            <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
            <button type="button" data-cmd="formatBlock" data-val="h2" title="二级标题">H2</button>
            <button type="button" data-cmd="formatBlock" data-val="p" title="正文段落">P</button>
            <button type="button" data-cmd="insertUnorderedList" title="无序列表">• 列表</button>
            <button type="button" data-cmd="insertOrderedList" title="有序列表">1. 列表</button>
            <button type="button" data-cmd="removeFormat" title="清除所选格式">✕ 格式</button>
          </div>
        </label>
        <label class="field switch-field"><span class="label">对咨客可见</span>
          <label class="switch"><input type="checkbox" name="visible" value="1" <?= (empty($edit) || !empty($edit['visible'])) ? 'checked' : '' ?>><i></i></label>
          <span class="hint">可见的记录会出现在咨客的会员中心「咨询记录」中</span>
        </label>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit">保存记录</button></div>
    </form>
    <p class="hint">保密提醒：咨询小结内容涉及来访者隐私，仅对本人可见；措辞请保持专业克制，避免诊断性表述。</p>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>咨询记录（<?= count($rows) ?>）</h2>
  <?php if ($rows): ?>
  <table class="table">
    <tr><th>类型</th><th>标题</th><th>咨客</th><th>可见</th><th>更新</th><th>操作</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $r['kind'] === 'homework' ? '📝 作业' : '📋 小结' ?></td>
      <td><?= e((string)$r['title']) ?></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)($r['m_phone'] ?? ''))) ?></td>
      <td><?= (int)$r['visible'] === 1 ? '<span class="chip ok-text">可见</span>' : '<span class="chip draft">隐藏</span>' ?></td>
      <td class="muted"><?= e(dt((string)$r['updated_at'])) ?></td>
      <td>
        <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/notes?edit=<?= (int)$r['id'] ?>">编辑</a>
        <form method="post" action="/admin/counselor-home/notes" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-ghost" type="submit"><?= (int)$r['visible'] === 1 ? '隐藏' : '公开' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无记录。</p>
  <?php endif; ?>
</div>
