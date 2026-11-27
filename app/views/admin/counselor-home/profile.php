<?php /** 咨询师工作台 · 我的资料与价格 */ ?>
<?php if ($pending): ?>
  <div class="alert alert-info">资质资料已于 <?= e(dt($pending['submitted_at'])) ?> 提交，正在等待机构审核。审核通过前，公开档案仍显示上一版已发布内容。</div>
<?php elseif ($latest && $latest['status'] === 'rejected'): ?>
  <div class="alert alert-warning">上次资质资料未通过：<?= e($latest['review_note'] ?: '请修改后重新提交') ?></div>
<?php endif; ?>
<form class="form card" method="post" action="/admin/counselor-home/profile" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="form-grid">
    <label class="field"><span class="label">职称/头衔</span>
      <input type="text" name="title" maxlength="80" value="<?= e((string)$me['title']) ?>" placeholder="国家二级心理咨询师">
    </label>
    <label class="field"><span class="label">咨询取向</span>
      <input type="text" name="orientation" maxlength="120" value="<?= e((string)$me['orientation']) ?>" placeholder="人本存在 / CBT">
    </label>
    <label class="field"><span class="label">擅长方向（顿号分隔）</span>
      <input type="text" name="specialties" maxlength="190" value="<?= e((string)$me['specialties']) ?>" placeholder="情绪压力、青少年、亲子关系">
    </label>
    <label class="field"><span class="label">标签（列表筛选，顿号分隔）</span>
      <input type="text" name="tags" maxlength="190" value="<?= e((string)$me['tags']) ?>">
    </label>
    <label class="field"><span class="label">平时价格（元起）</span>
      <input type="number" name="fee_from" step="0.01" min="0" value="<?= e((string)(isset($me['fee_from']) ? number_format((int)$me['fee_from'] / 100, 2, '.', '') : '')) ?>">
    </label>
    <label class="field"><span class="label">价格上限（元，可留空）</span>
      <input type="number" name="fee_to" step="0.01" min="0" value="<?= e((string)(isset($me['fee_to']) && $me['fee_to'] ? number_format((int)$me['fee_to'] / 100, 2, '.', '') : '')) ?>">
    </label>
    <label class="field"><span class="label">计费单位</span>
      <input type="text" name="fee_unit" maxlength="20" value="<?= e((string)$me['fee_unit']) ?>" placeholder="60 分钟/次">
    </label>
    <label class="field"><span class="label">累计咨询时长（小时）</span>
      <input type="number" name="hours" min="0" step="1" value="<?= (int)$me['hours'] ?>">
    </label>
    <label class="field"><span class="label">咨询语言</span>
      <input type="text" name="languages" maxlength="100" value="<?= e((string)$me['languages']) ?>" placeholder="普通话 / 英语">
    </label>
      <label class="field"><span class="label">手机号（短信提醒）</span>
        <input type="tel" name="phone" value="<?= e((string)($me['phone'] ?? '')) ?>" maxlength="11" placeholder="接收预约提醒短信">
      </label>
    <label class="field field-full"><span class="label">头像</span>
      <div class="img-pick" data-name="avatar" data-crop="square" data-upload="/admin/counselor-home/avatar/upload">
        <input type="text" name="avatar" value="<?= e((string)$me['avatar']) ?>" placeholder="上传或粘贴 /uploads/… 路径">
        <button type="button" class="btn btn-sm btn-ghost js-crop">上传并裁剪</button>
        <button type="button" class="btn btn-sm btn-ghost js-recrop" <?= $me['avatar'] ? '' : 'hidden' ?>>重新裁剪</button>
        <img class="img-preview" src="<?= e((string)$me['avatar']) ?>" alt="当前头像" <?= $me['avatar'] ? '' : 'hidden' ?>>
        <span class="hint">拖拽调整位置，缩放或旋转后应用；输出 960×960，原图不会被覆盖。</span>
      </div>
    </label>
    <label class="field field-full"><span class="label">个人简介</span>
      <textarea class="richtext" name="introduction" rows="6" id="ed-introduction"><?= e((string)$me['introduction']) ?></textarea>
      <div class="rt-toolbar">
        <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
        <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
        <button type="button" data-cmd="formatBlock" data-val="h2" title="二级标题">H2</button>
        <button type="button" data-cmd="formatBlock" data-val="p" title="正文段落">P</button>
        <button type="button" data-cmd="insertUnorderedList" title="无序列表">• 列表</button>
        <button type="button" data-cmd="insertImage" title="从媒体库插图">🖼 插图</button>
        <button type="button" data-cmd="removeFormat" title="清除所选格式">✕ 格式</button>
      </div>
    </label>
    <label class="field field-full"><span class="label">教育背景</span>
      <textarea name="education" rows="3" maxlength="500"><?= e((string)$me['education']) ?></textarea>
    </label>
    <label class="field field-full"><span class="label">从业经历</span>
      <textarea name="experience" rows="3" maxlength="500"><?= e((string)$me['experience']) ?></textarea>
    </label>
    <label class="field field-full"><span class="label">咨询风格</span>
      <textarea name="style" rows="3" maxlength="500"><?= e((string)$me['style']) ?></textarea>
    </label>
    <label class="field field-full"><span class="label">资质与注册信息（自述）</span>
      <textarea name="credentials" rows="3" maxlength="2000" <?= $pending ? 'readonly' : '' ?>><?= e((string)($draft['credentials'] ?? '')) ?></textarea>
      <span class="hint">仅存为草稿，审核通过后公开，不自动成为平台核验。当前公开版本状态：<?= e($trust['verifications']['credentials']['label']) ?></span>
    </label>
    <label class="field field-full"><span class="label">督导与专业支持（自述）</span>
      <textarea name="supervision" rows="3" maxlength="2000" <?= $pending ? 'readonly' : '' ?>><?= e((string)($draft['supervision'] ?? '')) ?></textarea>
      <span class="hint">仅存为草稿，审核通过后公开，不自动成为平台核验。当前公开版本状态：<?= e($trust['verifications']['supervision']['label']) ?></span>
    </label>
    <label class="field field-full"><span class="label">个人体验与自我觉察（自述）</span>
      <textarea name="personal_therapy" rows="3" maxlength="2000" <?= $pending ? 'readonly' : '' ?>><?= e((string)($draft['personal_therapy'] ?? '')) ?></textarea>
      <span class="hint">只填写愿意公开的内容；服务对象与不适合议题由机构管理员维护。</span>
    </label>
    <div class="field field-full">
      <span class="label">资质证明附件（私有）</span>
      <div id="evidenceList" class="evidence-list">
        <?php foreach ($attachments as $attachment): ?>
          <div class="evidence-item" data-attachment-id="<?= (int)$attachment['id'] ?>">
            <a href="/admin/counselor-home/evidence/<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener"><?= e($attachment['original_name']) ?></a>
            <span class="muted"><?= number_format(((int)$attachment['size']) / 1024, 1) ?> KB</span>
            <?php if (!$pending): ?><button type="button" class="btn-sm btn-text js-evidence-remove">移除</button><?php endif; ?>
            <?php if (!$pending): ?><input type="hidden" name="draft_attachment_ids[]" value="<?= (int)$attachment['id'] ?>"><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$pending): ?>
        <input type="file" id="evidenceFile" accept=".pdf,.jpg,.jpeg,.png" <?= $pending ? 'disabled' : '' ?>>
        <button type="button" class="btn btn-sm btn-ghost" id="evidenceUpload">上传证明</button>
        <span class="hint">支持 PDF、JPG、PNG，单个不超过 10MB；文件仅限本人和机构管理员下载。请勿上传证件号码等不必要信息。</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $pending ? '保存其他资料' : '保存草稿' ?></button></div>
  <p class="hint">资质、注册与督导信息保存后不会立即公开；提交审核通过后才更新前台咨询师页。昵称与手机号归属会员体系，如需修改请联系管理员。</p>
