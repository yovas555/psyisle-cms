<?php /** 留言详情与回复 */ ?>
<div class="detail-grid">
  <div class="panel">
    <h2>留言信息</h2>
    <table class="table">
      <tr><th>查询码</th><td><b><?= e($m['code']) ?></b></td></tr>
      <tr><th>联系人</th><td><?= e($m['name'] ?: '匿名') ?></td></tr>
      <tr><th>联系方式</th><td><?= e($m['contact']) ?></td></tr>
      <tr><th>主题</th><td><?= e($m['subject'] ?: '—') ?></td></tr>
      <tr><th>状态</th><td><?= e($m['status']) ?></td></tr>
      <tr><th>IP / 时间</th><td class="muted"><?= e($m['ip'] ?: '—') ?> · <?= e($m['created_at']) ?></td></tr>
    </table>
    <blockquote class="msg-content"><?= nl2br(e($m['content'])) ?></blockquote>

    <?php foreach ($replies as $r): ?>
    <div class="reply-item <?= $r['visible_to_user'] ? '' : 'hidden-reply' ?>">
      <p class="reply-meta"><?= e($r['display_name'] ?: '客服') ?> · <?= e($r['created_at']) ?> · <?= $r['visible_to_user'] ? '用户可见' : '内部备注' ?></p>
      <div><?= nl2br(e($r['content'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>回复</h2>
    <form id="replyForm" data-message="<?= (int)$m['id'] ?>">
      <?= csrf_field() ?>
      <label class="field switch-field">
        <span class="label">精选为公开问答</span>
        <label class="switch"><input type="checkbox" name="is_featured" value="1" <?= $m['is_featured'] ? 'checked' : '' ?>><i></i></label>
        <span class="hint">勾选后此留言的「问」与最新回复将匿名展示在网站「问答精选」页（用于内容获客与 SEO）。仅选脱敏后的内容。</span>
      </label>
      <label class="field field-full">
        <span class="label">公开问答格式（勾选精选时使用，问/答将分别展示）</span>
        <textarea name="featured_text" rows="4" placeholder="问：…&#10;答：…（把下方留言与回复整理成匿名化的问答）"><?= e((string)$m['content']) ?></textarea>
      </label>
      <?php $tplRaw = (string)setting('reply.templates', '');
      $tpls = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $tplRaw) ?: []))); ?>
      <?php if ($tpls): ?>
      <label class="field field-full">
        <span class="label">快捷回复（选择后自动填入，可修改）</span>
        <select onchange="if(this.value){var ta=this.closest('form').querySelector('textarea[name=content]');ta.value=this.value;ta.focus();}">
          <option value="">— 选择模板 —</option>
          <?php foreach ($tpls as $tpl): ?><option value="<?= e($tpl) ?>"><?= e(str_take($tpl, 30, '…')) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label class="field field-full">
        <span class="label">回复内容</span>
        <textarea name="content" rows="5" maxlength="3000" placeholder="回复内容（凭查询码的用户可见部分请保证得体）"></textarea>
      </label>
      <label class="field switch-field">
        <span class="label">对用户可见</span>
        <label class="switch"><input type="checkbox" name="visible_to_user" value="1" checked><i></i></label>
        <span class="hint">关闭则仅作为内部备注保存</span>
      </label>
      <button class="btn btn-primary" type="submit">提交回复</button>
    </form>
    <div class="form-actions">
      <?php if ($m['status'] !== 'closed'): ?>
      <form method="post" action="/admin/messages/close/<?= (int)$m['id'] ?>"><?= csrf_field() ?>
        <button class="btn btn-ghost" type="submit">关闭留言</button>
      </form>
      <?php endif; ?>
      <form method="post" action="/admin/messages/delete/<?= (int)$m['id'] ?>" data-confirm="确认删除该留言及全部回复？"><?= csrf_field() ?>
        <button class="btn btn-danger" type="submit">删除留言</button>
      </form>
    </div>
  </div>
</div>
