<?php /** 插件管理 */ ?>
<div class="page-guide" data-guide-key="plugins">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>插件怎么用</b>
  <ol>
    <li>插件目录随包位于 plugins/，上传插件目录后在此页出现</li>
    <li>「官方签名」= 发行方 Ed25519 签名的插件；第三方插件请审查代码后再启用</li>
    <li>启停即时生效；「配置」进入插件后台编辑接口参数；卸载会停用并清理私有设置（不影响共享配置）</li>
    <li>启用时会校验核心版本与依赖插件；每日/每分钟任务由服务器 cron 统一调度</li>
  </ol>
</div>
<table class="table table-hover">
  <thead><tr><th>插件</th><th>版本</th><th>作者</th><th>签名</th><th>任务</th><th>状态</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (!$plugins): ?><tr><td colspan="7" class="empty">尚未安装任何插件</td></tr><?php endif; ?>
  <?php foreach ($plugins as $pl): ?>
  <tr>
    <td><b><?= e((string)$pl['name']) ?></b><br><code class="muted"><?= e($pl['slug']) ?></code></td>
    <td><?= e((string)$pl['version']) ?></td>
    <td><?= e((string)$pl['author']) ?></td>
    <td><?= $pl['_official'] ? '<b class="ok">官方签名</b>' : '<b class="draft">未签名</b>' ?></td>
    <td><?php
      $scheds = array_unique(array_map(fn($j) => ($j['schedule'] === 'minutely' ? '每分钟' : '每日'), (array)($pl['cron'] ?? [])));
      echo e($scheds ? implode('+', $scheds) : '—');
    ?><?php if (!empty($pl['dependencies'])): ?><br><span class="muted">依赖：<?= e(implode(',', (array)$pl['dependencies'])) ?></span><?php endif; ?></td>
    <td><?= in_array($pl['slug'], $enabled, true) ? '<b class="ok">启用中</b>' : '<b class="draft">停用</b>' ?></td>
    <td class="ops">
      <?php if (!empty($pl['settings'])): ?>
      <a class="btn btn-sm btn-primary" href="/admin/plugin/<?= e($pl['slug']) ?>">配置</a>
      <?php endif; ?>
      <form method="post" action="/admin/plugins/toggle/<?= e($pl['slug']) ?>" style="display:inline"><?= csrf_field() ?>
        <button class="btn btn-sm btn-ghost" type="submit"><?= in_array($pl['slug'], $enabled, true) ? '停用' : '启用' ?></button>
      </form>
      <form method="post" action="/admin/plugins/uninstall/<?= e($pl['slug']) ?>" style="display:inline" data-confirm="卸载会停用插件并清理其私有设置，确认？"><?= csrf_field() ?>
        <button class="btn btn-sm btn-danger" type="submit">卸载</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="hint">开发规范见随包文档 docs/PLUGIN-GUIDE.md —— 插件可注册钩子、每日任务、后台菜单与前台路由，禁止修改核心文件。</p>
