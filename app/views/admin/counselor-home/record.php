<?php /** 个案 · 单条记录（P1-B） */ ?>
<?php $locked = (string)$rec['status'] === 'locked'; ?>
<div class="dash-grid">
  <div class="panel">
    <h2><?= e((string)$rec['title']) ?></h2>
    <div class="toolbar">
      <span class="chip"><?= e($recordTypeLabel) ?></span>
      <span class="chip"><?= $locked ? '<b>已锁定</b>' : '草稿' ?></span>
      <span class="chip">v<?= (int)$rec['version'] ?></span>
      <?php if (!empty($rec['session_seq'])): ?><span class="chip">会次 #<?= (int)$rec['session_seq'] ?></span><?php endif; ?>
      <span class="chip">对咨客 <?= !empty($rec['visible']) ? '可见' : '不可见' ?></span>
      <?php if ($locked): ?><span class="chip">锁定于 <?= e((string)$rec['locked_at']) ?></span><?php endif; ?>
      <a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records">← 记录列表</a>
    </div>
    <?php if (!empty($rec['amends_id'])): ?>
    <p class="hint">本条为修订记录，原记录 #<?= (int)$rec['amends_id'] ?> 已锁定保留。</p>
    <?php endif; ?>
    <div class="note-card" style="margin-top:12px;line-height:1.85">
      <?= (string)$rec['content'] ?>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>操作</h2>
  <div class="toolbar">
    <?php if ($canEdit): ?>
      <a class="btn btn-sm" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records?edit=<?= (int)$rec['id'] ?>">编辑草稿</a>
      <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records/<?= (int)$rec['id'] ?>/lock"
            onsubmit="return confirm('锁定后本条不可修改，只能另开修订记录。确定锁定？');" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn-primary btn-sm" type="submit">锁定（定稿）</button>
      </form>
    <?php elseif ($locked): ?>
      <form method="post" action="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records/<?= (int)$rec['id'] ?>/amend"
            onsubmit="return confirm('将创建一条修订草稿，原记录保持不变。继续？');" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn-sm" type="submit">发起修订</button>
      </form>
      <span class="muted">已锁定的记录不可修改；如需更正请发起修订，形成可追溯的版本链。</span>
    <?php else: ?>
      <span class="muted">个案已结案，本条记录只读。</span>
    <?php endif; ?>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>版本历史（<?= count($versions) ?>）</h2>
  <table class="table">
    <tr><th>版本</th><th>状态</th><th>编辑人</th><th>时间</th><th>操作</th></tr>
    <?php foreach ($versions as $v): ?>
    <tr>
      <td>v<?= (int)$v['version'] ?></td>
      <td><?= (string)$v['status'] === 'locked' ? '锁定快照' : '草稿快照' ?></td>
      <td class="muted">#<?= (int)($v['editor_id'] ?? 0) ?></td>
      <td class="muted"><?= e((string)$v['created_at']) ?></td>
      <td><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases/<?= (int)$case['id'] ?>/records/<?= (int)$rec['id'] ?>?v=<?= (int)$v['version'] ?>">查看</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if (isset($_GET['v'])): ?>
  <?php $snap = \app\Services\CaseRecordService::versionContent((int)$rec['id'], (int)$_GET['v'], (int)$me['id']); ?>
  <?php if ($snap): ?>
  <div class="note-card" style="margin-top:12px">
    <p class="muted" style="margin-bottom:8px">v<?= (int)$snap['version'] ?> 快照 · <?= e((string)$snap['created_at']) ?></p>
    <?= (string)$snap['content'] ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <p class="hint">版本快照只增不改，用于追溯「当时写了什么」。查阅版本同样记入审计。</p>
</div>
