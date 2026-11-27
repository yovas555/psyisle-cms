<?php /** 咨询师资质资料审核详情 */ ?>
<?php
$statusLabels = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回'];
$reviewer = $submission['reviewed_by'] ? '管理员 #' . (int)$submission['reviewed_by'] : '—';
?>
<div class="page-head">
  <div><a class="text-link" href="/admin/counselor-profile-reviews">← 审核队列</a><h1><?= e($submission['counselor_name']) ?> · 资质资料审核</h1></div>
  <div class="row">
    <span class="tag <?= $submission['status'] === 'pending' ? 'warn' : '' ?>"><?= e($statusLabels[$submission['status']] ?? $submission['status']) ?></span>
    <a class="btn btn-ghost" href="<?= e(url('/counselors/' . $counselor['slug'])) ?>" target="_blank" rel="noopener">查看公开档案</a>
    <a class="btn btn-ghost" href="/admin/counselors/<?= (int)$counselor['id'] ?>/verification">信任核验</a>
  </div>
</div>
<div class="card">
  <p><b>提交时间：</b><?= e(dt($submission['submitted_at'])) ?>　<b>提交账号：</b><?= e($submission['submitter_name'] ?: '—') ?></p>
  <?php if ($submission['reviewed_at']): ?><p><b>审核时间：</b><?= e(dt($submission['reviewed_at'])) ?>　<b>审核人：</b><?= e($reviewer) ?></p><?php endif; ?>
  <?php if ($submission['review_note']): ?><p><b>审核备注：</b><?= e($submission['review_note']) ?></p><?php endif; ?>
</div>
<?php if ($conflict && $submission['status'] === 'pending'): ?>
  <div class="alert alert-warning">提交后公开资料已被管理员重新编辑。为避免覆盖后台修改，本次不能通过；请填写原因驳回，让咨询师基于最新内容重新提交。</div>
<?php endif; ?>
<section class="card">
  <h2>资料对比</h2>
  <p class="hint">左侧为当前公开版本，右侧为本次待审核草稿。审核通过后，右侧内容才会覆盖公开字段。</p>
  <div class="review-diff">
    <?php foreach (\app\Services\CounselorProfileReview::LABELS as $key => $label): ?>
      <article>
        <h3><?= e($label) ?></h3>
        <div class="row"><span class="muted">当前公开</span><span class="muted">待审核</span></div>
        <div class="review-diff">
          <pre><?= e($current[$key] ?: '（空）') ?></pre>
          <pre><?= e($draft[$key] ?: '（空）') ?></pre>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<section class="card">
  <h2>私有证明附件</h2>
  <div class="evidence-list">
    <?php if (!$attachments): ?><p class="muted">本次未上传证明附件</p><?php endif; ?>
    <?php foreach ($attachments as $attachment): ?>
      <div class="evidence-item">
        <a href="/admin/counselor-profile-reviews/evidence/<?= (int)$attachment['id'] ?>"><?= e($attachment['original_name']) ?></a>
        <span class="muted"><?= number_format(((int)$attachment['size']) / 1024, 1) ?> KB</span>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="hint">附件仅限机构管理员下载，不进入公开档案。</p>
</section>
<?php if ($submission['status'] === 'pending'): ?>
<section class="card">
  <h2>审核操作</h2>
  <form class="form" method="post" action="/admin/counselor-profile-reviews/<?= (int)$submission['id'] ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="approve">
    <label class="field"><span class="label">通过备注（可选）</span><textarea name="review_note" rows="2" maxlength="500" placeholder="例如：资料表述完整，同意发布为公开自述"></textarea></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit" <?= $conflict ? 'disabled' : '' ?>>通过并发布</button></div>
  </form>
  <form class="form" method="post" action="/admin/counselor-profile-reviews/<?= (int)$submission['id'] ?>" data-confirm="确认驳回本次资质资料？">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reject">
    <label class="field"><span class="label">驳回原因（必填）</span><textarea name="review_note" rows="3" maxlength="500" required placeholder="明确指出需要补充或修改的内容"></textarea></label>
    <div class="form-actions"><button class="btn btn-danger" type="submit">驳回并退回修改</button></div>
  </form>
</section>
<?php endif; ?>
