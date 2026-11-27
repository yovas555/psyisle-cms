<?php /** 咨询师资质资料待审队列 */ ?>
<div class="page-head">
  <div><a class="text-link" href="/admin/counselors">← 咨询师管理</a><h1>资质资料审核队列</h1></div>
  <span class="tag warn"><?= (int)$pendingCount ?> 条待审核</span>
</div>
<div class="alert alert-info">审核通过仅代表批准发布咨询师自述，不会自动标记为“平台已核验”。资质真实性核验仍在“信任核验”台账单独记录。</div>
<div class="table-wrap">
  <table class="table table-hover">
    <thead><tr><th>咨询师</th><th>提交人</th><th>提交时间</th><th>状态</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">暂无待审核资质资料</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><a href="/admin/counselor-profile-reviews/<?= (int)$row['id'] ?>"><?= e($row['counselor_name']) ?></a></td>
        <td><?= e($row['submitter_name'] ?: '—') ?></td>
        <td><?= e(dt($row['submitted_at'])) ?></td>
        <td><span class="tag warn">待审核</span></td>
        <td class="ops"><a class="btn btn-sm btn-primary" href="/admin/counselor-profile-reviews/<?= (int)$row['id'] ?>">查看并审核</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
