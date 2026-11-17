<?php /** 量表管理（内置量表，只可停用；含提交统计） */ ?>
<div class="page-guide" data-guide-key="assessments">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>心理量表说明</b>
  <ol><li>内置 PHQ-9 / GAD-7 为国际通用筛查工具，不构成诊断</li><li>「分级分布」= 近 30 日提交的分数段人群</li><li>重度比例升高时，建议发相关科普文章引导预约</li></ol>
</div>
<div class="toolbar">
  <a class="btn btn-ghost" href="/admin/assessments/export" download>导出结果 CSV（脱敏）</a>
  <span class="muted">仅含分数/分级/时间，不含 IP 与答案明细</span>
</div>
<table class="table table-hover">
  <thead><tr><th>量表</th><th>近 30 日提交</th><th>分级分布</th><th>状态</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><b><?= e($r['name']) ?></b><br><code class="muted"><?= e($r['slug']) ?></code></td>
    <td><?= (int)$r['submits_30d'] ?></td>
    <td>
      <?php if (!$r['dist']): ?><span class="muted">—</span><?php endif; ?>
      <?php foreach ($r['dist'] as $d): ?>
      <span class="chip"><?= e($d['level']) ?> · <?= (int)$d['c'] ?></span>
      <?php endforeach; ?>
    </td>
    <td><?= $r['status'] ? '<b class="ok">启用</b>' : '<b class="draft">停用</b>' ?></td>
    <td class="ops">
      <a class="btn btn-sm btn-ghost" href="/assessment/<?= e($r['slug']) ?>" target="_blank">预览前台 ↗</a>
      <form method="post" action="/admin/assessments/toggle/<?= (int)$r['id'] ?>" style="display:inline"><?= csrf_field() ?>
        <button class="btn btn-sm btn-ghost" type="submit"><?= $r['status'] ? '停用' : '启用' ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="hint">内置量表为通用筛查工具（PHQ-9 / GAD-7），仅作自我了解与筛查提示，不构成诊断。量表内容修改需谨慎，并符合规范表述。</p>
