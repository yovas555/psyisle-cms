<?php /** 咨询师工作台 · 咨询小结与作业训练 */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2>历史咨询小结与作业（只读）</h2>
    <p class="hint">v2.22.0 起，新的咨询记录统一写进<b>个案管理</b>：可挂到某次会次、可锁定定稿、可追溯版本，历史记录不再新增也不再修改。</p>
    <div class="toolbar">
      <a class="btn btn-primary" href="/admin/counselor-home/cases">进入个案管理 →</a>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>咨询记录（<?= count($rows) ?>）</h2>
  <?php if ($rows): ?>
  <table class="table">
    <tr><th>类型</th><th>标题</th><th>咨客</th><th>可见</th><th>更新</th><th>状态</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $r['kind'] === 'homework' ? '📝 作业' : '📋 小结' ?></td>
      <td><?= e((string)$r['title']) ?></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)($r['m_phone'] ?? ''))) ?></td>
      <td><?= (int)$r['visible'] === 1 ? '<span class="chip ok-text">可见</span>' : '<span class="chip draft">隐藏</span>' ?></td>
      <td class="muted"><?= e(dt((string)$r['updated_at'])) ?></td>
      <td class="muted">只读</td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无记录。</p>
  <?php endif; ?>
</div>
