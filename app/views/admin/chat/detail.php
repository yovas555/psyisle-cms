<?php /** AI 会话详情（人工接管） */ ?>
<div class="panel">
  <h2>会话 #<?= (int)$s['id'] ?>
    <?php if ($s['status'] === 'escalated'): ?><b class="badge warn">待人工</b><?php endif; ?>
    <?php if ($s['crisis_flag']): ?><b class="badge warn">危机标记</b><?php endif; ?>
    <span class="muted"><?= e($s['contact'] ? '联系方式：' . $s['contact'] : '未留联系') ?></span>
  </h2>
  <div class="chat-transcript">
    <?php foreach ($msgs as $m): ?>
    <div class="ct-row ct-<?= e($m['role']) ?>">
      <span class="ct-role"><?= e(['user' => '访客', 'assistant' => '客服', 'system' => '系统'][$m['role']] ?? $m['role']) ?>
        <time><?= e(dt((string)$m['created_at'])) ?></time>
        <?php if ($m['model']): ?><i class="muted"><?= e($m['model']) ?></i><?php endif; ?>
      </span>
      <div class="ct-body"><?= nl2br(e($m['content'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div id="takeoverBanner" class="alert alert-warning" <?= $s['escalated'] ? '' : 'hidden' ?>>
    🎧 人工接管中 —— 你发送的回复将直接展示给访客；AI 对本会话已静默。处理完毕可「交还 AI」。
  </div>
  <form id="chatReplyForm" data-session="<?= (int)$s['id'] ?>">
    <?= csrf_field() ?>
    <label class="field field-full">
      <span class="label">人工回复（发送后访客端实时可见；首次回复即接管本会话）</span>
      <textarea name="content" rows="3" placeholder="输入回复内容，Ctrl+Enter 快捷发送"></textarea>
    </label>
    <button class="btn btn-primary" type="submit">发送回复</button>
  </form>
  <div class="form-actions">
    <?php if ($s['status'] !== 'closed'): ?>
    <form method="post" action="/admin/chat/close/<?= (int)$s['id'] ?>"><?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">关闭会话</button>
    </form>
    <?php endif; ?>
    <form method="post" action="/admin/chat/destroy/<?= (int)$s['id'] ?>" data-confirm="删除该会话及全部消息？不可恢复。"><?= csrf_field() ?>
      <button class="btn btn-danger" type="submit">删除会话</button>
    </form>
    <a class="btn btn-ghost" href="/admin/chat">返回列表</a>
  </div>
</div>
