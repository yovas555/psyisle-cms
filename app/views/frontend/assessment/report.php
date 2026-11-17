<?php /** 测评报告页（凭 token 脱敏回看；打印/存 PDF 优化） */ $lvl = (string)$r['level']; ?>
<section class="section report-page">
  <div class="container narrow">
    <div class="card report-card">
      <div class="report-head">
        <div>
          <p class="muted"><?= e($site) ?> · 心理测评报告</p>
          <h1><?= e((string)$r['a_name']) ?></h1>
          <p class="muted">测评时间：<?= e((string)$r['created_at']) ?>（报告编号 R<?= e(substr((string)$r['report_token'], 0, 8)) ?>）</p>
        </div>
        <div class="report-brand"><?= e($site) ?></div>
      </div>

      <table class="table report-sum">
        <tr><th>总分</th><td><b class="score-num"><?= (int)$r['score'] ?></b></td><th>分级</th><td><b><?= e($lvl) ?></b></td></tr>
        <tr><th>完成题数</th><td><?= count($answers) ?></td><th>作答说明</th><td>未作答按 0 分计</td></tr>
      </table>

      <?php if (is_array($dimensions)): ?>
      <h2 class="report-h2">维度得分</h2>
      <table class="table">
        <tr><th>维度</th><th>得分</th><th>项目数</th><th>说明</th></tr>
        <?php foreach ($dimensions as $d): ?>
        <tr>
          <td><b><?= e((string)$d['name']) ?></b></td>
          <td><b><?= (int)$d['score'] ?></b><?= isset($d['max']) ? ' / ' . (int)$d['max'] : '' ?></td>
          <td><?= (int)($d['count'] ?? 0) ?></td>
          <td class="muted"><?= e((string)($d['note'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>

      <h2 class="report-h2">分级说明</h2>
      <table class="table">
        <tr><th>分数区间</th><th>分级</th><th>建议</th></tr>
        <?php $prev = null; foreach ($scoring as $band): $lo = $prev === null ? 0 : $prev + 1; ?>
        <tr<?= ($lvl !== '' && mb_strpos($lvl, (string)$band['level']) !== false) ? ' style="background:rgba(13,148,136,.06)"' : '' ?>>
          <td><?= $lo ?>–<?= (int)$band['max'] ?></td>
          <td><b><?= e((string)$band['level']) ?></b></td>
          <td class="muted"><?= e((string)$band['advice']) ?></td>
        </tr>
        <?php $prev = (int)$band['max']; endforeach; ?>
      </table>
      <p class="report-advice muted" style="margin-top:8px"><?= e((string)($r['disclaimer'] ?? '')) ?></p>

      <div class="cp-actions report-actions" style="justify-content:flex-start">
        <a class="btn btn-primary" href="<?= url('/booking?source=report') ?>">预约一次专业咨询</a>
        <?php if ($phone): ?><a class="btn btn-ghost" href="tel:<?= e($phone) ?>">电话咨询 <?= e($phone) ?></a><?php endif; ?>
        <button class="btn btn-ghost no-print" onclick="window.print()">打印 / 保存 PDF</button>
      </div>

      <p class="hint report-foot">本报告为自评筛查结果，<b>不构成医学诊断</b>；分数受作答情境影响，解读请结合专业咨询。链接仅凭报告编号访问，不含个人信息；如需删除请通过会员中心「隐私中心」申请或联系机构。</p>
    </div>
  </div>
</section>
<style>
@media print {
  .site-head, .site-foot, .breadcrumb, .header-cta, .no-print, .chat-fab, .back-top { display: none !important; }
  .report-card { border: none; box-shadow: none; }
  body { background: #fff; }
}
.report-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.report-head h1 { margin: 4px 0; font-size: 24px; }
.report-brand { color: var(--primary, #0d9488); font-weight: 700; white-space: nowrap; }
.report-sum th { width: 90px; color: var(--muted, #64748b); font-weight: 500; }
.report-h2 { margin: 22px 0 8px; font-size: 17px; }
.report-advice { line-height: 1.7; }
.report-foot { margin-top: 18px; }
.report-actions { margin-top: 18px; flex-wrap: wrap; }
</style>
