<?php /** 授权与升级 */ $feats = $lic ? (json_decode((string)$lic['features'], true) ?: []) : []; ?>
<div class="page-guide" data-guide-key="license">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>授权与升级说明</b>
  <ol><li>激活：粘贴授权码，域名自动绑定</li><li>升级：上传 .zip → 「执行升级」；自动备份、失败自动回滚</li><li>授权过期不影响站点运行（宽限提示）</li></ol>
</div>
<div class="dash-grid">
  <div class="panel">
    <h2>授权状态</h2>
    <?php if (!$lic): ?>
    <div class="alert alert-warning">当前未激活授权 —— 系统以试用版功能运行（15 天全功能）。正式商用请激活授权码。</div>
    <?php else: ?>
    <table class="table">
      <tr><th>授权码</th><td><b><?= e($lic['license_key']) ?></b></td></tr>
      <tr><th>版本</th><td><?= e($status['label']) ?></td></tr>
      <tr><th>绑定域名</th><td><?= e($lic['domain'] ?: '（试用版不绑定）') ?></td></tr>
      <tr><th>有效期</th><td><?= e($lic['expires_at'] ?: '永久') ?></td></tr>
      <tr><th>功能矩阵</th><td>
        <?php foreach ($feats as $f => $on): ?>
          <?php if (is_bool($on)): ?><span class="chip"><?= e($f) ?>: <?= $on ? '✓' : '✗' ?></span>
          <?php else: ?><span class="chip"><?= e($f) ?>: <?= e((string)$on) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </td></tr>
      <tr><th>最近校验</th><td><?= e($lic['last_check_at'] ?: '—') ?></td></tr>
    </table>
    <?php endif; ?>
    <?php if ($status['warn']): ?><div class="alert alert-warning"><?= e($status['warn']) ?></div><?php endif; ?>

    <?php $u = \app\Services\LicenseService::usage(); ?>
    <h2>用量自助</h2>
    <table class="table">
      <tr><th>有效期</th>
        <td>
          <?php if ($u['days_left'] === null): ?><span class="ok-text">永久有效</span>
          <?php else: ?>
          剩余 <b<?= $u['days_left'] <= 30 ? ' class="err-text"' : '' ?>><?= (int)$u['days_left'] ?></b> 天
          <span class="muted">（已用 <?= (int)$u['percent'] ?>%）</span>
          <?php endif; ?>
        </td></tr>
      <tr><th>咨询师配额</th>
        <td>
          已用 <b<?= $u['counselors']['over'] ? ' class="err-text"' : '' ?>><?= (int)$u['counselors']['used'] ?></b> / <?= (int)$u['counselors']['max'] === 99 ? '不限' : (int)$u['counselors']['max'] ?>
          <?php if ($u['counselors']['over']): ?><span class="err-text">（超出配额，新增咨询师请联系续费升级）</span><?php endif; ?>
        </td></tr>
      <tr><th>多门店</th><td><?= $u['multisite'] ? '<span class="ok-text">✔ 支持</span>' : '—（专业版及以上）' ?></td></tr>
      <tr><th>去版权</th><td><?= $u['remove_brand'] ? '<span class="ok-text">✔ 可关闭</span>' : '—（永久版）' ?></td></tr>
      <tr><th>商业插件</th><td><?= $u['commercial_plugins'] ? '<span class="ok-text">✔ 全部永久使用</span>' : '—（永久版全部，其他版本按授权位）' ?></td></tr>
      <tr><th>AI 客服</th><td><?= $u['ai_chat'] ? '<span class="ok-text">✔ 已启用</span>' : '<span class="draft">✗ 已降级（远程校验超期）</span>' ?></td></tr>
    </table>

    <h2>授权版本对照</h2>
