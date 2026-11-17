<?php /** 域总览（v2.8.4）：每个顶部功能域的落地页 */ ?>
<div class="dash-grid">
  <?php foreach ($cards as $c): [$ico, $t, $v, $d, $url, $tone] = array_pad($c, 6, ''); ?>
  <a class="panel area-card<?= $tone === 'warn' ? ' area-warn' : '' ?>" href="<?= e($url) ?>" style="text-decoration:none;color:inherit">
    <h2 style="display:flex;align-items:center;gap:8px;margin:0 0 8px"><span style="font-size:20px"><?= $ico ?></span><?= e((string)$t) ?><?= $tone === 'warn' ? '<span class="chip" style="background:#fef3c7;color:#92400e;margin-left:auto">需处理</span>' : '' ?></h2>
    <div style="font-size:22px;font-weight:700;color:var(--primary,#0d9488);line-height:1.3"><?= is_numeric($v) ? (int)$v : e((string)$v) ?></div>
    <p class="hint" style="margin:6px 0 0"><?= e((string)$d) ?> ›</p>
  </a>
  <?php endforeach; ?>
</div>

<?php if (($domain ?? '') === 'wechat' && !empty($checklist)): ?>
<div class="panel" style="margin-top:16px">
  <h2>🧭 微信接入向导（机构自有服务号/商户号 · 直连路线）</h2>
  <p class="hint">按步骤申请并配置；CMS 实时检测各步完成度。全绿后会员即可微信登录、微信支付、接收微信通知。独立站点使用机构自己的服务号与商户号——消息来自机构、粉丝沉淀在机构、资金进机构账户（合规无二清）。</p>
  <?php $done = 0; $total = 0; foreach ($checklist as $step): [$tag, $title, $desc, $checks] = $step; ?>
  <?php $stepAll = true; foreach ($checks as [$cl, $ok, $hint]) { if (!$ok) $stepAll = false; } if ($stepAll) $done++; $total++; ?>
  <details style="margin:10px 0;border:1px solid var(--border,#e2e8f0);border-radius:8px;background:<?= $stepAll ? '#f0fdf4' : '#fff' ?>">
    <summary style="cursor:pointer;padding:10px 14px;font-weight:600"><?= $stepAll ? '✅' : '⬜' ?> <?= e((string)$title) ?></summary>
    <div style="padding:0 14px 12px">
      <p class="hint" style="margin:4px 0 8px"><?= e((string)$desc) ?></p>
      <ul style="margin:0;padding-left:4px;list-style:none">
        <?php foreach ($checks as [$cl, $ok, $hint]): ?>
        <li style="padding:3px 0;font-size:13px"><?= $ok ? '<span style="color:#16a34a">✓</span>' : '<span style="color:#d97706">⬜</span>' ?> <?= e((string)$cl) ?><?php if ((string)$hint !== ''): ?> — <span class="muted"><?= e((string)$hint) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </details>
  <?php endforeach; ?>
  <p class="hint">进度：<?= $done ?>/<?= $total ?> 步完成。官方入口：mp.weixin.qq.com（服务号）· pay.weixin.qq.com（商户平台）。协助需求请联系发行方（商业授权含接入协助）。</p>
</div>
<?php endif; ?>

<?php if (($domain ?? '') === 'content'): $recent = \app\Controllers\Admin\Area::recentArticles(); if ($recent): ?>
<div class="panel" style="margin-top:16px">
  <h2>最近文章</h2>
  <table class="table">
    <tr><th>标题</th><th>状态</th><th>时间</th><th>操作</th></tr>
    <?php foreach ($recent as $a): ?>
    <tr>
      <td><?= e((string)$a['title']) ?></td>
      <td><?= $a['status'] === 'published' ? '<span class="chip ok-text">已发布</span>' : '<span class="chip draft">草稿</span>' ?></td>
      <td class="muted"><?= e(dt((string)$a['created_at'])) ?></td>
      <td><a class="btn btn-sm btn-ghost" href="/admin/articles/<?= (int)$a['id'] ?>/edit">编辑</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; endif; ?>

<p class="hint" style="margin-top:14px">💡 使用顶部导航切换功能域；本页数据实时汇总，点击卡片直达对应功能。</p>
