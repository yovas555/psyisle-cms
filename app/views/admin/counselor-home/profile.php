<?php /** 咨询师工作台 · 我的资料与价格 */ ?>
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
    <label class="field"><span class="label">服务时间说明</span>
      <input type="text" name="hours" maxlength="120" value="<?= e((string)$me['hours']) ?>" placeholder="如：工作日晚间 / 周六全天">
    </label>
    <label class="field"><span class="label">咨询语言</span>
      <input type="text" name="languages" maxlength="100" value="<?= e((string)$me['languages']) ?>" placeholder="普通话 / 英语">
    </label>
    <label class="field"><span class="label">头像路径</span>
      <div class="img-pick" data-name="avatar">
        <input type="text" name="avatar" value="<?= e((string)$me['avatar']) ?>" placeholder="上传或粘贴 /uploads/… 路径">
        <button type="button" class="btn btn-sm btn-ghost js-upload">上传</button>
        <img class="img-preview" src="<?= e((string)$me['avatar']) ?>" alt="" <?= $me['avatar'] ? '' : 'hidden' ?>>
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
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">保存资料</button></div>
  <p class="hint">保存后前台咨询师页与预约页即时更新。昵称与手机号归属会员体系，如需修改请联系管理员。</p>
</form>
