<?php /** 清除演示内容向导 */ ?>
<div class="page-guide" data-guide-key="purge-demo">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>清除演示内容</b>
  <ol>
    <li>新装站自带演示数据（欢迎文章/示例活动/演示咨询师/FAQ/AI 知识库），正式启用前应清除</li>
    <li>未来日期的已发布活动会<b style="color:#dc2626">误导访客到店</b>——优先处理</li>
    <li>清除操作不可撤销，执行前请确认已备份</li>
  </ol>
</div>

<?php if (!$demoMode): ?>
<div class="alert alert-success" style="margin-bottom:14px">demo_mode 已关闭——演示内容已清除或安装时已跳过。</div>
<?php endif; ?>

<?php if (!empty($futureEvents)): ?>
<div class="alert alert-warning" style="margin-bottom:14px">
  <b>⚠ 以下未来日期的已发布活动存在访客到店风险，请优先清除：</b>
  <ul style="margin:8px 0 0;padding-left:20px">
  <?php foreach ($futureEvents as $e): ?>
    <li><b style="color:#dc2626"><?= e($e['title']) ?></b>（<?= e((string)$e['start_time']) ?>）</li>
  <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" data-confirm="确认清除所选演示内容？此操作不可撤销。">
  <?= csrf_field() ?>
  <div class="panel">
    <h2>按类型勾选清除</h2>
    <?php
    $types = [
        'articles' => ['文章', '欢迎文章/示例文章（slug=welcome 等）'],
        'events' => ['活动', '示例活动与未来日期活动'],
        'counselors' => ['咨询师', '名称含「演示/测试」的咨询师'],
        'services' => ['服务', '名称含「演示/测试」的服务项目'],
        'faqs' => ['FAQ', '演示问答'],
        'banners' => ['轮播图', '演示轮播图'],
        'kb' => ['AI 知识库', '演示问答对'],
        'stats' => ['统计键', '首页统计数字归零（如 3000+ 服务会员）'],
    ];
    foreach ($types as $k => [$label, $desc]): ?>
    <div style="display:flex;gap:10px;align-items:center;margin:6px 0">
      <label style="display:flex;gap:6px;align-items:center">
        <input type="checkbox" name="cats[]" value="<?= e($k) ?>" <?= in_array($k, ['articles','events','stats']) ? 'checked' : '' ?>>
        <b><?= e($label) ?></b>
        <span class="muted" style="font-size:13px"><?= e($desc) ?></span>
      </label>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:14px">
      <button class="btn btn-danger" type="submit">清除演示内容</button>
      <span class="hint" style="margin-left:10px">清除后 demo_mode 自动关闭</span>
    </div>
  </div>
</form>

<?php if (!empty($demoArticles)): ?>
<div class="panel" style="margin-top:14px">
  <h2>演示文章明细</h2>
  <table class="table">
    <tr><th>Slug</th><th>标题</th></tr>
    <?php foreach ($demoArticles as $a): ?>
    <tr><td><code><?= e($a['slug']) ?></code></td><td><?= e($a['title']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
