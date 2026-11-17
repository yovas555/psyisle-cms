<?php /** 反馈上报 */ ?>
<div class="page-guide" data-guide-key="feedback">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>上报问题/需求</b>
  <ol>
    <li>通过 CMS 反馈 API 将 bug/需求/建议提交到 CMS 管理台（开发团队跟进）</li>
    <li>需先在「系统设置→安全与统计」配置 feedback.site_key / site_secret</li>
    <li>提交时自动附带 CMS 版本/插件清单/最近错误日志（脱敏）</li>
  </ol>
</div>

<?php if (!$configured): ?>
<div class="alert alert-warning" style="border-radius:12px;margin-bottom:16px">
  <b>反馈通道未配置</b>——请在「系统设置→安全与统计」中填入 CMS 管理台分配的 site_key / site_secret 后刷新本页。
</div>
<?php else: ?>
<div class="panel">
  <h2>提交反馈</h2>
  <?php $last = \app\Core\Cache::get('feedback_last_result'); ?>
  <?php if ($last): ?>
  <p class="<?= $last['ok'] ? 'ok-text' : 'err-text' ?>">上次提交（<?= e($last['at']) ?>）：<?= e($last['msg']) ?></p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">类型</span>
        <select name="type">
          <option value="bug">Bug 报告</option>
          <option value="feature">功能需求</option>
          <option value="question">使用咨询</option>
          <option value="other">其他</option>
        </select>
      </label>
      <label class="field"><span class="label">严重程度</span>
        <select name="severity">
          <option value="P0">P0 · 站点不可用</option>
          <option value="P1">P1 · 核心功能异常</option>
          <option value="P2">P2 · 功能异常但有绕行方案</option>
          <option value="P3" selected>P3 · 一般问题/建议</option>
        </select>
      </label>
      <label class="field"><span class="label">涉及模块</span>
        <select name="area">
          <option value="general">通用</option>
          <option value="booking">预约</option>
          <option value="member">会员</option>
          <option value="payment">支付</option>
          <option value="notify">通知</option>
          <option value="rtc">音视频</option>
          <option value="ui">界面/主题</option>
          <option value="plugin">插件</option>
        </select>
      </label>
      <label class="field field-full"><span class="label">标题</span>
        <input type="text" name="title" required maxlength="80" placeholder="简要描述问题">
      </label>
      <label class="field field-full"><span class="label">详细描述</span>
        <textarea name="body" rows="6" required placeholder="复现步骤、期望结果、实际结果等"></textarea>
      </label>
    </div>
    <button class="btn btn-primary" type="submit">提交反馈</button>
    <span class="hint">提交时自动附带 CMS 版本/插件清单/最近 10 条错误日志（脱敏）</span>
  </form>
</div>
<?php endif; ?>
