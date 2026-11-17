<?php
/** AI 智能客服插件后台：接口配置 + 提示词 + 知识库 */
use app\Core\PluginManager;

$meta = $meta ?? PluginManager::get('ai-concierge');
$settings = $meta['settings'] ?? [];
?>
<div class="page-guide" data-guide-key="plugin-ai-concierge">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>AI 智能客服 · v<?= e((string)$meta['version']) ?></b>
  <?= $meta['_official'] ? '<span class="chip" style="margin-left:8px">官方签名</span>' : '<span class="chip" style="margin-left:8px;background:#fef3c7;color:#92400e">未签名</span>' ?>
  <div style="margin-top:6px;color:#475569">接入大模型 + 知识库优先应答；危机干预兜底在核心层强制生效（12356 / 110），不受本插件开关影响。会话与人工接管请到「AI 客服会话」。</div>
</div>

<form class="form card" method="post" action="/admin/plugin/ai-concierge/save-settings">
  <?= csrf_field() ?>
  <h2>模型接口（接口数据）</h2>
  <div class="form-grid">
  <?php foreach ($settings as $f):
      $key = (string)($f['key'] ?? ''); if ($key === '') continue;
      $pk = str_replace('.', '_', $key);
      $type = (string)($f['type'] ?? 'text');
      $cur = setting($key, (string)($f['default'] ?? ''));
      if ($f['label'] === '客服角色提示词（留空用系统默认身份）' || $key === 'ai.extra_prompt') continue;
  ?>
    <?php if ($type === 'switch'): ?>
    <label class="field switch-field">
      <span class="label"><?= e((string)$f['label']) ?></span>
      <label class="switch"><input type="checkbox" name="<?= e($pk) ?>" value="1" <?= ((string)$cur === '1') ? 'checked' : '' ?>><i></i></label>
      <?php if (!empty($f['help'])): ?><span class="hint"><?= e((string)$f['help']) ?></span><?php endif; ?>
    </label>
    <?php else: ?>
    <label class="field">
      <span class="label"><?= e((string)$f['label']) ?></span>
      <input type="<?= $type === 'password' ? 'password' : 'text' ?>" name="<?= e($pk) ?>" value="<?= $type === 'password' ? '' : e((string)$cur) ?>" placeholder="<?= $type === 'password' && (string)$cur !== '' ? '••••••••（已保存，留空不修改）' : e((string)($f['placeholder'] ?? '')) ?>" autocomplete="new-password">
      <?php if (!empty($f['help'])): ?><span class="hint"><?= e((string)$f['help']) ?></span><?php endif; ?>
    </label>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>

  <h2 style="margin-top:20px">提示词管理</h2>
  <div class="form-grid">
    <label class="field field-full">
      <span class="label">客服角色提示词（留空使用系统默认身份）</span>
      <textarea name="ai_persona" rows="4" placeholder="你是「XX心理」的资深咨询助理小暖，语气亲切沉稳，擅长共情式回应……"><?= e((string)setting('ai.persona', '')) ?></textarea>
      <span class="hint">定义客服名字、身份、语气与边界；危机干预规则始终由核心强制附加，无法被覆盖。</span>
    </label>
    <label class="field field-full">
      <span class="label">附加提示词 / 机构要求</span>
      <textarea name="ai_extra_prompt" rows="3" placeholder="提及费用时引导到「咨询服务」页；提及地址时附上地铁指引"><?= e((string)setting('ai.extra_prompt', '')) ?></textarea>
    </label>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">保存配置</button>
    <button class="btn btn-ghost" type="button" id="testAiBtn">测试连通性</button>
    <a class="btn btn-ghost" href="/admin/chat">会话与人工接管 →</a>
  </div>
  <div id="aiTestResult" class="muted" hidden></div>
</form>

<div class="panel">
  <h2>知识库（优先于大模型，命中不耗 token）</h2>
  <form method="post" action="/admin/plugin/ai-concierge/add-kb" class="kb-add">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">问题</span><input type="text" name="question" placeholder="如：周末有咨询吗？" required></label>
      <label class="field"><span class="label">关键词（顿号分隔）</span><input type="text" name="keywords" placeholder="周末、营业时间"></label>
      <label class="field field-full"><span class="label">回答</span><textarea name="answer" rows="2" required></textarea></label>
    </div>
    <button class="btn btn-primary" type="submit">添加词条</button>
  </form>

  <table class="table">
    <tr><th>问题</th><th>关键词</th><th>来源</th><th>状态</th><th>操作</th></tr>
    <?php if (!$kb): ?><tr><td colspan="5" class="empty">暂无词条</td></tr><?php endif; ?>
    <?php foreach ($kb as $k): ?>
    <tr>
      <td><?= e(str_take((string)$k['question'], 26, '…')) ?></td>
      <td class="muted"><?= e(str_take((string)$k['keywords'], 18, '')) ?></td>
      <td><?= e($k['source'] === 'auto_faq' ? 'FAQ 同步' : '手动') ?></td>
      <td><?= $k['enabled'] ? '<b class="ok">启用</b>' : '<b class="draft">停用</b>' ?></td>
      <td class="ops">
        <form method="post" action="/admin/plugin/ai-concierge/kb-toggle" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
          <button class="btn btn-sm btn-ghost" type="submit"><?= $k['enabled'] ? '停用' : '启用' ?></button>
        </form>
        <form method="post" action="/admin/plugin/ai-concierge/kb-delete" style="display:inline" data-confirm="删除该词条？"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<script>
(function () {
  var PRESETS = {
    'deepseek':  { url: 'https://api.deepseek.com', model: 'deepseek-chat' },
    'agnes':     { url: 'https://apihub.agnes-ai.com/v1', model: 'agnes-2.5-flash' },
    'agnes-cn':  { url: 'https://api.agnes-ai.cn/v1', model: 'agnes-2.5-flash' },
    'tongyi':    { url: 'https://dashscope.aliyuncs.com/compatible-mode/v1', model: 'qwen-plus' },
    'zhipu':     { url: 'https://open.bigmodel.cn/api/paas/v4', model: 'glm-4-flash' },
    'kimi':      { url: 'https://api.moonshot.cn/v1', model: 'moonshot-v1-8k' },
    'openai':    { url: 'https://api.openai.com/v1', model: 'gpt-4o-mini' }
  };
  var sel = document.querySelector('select[name="ai_provider"]');
  if (!sel) return;
  var urlIn = document.querySelector('input[name="ai_base_url"]');
  var modelIn = document.querySelector('input[name="ai_model"]');
  sel.addEventListener('change', function () {
    var p = PRESETS[sel.value];
    if (!p) return;
    if (urlIn) urlIn.value = p.url;
    if (modelIn) modelIn.value = p.model;
  });
})();
</script>
