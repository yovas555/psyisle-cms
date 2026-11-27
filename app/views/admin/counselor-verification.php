<?php /** 咨询师信任信息核验台账 */ ?>
<div class="page-head">
  <div><a class="text-link" href="/admin/counselors">← 咨询师管理</a><h1><?= e($counselor['name']) ?> · 信任信息核验</h1></div>
  <a class="btn btn-ghost" href="<?= e(url('/counselors/' . $counselor['slug'])) ?>" target="_blank" rel="noopener">查看公开档案</a>
</div>
<div class="alert alert-info">核验记录只追加、不覆盖。公开页只展示核验状态、时间、有效期与公开摘要；内部依据和审核人不会公开。自述资料变更后，旧核验自动转为待重新核验。</div>
<div class="trust-grid">
  <?php foreach ($states as $state): ?>
  <article class="card">
    <div class="row"><h3><?= e($state['item_label']) ?></h3><span class="tag <?= $state['verified'] ? '' : 'warn' ?>"><?= e($state['label']) ?></span></div>
    <?php if ($state['reviewed_at']): ?><p class="muted">最近操作：<?= e(dt($state['reviewed_at'])) ?><?= $state['expires_at'] ? ' · 有效期至 ' . e($state['expires_at']) : '' ?></p><?php endif; ?>
    <?php if ($state['subject_changed']): ?><p class="hint">当前资料与最近一次核验时不同，需要重新核验。</p><?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<form class="form card" method="post" action="/admin/counselors/<?= (int)$counselor['id'] ?>/verification">
  <?= csrf_field() ?>
  <h2>追加核验记录</h2>
  <div class="form-grid">
    <label class="field"><span class="label">核验项目</span>
      <select name="item_key"><?php foreach (\app\Services\CounselorTrust::ITEMS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field"><span class="label">核验结论</span>
      <select name="status">
        <option value="pending">待核验</option><option value="verified">通过核验</option>
        <option value="rejected">未通过核验</option><option value="revoked">撤回核验</option>
      </select>
    </label>
    <label class="field"><span class="label">公开核验摘要</span>
      <textarea name="verified_summary" rows="3" maxlength="1000" placeholder="通过核验时必填；只写可向来访者公开的事实，不写证件号码或内部备注"></textarea>
    </label>
    <label class="field"><span class="label">内部核验依据</span>
      <textarea name="evidence_reference" rows="3" maxlength="1000" placeholder="通过核验时必填；例如查验方式与机构档案编号，不公开展示"></textarea>
    </label>
    <label class="field"><span class="label">有效期至（年度审阅必填）</span><input type="date" name="expires_at"></label>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">确认并追加记录</button></div>
</form>
<section class="card">
  <h2>核验台账</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>时间</th><th>项目</th><th>结论</th><th>操作者</th><th>有效期</th><th>内部依据</th></tr></thead>
    <tbody>
    <?php if (!$history): ?><tr><td colspan="6" class="empty">暂无核验记录</td></tr><?php endif; ?>
    <?php foreach ($history as $row): $state = \app\Services\CounselorTrust::evaluate($counselor, $row); ?>
      <tr><td><?= e(dt($row['reviewed_at'])) ?></td><td><?= e(\app\Services\CounselorTrust::ITEMS[$row['item_key']] ?? $row['item_key']) ?></td><td><?= e($state['label']) ?></td><td><?= e($row['verifier_name']) ?></td><td><?= e($row['expires_at'] ?: '—') ?></td><td><?= e($row['evidence_reference'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