</form>
<?php if (!$pending): ?>
<form class="card form-actions" method="post" action="/admin/counselor-home/profile/submit">
  <?= csrf_field() ?>
  <button class="btn btn-primary" type="submit">提交资质资料审核</button>
  <span class="hint">提交后不可修改，机构管理员通过后才会公开。</span>
</form>
<?php endif; ?>
<script>
(function () {
  var fileInput = document.getElementById('evidenceFile');
  var uploadButton = document.getElementById('evidenceUpload');
  var list = document.getElementById('evidenceList');
  if (!fileInput || !uploadButton || !list) return;
  uploadButton.addEventListener('click', function () {
    if (!fileInput.files.length) { fileInput.click(); return; }
    uploadButton.disabled = true;
    uploadButton.textContent = '上传中…';
    var fd = new FormData();
    fd.append('file', fileInput.files[0]);
    fd.append('_token', CSRF_TOKEN);
    fetch('/admin/counselor-home/evidence/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.msg || '上传失败');
        var item = document.createElement('div');
        item.className = 'evidence-item';
        item.setAttribute('data-attachment-id', d.attachment.id);
        var link = document.createElement('a');
        link.href = '/admin/counselor-home/evidence/' + d.attachment.id;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = d.attachment.name;
        var size = document.createElement('span');
        size.className = 'muted';
        size.textContent = (d.attachment.size / 1024).toFixed(1) + ' KB';
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn-sm btn-text js-evidence-remove';
        remove.textContent = '移除';
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'draft_attachment_ids[]';
        hidden.value = d.attachment.id;
        item.appendChild(link); item.appendChild(size); item.appendChild(remove); item.appendChild(hidden);
        list.appendChild(item);
        fileInput.value = '';
      })
      .catch(function (err) { alert(err.message || '上传失败，请重试'); })
      .finally(function () { uploadButton.disabled = false; uploadButton.textContent = '上传证明'; });
  });
  fileInput.addEventListener('change', function () { if (fileInput.files.length) uploadButton.click(); });
  list.addEventListener('click', function (event) {
    var button = event.target.closest('.js-evidence-remove');
    if (!button) return;
    var item = button.closest('.evidence-item');
    if (item) item.remove();
  });
})();
</script>
