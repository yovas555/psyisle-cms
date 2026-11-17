<?php /** 使用手册（后台内嵌精要版，完整版见服务器 docs/USER-MANUAL-*.md） */ ?>
<div class="page-guide" data-guide-key="help">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>本页为手册精要</b> —— 完整《使用白皮书 v<?= CMS_VERSION ?>》与《运维档案》随系统归档于服务器 docs/ 目录（技术人员经 SSH 读取）。
</div>

<div class="panel">
  <h2>快速上手 5 步</h2>
  <ol class="help-steps">
    <li><b>改默认密码</b>（用户与角色）+ 开启两步验证</li>
    <li><b>完善站点信息</b>（系统设置：名称/联系方式/备案号/主题风格）</li>
    <li><b>接通智能客服与邮件</b>（AI 客服 API Key + 测试连通性；SMTP 授权码 + 测试邮件）</li>
    <li><b>发布真实内容</b>（文章/咨询师/服务/活动，替换演示数据）</li>
    <li><b>搜索引擎提交</b>（设置-SEO 填关键词描述；工具页上传验证文件；站长平台提交 sitemap）</li>
  </ol>
</div>

<div class="panel">
  <h2>每天要做的事</h2>
  <table class="table">
    <tr><th>场景</th><th>去哪里</th><th>怎么做</th></tr>
    <tr><td>有新预约</td><td>预约管理（仪表盘待办提醒）</td><td>电话确认 → 状态「已确认」→ 咨询后「已完成」；备注写给客户看</td></tr>
    <tr><td>有新留言</td><td>咨询留言</td><td>回复（默认用户可见）；有价值的整理成「问答精选」</td></tr>
    <tr><td>AI 转人工</td><td>AI 客服（待人工会话）</td><td>打开会话底部回复，客户 4 秒内收到</td></tr>
    <tr><td>危机预警</td><td>AI 客服（红色危机卡）</td><td>系统已返回 12356/110 兜底话术，尽快人工跟进</td></tr>
    <tr><td>要发通知</td><td>内容管理各模块</td><td>发布后自动推送搜索引擎，无需额外操作</td></tr>
  </table>
</div>

<div class="panel">
  <h2>重要规则（请记住）</h2>
  <ul class="help-rules">
    <li>危机干预热线口径统一为 <b>12356 / 110</b>，AI 兜底不可关闭</li>
    <li>预约必须勾选《咨询知情同意书》；未成年人需监护人同意</li>
    <li>会员心声与问答精选：先授权、再脱敏，不做疗效承诺</li>
    <li>文章标题/摘要避免广告法违禁词（系统会提示）</li>
    <li>数据库备份每周一次（运维工具），升级系统前会自动额外备份</li>
    <li>只给运营人员分配「预约助理」或「内容编辑」角色，管理员账号开启两步验证</li>
  </ul>
</div>

<div class="panel">
  <h2>遇到问题</h2>
  <p>① 先看本页「常见问题」精要 → ② 运维工具页查看错误日志 → ③ 联系技术支持（提供：操作页面、时间、现象截图）。</p>
  <p class="muted">系统版本 v<?= CMS_VERSION ?> · 完整手册：docs/USER-MANUAL-v<?= CMS_VERSION ?>.md · 运维档案：docs/OPS.md</p>
</div>
