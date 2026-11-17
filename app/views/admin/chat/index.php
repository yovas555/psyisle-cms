<?php /** AI 客服配置 + 会话 + 知识库 */ ?>
<div class="page-guide" data-guide-key="chat">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>AI 客服配置与接管</b>
  <ol><li>第一步：填 API 地址 / Key / 模型名 → 保存 → 点「测试连通性」</li><li>知识库优先于大模型：常见问题加词条，命中率 100% 不耗 token</li><li>「待人工」会话打开后在底部回复，客户 4 秒内可见</li><li>带「危机」标记：AI 已返回 12356/110 兜底话术，尽快人工跟进</li></ol>
</div>
<div class="page-guide" data-guide-key="chat-actions">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>会话管理</b>：待人工会话处理完请「关闭会话」；历史会话可单条删除，或用下方「清理过期会话」批量移除 30 天前的非待人工会话。
</div>
<div class="toolbar">
  <form method="post" action="/admin/chat/batch-delete" id="batchForm" data-confirm="删除选中的会话及其全部消息？不可恢复。">
    <?= csrf_field() ?>
    <button class="btn btn-danger" type="submit" id="batchDeleteBtn" disabled>删除选中（<span id="selCount">0</span>）</button>
  </form>
  <form method="post" action="/admin/chat/cleanup" data-confirm="自动删除「已关闭」且满 30 天的会话及其消息？（每日凌晨也会自动执行）"><?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit">清理已关闭满 30 天</button>
  </form>
</div>
<div class="dash-grid">
  <div class="panel">
    <h2>智能客服配置</h2>
    <p class="muted">AI 模型接口、角色提示词与知识库已由「AI 智能客服」插件管理。</p>
    <div class="form-actions">
      <a class="btn btn-primary" href="/admin/plugin/ai-concierge">打开 AI 客服插件配置</a>
      <a class="btn btn-ghost" href="/admin/plugins">插件管理</a>
    </div>
    <p class="hint">危机干预兜底（12356 / 110）在核心层强制生效：即使停用插件，访客提及自伤风险时仍会返回热线指引并触发危机预警。</p>
  </div>

  <div class="panel wide">
    <h2>最近会话</h2>
    <table class="table table-hover" id="chatTable">
      <tr><th><input type="checkbox" id="selAll" title="全选"></th><th>#</th><th>标识</th><th>最近消息</th><th>消息数</th><th>状态</th><th>联系</th><th>时间</th><th>操作</th></tr>
      <?php if (!$sessions): ?><tr><td colspan="8" class="empty">暂无会话</td></tr><?php endif; ?>
      <?php foreach ($sessions as $s): ?>
      <tr>
        <td><input type="checkbox" class="sel-row" name="ids[]" form="batchForm" value="<?= (int)$s['id'] ?>"></td>
        <td><?= (int)$s['id'] ?></td>
        <td class="muted"><?= e(substr((string)$s['token'], 0, 8)) ?>…</td>
        <td><?= e(str_take((string)$s['last_msg'], 36, '…')) ?></td>
        <td><?= (int)$s['msg_count'] ?></td>
        <td><?= $s['status'] === 'escalated' ? '<b class="badge warn">待人工</b>' : ($s['status'] === 'closed' ? '已关闭' : 'AI 中') ?>
            <?= $s['crisis_flag'] ? ' <b class="badge warn">危机</b>' : '' ?></td>
        <td><?= e($s['contact'] ?: '—') ?></td>
        <td class="muted"><?= e(dt((string)$s['last_active_at'])) ?></td>
        <td class="ops">
          <a class="btn btn-sm btn-ghost" href="/admin/chat/<?= (int)$s['id'] ?>/edit">查看</a>
          <form method="post" action="/admin/chat/destroy/<?= (int)$s['id'] ?>" style="display:inline" data-confirm="删除该会话及全部消息？"><?= csrf_field() ?>
            <button class="btn btn-sm btn-danger" type="submit">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
