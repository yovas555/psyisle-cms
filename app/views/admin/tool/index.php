<?php /** 运维工具 */ ?>
<div class="page-guide" data-guide-key="tools">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>运维工具说明</b>
  <ol><li>数据库备份建议每周一次（保留 30 天）；升级前系统自动额外备份</li><li>站长平台验证文件上传后即通过验证</li><li>发布文章会自动推送百度/IndexNow；也可手动点「推送最新 URL」</li><li>计划任务已配好（邮件/统计/升级检查/预约提醒）</li></ol>
</div>
<div class="dash-grid">
  <div class="panel">
    <h2>SEO 工具</h2>
    <p class="hint">动态资产生成于访问时：/sitemap.xml · /robots.txt · /rss.xml · /llms.txt</p>
    <div class="chip-row">
      <a class="chip" href="/sitemap.xml" target="_blank">sitemap.xml ↗</a>
      <a class="chip" href="/robots.txt" target="_blank">robots.txt ↗</a>
      <a class="chip" href="/rss.xml" target="_blank">rss.xml ↗</a>
      <a class="chip" href="/llms.txt" target="_blank">llms.txt ↗</a>
    </div>
    <form method="post" action="/admin/tools/push-urls" data-confirm="向百度/IndexNow 推送最近 URL？" style="margin-top:10px">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">推送最新 URL 到搜索引擎</button>
      <span class="hint">发布文章时也会自动推送；token 在系统设置-SEO 配置</span>
    </form>
    <h2>站长平台入口</h2>
    <div class="chip-row">
      <a class="chip" href="https://ziyuan.baidu.com/linksubmit/url" target="_blank" rel="noopener">百度链接提交</a>
      <a class="chip" href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing 站长</a>
      <a class="chip" href="https://zhanzhang.so.com" target="_blank" rel="noopener">360 站长</a>
      <a class="chip" href="https://zhanzhang.sogou.com" target="_blank" rel="noopener">搜狗站长</a>
      <a class="chip" href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Console</a>
    </div>
    <form method="post" action="/admin/tools/verify" enctype="multipart/form-data" style="margin-top:12px">
      <?= csrf_field() ?>
      <label class="field"><span class="label">上传搜索引擎验证文件（放置到站点根）</span>
        <input type="file" name="vfile">
      </label>
      <button class="btn btn-ghost" type="submit">上传验证文件</button>
    </form>
    <h2>robots.txt 追加规则</h2>
    <form method="post" action="/admin/tools/robots">
      <?= csrf_field() ?>
      <textarea name="robots_extra" rows="3" placeholder="如针对特定爬虫的规则"><?= e((string)$robotsExtra) ?></textarea>
      <button class="btn btn-ghost" type="submit">保存</button>
    </form>
  </div>

  <div class="panel">
    <h2>数据库备份</h2>
    <form method="post" action="/admin/tools/backup"><?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">数据库备份（保留 30 天）</button>
    </form>
    <form method="post" action="/admin/tools/full-backup" data-confirm="全量备份含数据库与全部上传文件，可能耗时较长，继续？"><?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">全量备份（数据库 + uploads）</button>
    </form>
    <table class="table">
      <tr><th>文件</th><th>大小</th><th>时间</th></tr>
      <?php if (!$backups): ?><tr><td colspan="3" class="empty">暂无备份</td></tr><?php endif; ?>
      <?php foreach ($backups as $b): ?>
      <tr><td><?= e($b['file']) ?></td><td><?= round($b['size'] / 1024) ?>KB</td><td class="muted"><?= e($b['mtime']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="hint">备份文件位于 storage/backups/（Web 不可达，需经服务器文件管理获取）。</p>
  </div>

  <div class="panel">
    <h2>缓存与日志</h2>
    <form method="post" action="/admin/tools/cache"><?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">清理系统缓存</button>
    </form>
    <h2>最近错误日志</h2>
    <?php if (!$logs): ?><p class="muted">暂无错误日志 🎉</p><?php endif; ?>
    <?php foreach ($logs as $file => $lines): ?>
    <details><summary><?= e($file) ?>（<?= count($lines) ?> 行）</summary>
      <pre class="log-view"><?= e(implode("\n", $lines)) ?></pre>
    </details>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>内容导入导出（迁移）</h2>
    <div class="form-actions">
      <a class="btn btn-ghost" href="/admin/tools/export-content" download>导出全部内容（JSON）</a>
    </div>
    <form method="post" action="/admin/tools/import-content" enctype="multipart/form-data" data-confirm="导入会新增条目（slug 冲突自动加后缀），确认？">
      <?= csrf_field() ?>
      <label class="field"><input type="file" name="content_json" accept=".json"></label>
      <button class="btn btn-ghost" type="submit">导入内容 JSON</button>
      <span class="hint">用于站点迁移或内容交换；导入不覆盖既有数据</span>
    </form>

    <h2>计划任务（已配置 ✓）</h2>
    <p class="hint">服务器 crontab 已配置 4 条任务并实测可用，无需手动操作：</p>
    <div class="code-box small">
      <code>每分钟　邮件队列发送</code><br>
      <code>每日 3:10　访问统计聚合</code><br>
      <code>每日 4:15　升级检查（新版本邮件提醒）</code><br>
      <code>每日 18:00　次日预约提醒</code>
    </div>
    <p class="hint">迁移服务器时在 crontab 添加（secret 见 config/config.php）：<br>
      <code><?= e($cronUrl) ?>/admin/cron/{mail,stats,upgrade-check,booking-reminder}?secret=…</code></p>
  </div>
</div>
