<?php
/** 通用 CRUD 表单
 * @var array $fields  name => [label, type, attrs]
 * @var ?array $row
 */
$isEdit = $row !== null;
$action = $isEdit ? '/admin/' . $baseSeg . '/update/' . $row['id'] : '/admin/' . $baseSeg;
$cats = null;
if (isset($fields['category_id']) && $fields['category_id'][1] === 'category_select') {
    $cats = app\Core\Database::fetchAll("SELECT id, name, type FROM categories WHERE status=1 AND type IN ('article','news') ORDER BY type, sort");
}
$slotsText = '';
if ($isEdit && $baseSeg === 'counselors') {
    $rows = app\Core\Database::fetchAll('SELECT weekday, start_time, end_time FROM counselor_slots WHERE counselor_id=? ORDER BY weekday', [$row['id']]);
    $lines = [];
    foreach ($rows as $s) {
        $lines[] = $s['weekday'] . '|' . substr((string)$s['start_time'], 0, 5) . '|' . substr((string)$s['end_time'], 0, 5);
    }
    $slotsText = implode("\n", $lines);
}
?>
<form class="form card" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="form-grid">
  <?php foreach ($fields as $name => [$label, $type, $attrs]): ?>
    <?php $val = $row[$name] ?? ($attrs['default'] ?? ''); $full = in_array($type, ['textarea','richtext'], true); ?>
    <?php if ($type === 'richtext'): ?>
    <div class="field field-full">
      <span class="label"><?= e($label) ?></span>
      <textarea class="richtext" name="<?= e($name) ?>" rows="10" id="ed-<?= e($name) ?>"><?= e((string)$val) ?></textarea>
      <div class="rt-toolbar">
        <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
        <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
        <button type="button" data-cmd="formatBlock" data-val="h2" title="二级标题">H2</button>
        <button type="button" data-cmd="formatBlock" data-val="h3" title="三级标题">H3</button>
        <button type="button" data-cmd="formatBlock" data-val="p" title="正文段落">P</button>
        <button type="button" data-cmd="insertUnorderedList" title="无序列表">• 列表</button>
        <button type="button" data-cmd="insertOrderedList" title="有序列表">1. 列表</button>
        <button type="button" data-cmd="formatBlock" data-val="blockquote" title="引用块">引用</button>
        <button type="button" data-cmd="insertImage" title="从媒体库插图">🖼 插图</button>
        <button type="button" data-cmd="createLink" title="插入链接">🔗 链接</button>
        <button type="button" data-cmd="removeFormat" title="清除所选格式">✕ 格式</button>
        <span class="rt-hint">所见即所得 · 粘贴自动转纯文本 · 入库白名单净化</span>
      </div>
    </div>
    <?php elseif ($type === 'textarea'): ?>
    <label class="field field-full">
      <span class="label"><?= e($label) ?></span>
      <textarea name="<?= e($name) ?>" rows="<?= (int)($attrs['rows'] ?? 3) ?>"><?= e((string)$val) ?></textarea>
      <?php if (!empty($attrs['hint'])): ?><span class="hint"><?= e($attrs['hint']) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'image'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <div class="img-pick" data-name="<?= e($name) ?>">
        <input type="text" name="<?= e($name) ?>" value="<?= e((string)$val) ?>" placeholder="上传或粘贴 /uploads/… 路径">
        <button type="button" class="btn btn-sm btn-ghost js-upload">上传</button>
        <img class="img-preview" src="<?= e((string)$val) ?>" alt="" <?= $val ? '' : 'hidden' ?>>
      </div>
    </label>
    <?php elseif ($type === 'publish_select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>">
        <option value="draft" <?= $val === 'draft' ? 'selected' : '' ?>>草稿</option>
        <option value="published" <?= $val === 'published' ? 'selected' : '' ?>>发布</option>
      </select>
    </label>
    <?php elseif ($type === 'user_select'): ?>
    <?php /* v2.20.2 咨询师工作台绑定：users 账号下拉（绑定后该账号登录直进咨询师工作台） */ ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>">
        <option value="">未绑定</option>
        <?php foreach (\app\Core\Database::fetchAll('SELECT id, username, display_name FROM users WHERE status=1 ORDER BY username') as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= (int)$val === (int)$u['id'] ? 'selected' : '' ?>><?= e(($u['display_name'] ?: $u['username']) . '（' . $u['username'] . '）') ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">绑定后该后台账号登录即进入咨询师工作台；同一账号仅可绑定一位咨询师</span>
    </label>
    <?php elseif ($type === 'category_select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>">
        <option value="">未分类</option>
        <?php foreach (($cats ?: []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$val === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php elseif ($type === 'type_select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>">
        <option value="article" <?= $val === 'article' ? 'selected' : '' ?>>文章栏目</option>
        <option value="news" <?= $val === 'news' ? 'selected' : '' ?>>新闻栏目</option>
        <option value="faq" <?= $val === 'faq' ? 'selected' : '' ?>>FAQ 分类</option>
      </select>
    </label>
    <?php elseif ($type === 'loc_select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>">
        <option value="header" <?= $val === 'header' ? 'selected' : '' ?>>顶部导航</option>
        <option value="footer" <?= $val === 'footer' ? 'selected' : '' ?>>页脚导航</option>
        <option value="mobile" <?= $val === 'mobile' ? 'selected' : '' ?>>移动端</option>
      </select>
    </label>
    <?php elseif ($type === 'gender_select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>">
        <option value="">不公开</option>
        <option value="1" <?= (int)$val === 1 ? 'selected' : '' ?>>男</option>
        <option value="2" <?= (int)$val === 2 ? 'selected' : '' ?>>女</option>
      </select>
    </label>
    <?php elseif ($type === 'role_select'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <select name="<?= e($name) ?>" required>
        <?php foreach (app\Core\Database::fetchAll('SELECT id, name FROM roles ORDER BY id') as $role): ?>
        <option value="<?= (int)$role['id'] ?>" <?= (int)$val === (int)$role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php elseif ($type === 'switch'): ?>
    <label class="field switch-field">
      <span class="label"><?= e($label) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($name) ?>" value="1" <?= ((int)$val) ? 'checked' : '' ?>><i></i></label>
    </label>
    <?php elseif ($type === 'password'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <input type="password" name="<?= e($name) ?>" value="" autocomplete="new-password">
      <?php if (!empty($attrs['hint'])): ?><span class="hint"><?= e($attrs['hint']) ?></span><?php endif; ?>
    </label>
    <?php elseif ($type === 'datetime'): ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <input type="datetime-local" name="<?= e($name) ?>" value="<?= e($val ? date('Y-m-d\TH:i', strtotime((string)$val)) : '') ?>">
      <?php if (!empty($attrs['hint'])): ?><span class="hint"><?= e($attrs['hint']) ?></span><?php endif; ?>
    </label>
    <?php else: ?>
    <label class="field">
      <span class="label"><?= e($label) ?></span>
      <input type="<?= $type === 'decimal' ? 'number' : 'text' ?>" <?= $type === 'decimal' ? 'step="0.01"' : '' ?>
             name="<?= e($name) ?>" value="<?= e((string)$val) ?>"
             <?= !empty($attrs['required']) ? 'required' : '' ?> <?= !empty($attrs['maxlen']) ? 'maxlength="' . (int)$attrs['maxlen'] . '"' : '' ?>>
      <?php if (!empty($attrs['hint'])): ?><span class="hint"><?= e($attrs['hint']) ?></span><?php endif; ?>
    </label>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($baseSeg === 'counselors'): ?>
  <label class="field field-full">
    <span class="label">可预约时段（每行一条：星期|开始|结束[|单次分钟]，星期 1-7 周一至周日；单次分钟默认 50，范围 10-240）</span>
    <textarea name="slots_text" rows="4" placeholder="6|09:00|12:00|50&#10;6|14:00|17:00&#10;3|19:00|21:00"><?= e($slotsText) ?></textarea>
    <span class="hint">时段按「单次分钟」切片生成可约槽（如 09:00-12:00、50 分钟 → 09:00/09:50/10:40 各成一槽）；提前截止与缓冲由系统设置统一控制；留空表示不展示时段表</span>
  </label>
  <?php endif; ?>
  <?php /* v2.8.0 插件扩展点：stores-pro 注入门店选择等额外字段 */
    $formExtras = \app\Core\Hook::fire('admin.form.extras', ['baseSeg' => $baseSeg, 'row' => $row, 'html' => ''])['html'] ?? '';
    echo $formExtras; ?>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit">保存</button>
    <button class="btn btn-ghost" type="submit" name="_stay" value="1">保存并继续编辑</button>
    <a class="btn btn-ghost" href="/admin/<?= e($baseSeg) ?>">返回列表</a>
    <?php if ($baseSeg === 'articles'): ?><span class="hint">发布时间填未来时间并选「发布」= 定时发布（系统每分钟自动发布）</span><?php endif; ?>
  </div>

  <?php if (!empty($revisions)): ?>
  <div class="panel" style="margin-top:18px">
    <h2>历史修订（保留最近 10 版）</h2>
    <table class="table">
      <tr><th>保存时间</th><th>操作</th></tr>
      <?php foreach ($revisions as $r): ?>
      <tr>
        <td class="muted"><?= e($r['created_at']) ?></td>
        <td>
          <form method="post" action="/admin/articles/<?= (int)$row['id'] ?>/restore-rev/<?= (int)$r['id'] ?>" data-confirm="恢复到该版本？当前内容会先存为新修订。"><?= csrf_field() ?>
            <button class="btn btn-sm btn-ghost" type="submit">恢复此版本</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</form>