<table class="table">
  <tr><th>版本</th><th>有效期</th><th>咨询师上限</th><th>多门店</th><th>可去版权</th><th>建议价格</th></tr>
  <tr><td>个人版</td><td>180 天试用</td><td>1</td><td>—</td><td>否</td><td>免费</td></tr>
  <tr><td>工作室版</td><td>1 年</td><td>5</td><td>—</td><td>否</td><td>¥1,000 / 年</td></tr>
  <tr><td>专业版</td><td>1 年</td><td>20</td><td>✔ 支持</td><td>否</td><td>¥3,000 / 年</td></tr>
  <tr><td>永久授权版</td><td>永久</td><td>不限</td><td>✔ 支持</td><td>✔ 可关</td><td>¥9,800 一次性</td></tr>
</table>
<p class="hint">授权码格式 <code>PSY-版本-XXXX-XXXX-XXXX-签名</code>，版本段为 PERSONAL / STUDIO / PRO / LIFETIME。</p>

<h2>激活 / 更换授权码</h2>
    <p class="hint">激活模式：<b>远程激活</b>（推荐）——在「系统设置-安全」填发行方激活地址后，输入授权码即自动完成远程验签（Ed25519，7 天心跳自动续期）；<b>离线应急</b>——未配置激活地址时按本地签名校验。</p>
    <form method="post" action="/admin/license/activate">
      <?= csrf_field() ?>
      <label class="field"><span class="label">授权码</span>
        <input type="text" name="license_key" placeholder="PSY-PRO-XXXX-XXXX-XXXX-XXXX">
      </label>
      <button class="btn btn-primary" type="submit">激活</button>
      <button class="btn btn-ghost" type="submit" formaction="/admin/license/check">远程校验</button>
    </form>
    <h2>核心文件完整性</h2>
    <?php $integrity = \app\Services\LicenseService::integrityStatus(); ?>
    <?php if ($integrity[0] === 'ok'): ?><p class="ok-text">✓ 全部核心文件与基线一致</p>
    <?php elseif ($integrity[0] === 'missing'): ?><p class="draft">完整性基线缺失（点击下方重建）</p>
    <?php else: ?><p class="err-text">⚠ 检测到修改：<?= e(implode('；', $integrity[1])) ?></p><?php endif; ?>
    <form method="post" action="/admin/license/rebuild-integrity" style="margin-top:8px"><?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">重建完整性基线</button>
    </form>
    <p class="hint">当前系统版本：<?= e($version) ?></p>
  </div>

  <div class="panel">
    <h2>系统升级</h2>
    <?php $dist = is_array($remote) && ($remote['_proto'] ?? '') === 'distribute'; ?>
    <?php if ($remote): ?>
      <?php if (version_compare((string)$remote['version'], $version, '>')): ?>
      <div class="alert alert-warning">发现新版本 <b><?= e($remote['version']) ?></b>（当前 <?= e($version) ?>）<?= $dist ? '· 已通过主站验签' : '' ?>。<?= e($remote['changelog'] ?? '') ?></div>
      <?php if (!empty($remote['min_from']) && version_compare($version, (string)$remote['min_from'], '<')): ?>
      <div class="alert alert-warning">当前版本低于最低直升版本 <?= e((string)$remote['min_from']) ?>，请先手动逐级升级。</div>
      <?php elseif ($dist): ?>
      <?php
        $policy = is_array($remote['plugin_policy'] ?? null) ? $remote['plugin_policy'] : null;
        $requirements = ($policy && ($policy['mode'] ?? '') === 'separate-plugins' && is_array($policy['require_plugins'] ?? null))
            ? $policy['require_plugins'] : null;
        $pluginsReady = false;
        $pluginReason = '主站未返回有效的独立插件升级策略，禁止执行核心升级。';
        $pluginChecks = [];
        if ($requirements !== null) {
            [$pluginsReady, $pluginReason] = \app\Services\ReleasePackage::checkPluginVersions($requirements);
            foreach ($requirements as $requiredSlug => $requiredVersion) {
                [$checkOk, $checkReason] = \app\Services\ReleasePackage::checkPluginVersions([$requiredSlug => $requiredVersion]);
                $pluginChecks[] = ['ok' => $checkOk, 'reason' => $checkReason];
            }
        }
        $packageReady = ($remote['package_type'] ?? '') === 'upgrade';
        $canCoreUpgrade = $pluginsReady && $packageReady;
      ?>
      <div class="alert <?= $canCoreUpgrade ? 'alert-info' : 'alert-warning' ?>">
        <b>核心升级前置插件条件</b>
        <?php if ($requirements === null): ?>
          <p>无法确认 require_plugins，一键升级已禁用。</p>
        <?php elseif (!$requirements): ?>
          <p>无额外插件版本要求。</p>
        <?php else: ?>
          <ul>
            <?php foreach ($pluginChecks as $check): ?>
              <li class="<?= $check['ok'] ? 'ok-text' : 'err-text' ?>"><?= e($check['reason'] !== '' ? $check['reason'] : '插件版本条件已满足') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!$packageReady): ?><p class="err-text">主站未确认 package_type=upgrade，一键升级已禁用。</p><?php endif; ?>
        <?php if (!$pluginsReady && $requirements !== null): ?><p class="err-text"><?= e($pluginReason) ?></p><?php endif; ?>
      </div>
      <form method="post" action="/admin/upgrade/remote" data-confirm="将从主站下载升级包并自动备份安装，确认执行？">
        <?= csrf_field() ?>
        <button class="btn btn-primary" type="submit" <?= $canCoreUpgrade ? '' : 'disabled' ?>>一键升级到 <?= e($remote['version']) ?></button>
      </form>
      <?php endif; ?>
      <?php else: ?><p class="muted">已是最新版本（<?= $dist ? '主站验签确认' : '远端' ?> <?= e($remote['version']) ?>）。</p><?php endif; ?>
    <?php else: ?>
    <p class="muted"><?= e($remoteMsg) ?></p>
    <?php endif; ?>
    <?php if ($dist): ?>
    <p class="hint">升级通道：升级分发主站（授权码 + 域名 + IP 三重校验通过后下发，本地再验签 + md5 双保险；自动备份，失败自动回滚）。</p>
    <p class="hint">插件升级与核心升级相互独立：核心升级包不覆盖 plugins/，请按下方插件清单单独更新。</p>
    <h3>插件更新（官方插件库）</h3>
    <?php if (!$pluginUpdates): ?><p class="muted">全部官方插件已是最新。</p><?php else: ?>
    <table class="table">
      <tr><th>插件</th><th>本地</th><th>主站</th><th>操作</th></tr>
      <?php foreach ($pluginUpdates as $slug => $u): ?>
      <tr>
        <td><b><?= e($slug) ?></b></td>
        <td class="muted"><?= e($u['local']) ?></td>
        <td class="ok-text"><?= e($u['remote']) ?></td>
        <td>
          <form method="post" action="/admin/upgrade/plugin-update">
            <?= csrf_field() ?>
            <input type="hidden" name="slug" value="<?= e($slug) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">更新到 <?= e($u['remote']) ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="/admin/upgrade/upload" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label class="field"><span class="label">上传升级包（.zip，含 version.json）</span>
        <input type="file" name="package" accept=".zip">
      </label>
      <button class="btn btn-ghost" type="submit">上传升级包</button>
    </form>

    <form method="post" action="/admin/upgrade/run" data-confirm="执行升级会先自动备份代码，确认执行？">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">执行升级</button>
    </form>
    <p class="hint">升级流程：完整验签 → 检查前置插件 → 备份 app/public/database/tools 与升级记录 → 逐文件写入并回读 → 迁移脚本 → 失败自动回滚；config 绝不覆盖。</p>

    <h2>升级历史</h2>
    <table class="table">
      <tr><th>版本</th><th>结果</th><th>时间</th><th>备注</th></tr>
      <?php if (!$history): ?><tr><td colspan="4" class="empty">暂无升级记录</td></tr><?php endif; ?>
      <?php foreach ($history as $h): ?>
      <tr>
        <td><?= e($h['from_version']) ?> → <?= e($h['to_version']) ?></td>
        <td><?= e($h['result'] === 'success' ? '成功' : ($h['result'] === 'rolledback' ? '已回滚' : '失败')) ?></td>
        <td class="muted"><?= e($h['created_at']) ?></td>
        <td class="muted"><?= e(str_take((string)$h['detail'], 30, '')) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
