<?php /** 系统设置 */ $v = fn($k, $d = '') => e((string)setting($k, $d)); ?>
<div class="page-guide" data-guide-key="settings">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>系统设置怎么填</b>
  <ol><li>站点信息一次填好；主题风格随时切换即时生效</li><li>SMTP 密码填邮箱「授权码」；填完必点「发送测试邮件」</li><li>SEO 默认关键词/描述兜底全站；百度 token 在站长平台申请</li><li>建议后台空闲超时 2 小时内</li></ol>
</div>
<form method="post" action="/admin/settings/save" id="settingsForm">
  <?= csrf_field() ?>
  <input type="hidden" name="_full_settings" value="1">
  <div class="setting-tabs" id="settingTabs" style="align-items:center">
    <span style="margin-left:auto;font-size:12px;color:#94a3b8">设置项在各标签页中，点击右上方按钮一次保存全部</span>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-left:10px">保存全部设置</button>
    <button type="button" class="tab active" data-tab="tab-site">站点信息</button>
    <button type="button" class="tab" data-tab="tab-seo">SEO / GEO</button>
    <button type="button" class="tab" data-tab="tab-contact">联系方式</button>
    <button type="button" class="tab" data-tab="tab-mail">邮件通知</button>
    <button type="button" class="tab" data-tab="tab-sec">安全与统计</button>
  </div>

  <div class="tab-pane active" id="tab-site">
    <div class="panel">
      <h2>基本信息</h2>
      <div class="form-grid">
        <label class="field"><span class="label">站点名称 <em>*</em></span><input type="text" name="site.name" value="<?= $v('site.name') ?>" required></label>
        <label class="field"><span class="label">CMS 反馈站点 Key</span><input type="text" name="feedback.site_key" value="<?= $v('feedback.site_key') ?>" placeholder="由 CMS 管理台分配"></label>
        <label class="field"><span class="label">CMS 反馈站点 Secret</span><input type="password" name="feedback.site_secret" value="" placeholder="<?= trim((string)setting('feedback.site_secret', '')) !== '' ? '已配置，留空保持不变' : '由 CMS 管理台分配' ?>" autocomplete="new-password"><span class="hint"><?= trim((string)setting('feedback.site_secret', '')) !== '' ? '已配置，留空保持不变' : '留空不修改已有密钥' ?></span></label>
        <label class="field switch-field"><span class="label">预约需登录会员</span>
          <label class="switch"><input type="checkbox" name="booking_require_member" value="1" <?= setting('booking.require_member','0')==='1' ? 'checked' : '' ?>><i></i></label>
          <span class="hint">产品变更提案（默认关）——开启后未登录访客跳转登录页</span>
        </label>
        <label class="field switch-field"><span class="label">测评需登录会员</span>
          <label class="switch"><input type="checkbox" name="assess_require_member" value="1" <?= setting('assess.require_member','0')==='1' ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">留言需登录会员</span>
          <label class="switch"><input type="checkbox" name="message_require_member" value="1" <?= setting('message.require_member','0')==='1' ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field"><span class="label">品牌徽章文字</span><input type="text" name="site.brand_mark" maxlength="3" value="<?= $v('site.brand_mark') ?>" placeholder="留空取站名首字"></label>
        <label class="field"><span class="label">站点 LOGO（徽章图标）</span>
          <select name="site.logo_type">
            <?php $curLogo = $v('site.logo_type') ?: 'text'; ?>
            <option value="text" <?= $curLogo === 'text' ? 'selected' : '' ?>>文字徽章（默认）</option>
            <option value="heart-teal" <?= $curLogo === 'heart-teal' ? 'selected' : '' ?>>心形 · 青色</option>
            <option value="heart-warm" <?= $curLogo === 'heart-warm' ? 'selected' : '' ?>>心形 · 暖粉</option>
            <option value="psych-brain" <?= $curLogo === 'psych-brain' ? 'selected' : '' ?>>心理 · 大脑</option>
            <option value="counsel-chat" <?= $curLogo === 'counsel-chat' ? 'selected' : '' ?>>咨询 · 对话</option>
            <option value="custom" <?= $curLogo === 'custom' ? 'selected' : '' ?>>自定义上传</option>
          </select>
          <span class="hint">选择后自动适配 40×40 圆形徽章大小</span>
        </label>
        <label class="field"><span class="label">自定义 LOGO 图片 URL（选「自定义上传」时填写）</span>
          <input type="text" name="site.logo_custom" value="<?= $v('site.logo_custom') ?>" placeholder="/uploads/logo/custom.svg 或 https://…/logo.png">
          <span class="hint">支持 SVG/PNG，建议正方形；自动裁圆</span>
        </label>
        <label class="field"><span class="label">站点口号</span><input type="text" name="site.slogan" value="<?= $v('site.slogan') ?>"></label>
        <label class="field"><span class="label">主域名（如 ww.123xl.cn）</span><input type="text" name="site.domain" value="<?= $v('site.domain') ?>" placeholder="用于 canonical / sitemap，留空自动"></label>
        <label class="field"><span class="label">ICP 备案号</span><input type="text" name="site.icp" value="<?= $v('site.icp') ?>" placeholder="如：粤ICP备2026xxxxxx号"></label>
        <label class="field"><span class="label">公安备案号</span><input type="text" name="site.police_icp" value="<?= $v('site.police_icp') ?>"></label>
        <label class="field">
          <span class="label">前台主题风格</span>
          <select name="site.theme">
            <?php $curTheme = setting('site.theme', 'healing');
            foreach (['healing' => '治愈青绿（默认）', 'calm-blue' => '静水深蓝 · 专业', 'warm-terra' => '暖陶人文 · 工作室', 'violet-dusk' => '暮紫轻奢 · 现代'] as $tk => $tv): ?>
            <option value="<?= e($tk) ?>" <?= $curTheme === $tk ? 'selected' : '' ?>><?= e($tv) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">保存后前台立即生效（四套均为完整配色方案，仅覆盖色板与渐变）</span>
        </label>
        <?php $canHideBrand = \app\Services\LicenseService::feature('remove_brand'); ?>
        <label class="field switch-field">
          <span class="label">页脚显示「Powered by 123xl-cms」标识</span>
          <label class="switch"><input type="checkbox" name="site.powered_by" value="1" <?= setting('site.powered_by', '1') === '1' ? 'checked' : '' ?> <?= $canHideBrand ? '' : 'disabled' ?>><i></i></label>
          <?php if ($canHideBrand): ?>
          <span class="hint">当前授权可关闭版权标识（关闭后页脚只显示 © 年份与站点名称）</span>
          <?php else: ?>
          <span class="hint">当前授权版本需保留标识（专业版可关闭）</span>
          <?php endif; ?>
        </label>
        <label class="field switch-field"><span class="label">页脚危机提示条（行业规范建议保留）</span>
          <label class="switch"><input type="checkbox" name="site.crisis_bar" value="1" <?= setting('site.crisis_bar', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field field-full">
          <span class="label">危机提示条内容（留空使用默认文案；支持换行，链接请用文字描述）</span>
          <textarea name="site.crisis_bar_text" rows="3" placeholder="默认：如你或身边的人正处于危机中，请立即拨打 24 小时心理援助热线 12356，紧急情况拨打 110。"><?= e((string)setting('site.crisis_bar_text', '')) ?></textarea>
          <span class="hint">为防 XSS 仅输出纯文本与换行；需要放置链接时请在「导航菜单」或单页中安排</span>
        </label>
        <label class="field switch-field"><span class="label">维护模式（全站顶部提示条）</span>
          <label class="switch"><input type="checkbox" name="site.maintenance" value="1" <?= setting('site.maintenance') ? 'checked' : '' ?>><i></i></label>
        </label>
      </div>
      <h2>首页文案</h2>
      <div class="form-grid">
        <label class="field"><span class="label">首页大标题</span><input type="text" name="site.hero_title" value="<?= $v('site.hero_title', '让每一次倾诉，都被温柔以待') ?>"></label>
        <label class="field"><span class="label">首页副标题</span><input type="text" name="site.hero_sub" value="<?= $v('site.hero_sub', '专业 · 保密 · 温暖 —— 我们在这里，陪你一起面对') ?>"></label>
        <label class="field"><span class="label">统计：从业年限</span><input type="text" name="site.stat_years" value="<?= $v('site.stat_years') ?>"></label>
        <label class="field"><span class="label">统计：累计咨询时长</span><input type="text" name="site.stat_hours" value="<?= $v('site.stat_hours') ?>"></label>
        <label class="field"><span class="label">统计：服务会员</span><input type="text" name="site.stat_clients" value="<?= $v('site.stat_clients') ?>"></label>
        <p class="hint" style="grid-column:1/-1;margin:0">统计卡留空或填 0 时不显示；请只填写机构可证实的数字。</p>
      </div>
    </div>
  </div>

  <div class="tab-pane" id="tab-seo">
    <div class="panel">
      <h2>全站默认 SEO</h2>
      <div class="form-grid">
        <label class="field field-full"><span class="label">全站默认关键词</span><input type="text" name="seo.keywords" value="<?= $v('seo.keywords') ?>" placeholder="心理咨询,心理机构,城市名"></label>
        <label class="field field-full"><span class="label">全站默认描述（GEO 也会引用）</span><textarea name="seo.description" rows="3"><?= $v('seo.description') ?></textarea></label>
        <label class="field field-full"><span class="label">robots.txt 追加规则</span><textarea name="seo.robots_extra" rows="3"><?= $v('seo.robots_extra') ?></textarea></label>
        <label class="field"><span class="label">百度主动推送 token</span><input type="text" name="seo.baidu_token" value="<?= $v('seo.baidu_token') ?>" placeholder="站长平台-链接提交-推送接口 token"></label>
        <label class="field"><span class="label">Bing IndexNow Key</span><input type="text" name="seo.indexnow_key" value="<?= $v('seo.indexnow_key') ?>"></label>
        <label class="field field-full"><span class="label">关键词自动内链（每行一条：关键词|目标，目标如 /tag/焦虑 或完整 URL）</span>
          <textarea name="seo.autolink" rows="4" placeholder="焦虑|/tag/焦虑&#10;亲子沟通|/tag/亲子沟通"><?= $v('seo.autolink') ?></textarea>
          <span class="hint">文章渲染时自动把关键词首次出现处链接到目标页（内链 SEO 引擎）</span>
        </label>
      </div>
      <p class="hint">动态资产已内置：/sitemap.xml · /robots.txt · /rss.xml · /llms.txt（GEO）。站长平台验证文件请在「运维工具」上传。</p>
    </div>
  </div>

  <div class="tab-pane" id="tab-contact">
    <div class="panel">
      <h2>联系方式</h2>
      <div class="form-grid">
        <label class="field"><span class="label">联系电话</span><input type="text" name="contact.phone" value="<?= $v('contact.phone') ?>"></label>
        <label class="field"><span class="label">接待时间</span><input type="text" name="contact.hours" value="<?= $v('contact.hours') ?>" placeholder="如：周一至周日 9:00-21:00"></label>
        <label class="field field-full"><span class="label">机构地址</span><input type="text" name="contact.address" value="<?= $v('contact.address') ?>"></label>
        <label class="field"><span class="label">所在城市</span><input type="text" name="contact.city" value="<?= $v('contact.city') ?>" placeholder="结构化数据用"></label>
        <label class="field"><span class="label">微信二维码图</span>
          <div class="img-pick" data-name="contact.wechat_qr">
            <input type="text" name="contact.wechat_qr" value="<?= $v('contact.wechat_qr') ?>">
            <button type="button" class="btn btn-sm btn-ghost js-upload">上传</button>
            <img class="img-preview" src="<?= $v('contact.wechat_qr') ?>" alt="" <?= setting('contact.wechat_qr') ? '' : 'hidden' ?>>
          </div>
        </label>
      </div>
    </div>
  </div>

  <div class="tab-pane" id="tab-mail">
    <div class="panel">
      <h2>SMTP 邮件</h2>
      <div class="form-grid">
        <label class="field switch-field"><span class="label">启用邮件推送</span>
          <label class="switch"><input type="checkbox" name="mail.enabled" value="1" <?= setting('mail.enabled') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field"><span class="label">SMTP 服务器</span><input type="text" name="mail.host" value="<?= $v('mail.host') ?>" placeholder="smtp.qq.com"></label>
        <label class="field"><span class="label">端口</span><input type="number" name="mail.port" value="<?= $v('mail.port', '465') ?>"></label>
        <label class="field"><span class="label">加密方式</span>
          <select name="mail.encryption">
            <option value="ssl" <?= setting('mail.encryption') === 'ssl' ? 'selected' : '' ?>>SSL（端口 465）</option>
            <option value="tls" <?= setting('mail.encryption') === 'tls' ? 'selected' : '' ?>>STARTTLS（端口 587）</option>
            <option value="none" <?= setting('mail.encryption') === 'none' ? 'selected' : '' ?>>无加密</option>
          </select>
        </label>
        <label class="field"><span class="label">SMTP 用户名</span><input type="text" name="mail.user" value="<?= $v('mail.user') ?>" placeholder="发件邮箱"></label>
        <label class="field"><span class="label">SMTP 密码 / 授权码</span><input type="password" name="mail.pass" value="" placeholder="<?= setting('mail.pass') !== null && setting('mail.pass', '') !== '' ? '••••••••（已保存，留空不修改）' : '邮箱需开启 SMTP 并使用授权码' ?>" autocomplete="new-password"></label>
        <label class="field"><span class="label">发件人名称</span><input type="text" name="mail.from_name" value="<?= $v('mail.from_name', '一二三CMS') ?>"></label>
      </div>
      <h2>咨询记录 AI 草稿</h2>
      <div class="form-grid">
        <label class="field switch-field"><span class="label">启用 AI 草稿</span>
          <label class="switch"><input type="checkbox" name="ai.draft_enabled" value="1" <?= setting('ai.draft_enabled', '0') === '1' ? 'checked' : '' ?>><i></i></label>
          <span class="hint">关闭时咨询师看不到生成入口；AI 只产出草稿，永不自动写入咨询记录</span>
        </label>
        <label class="field switch-field"><span class="label">启用 AI 能力总开关</span>
          <label class="switch"><input type="checkbox" name="ai.enabled" value="1" <?= setting('ai.enabled') ? 'checked' : '' ?>><i></i></label>
          <span class="hint">AI 客服与 AI 草稿共用此开关</span>
        </label>
        <label class="field"><span class="label">接口地址（OpenAI 兼容）</span><input type="text" name="ai.base_url" value="<?= $v('ai.base_url', 'https://api.deepseek.com') ?>" placeholder="https://api.deepseek.com"></label>
        <label class="field"><span class="label">模型名</span><input type="text" name="ai.model" value="<?= $v('ai.model') ?>" placeholder="deepseek-chat"></label>
        <label class="field"><span class="label">API Key</span><input type="password" name="ai.api_key" value="" placeholder="<?= setting('ai.api_key', '') !== '' ? '••••••••（已保存，留空不修改）' : 'sk-...' ?>" autocomplete="new-password"></label>
        <label class="field"><span class="label">外发策略</span>
          <select name="ai.outbound_scope">
            <option value="chat" <?= setting('ai.outbound_scope', 'chat') === 'chat' ? 'selected' : '' ?>>仅对话（脱敏后允许外发）</option>
            <option value="none" <?= setting('ai.outbound_scope', 'chat') === 'none' ? 'selected' : '' ?>>零外发（只用本地知识库）</option>
          </select>
          <span class="hint">选「零外发」后，AI 客服与 AI 草稿都不会把内容发到外部接口</span>
        </label>
        <label class="field switch-field"><span class="label">外发前脱敏</span>
          <label class="switch"><input type="checkbox" name="ai.redact" value="1" <?= setting('ai.redact', '1') === '1' ? 'checked' : '' ?>><i></i></label>
          <span class="hint">手机号 / 身份证 / 邮箱 / 卡号替换为占位符</span>
        </label>
      </div>
      <h2>CDN 缓存刷新（阿里云）</h2>
      <div class="form-grid">
        <label class="field"><span class="label">CDN 加速域名</span><input type="text" name="cdn.domain" value="<?= $v('cdn.domain', setting('site.domain', '')) ?>" placeholder="www.example.com（留空用站点域名）"></label>
        <label class="field"><span class="label">AccessKey ID</span><input type="text" name="cdn.access_key_id" value="<?= $v('cdn.access_key_id') ?>" placeholder="阿里云 RAM 子账号的 AccessKey ID"></label>
        <label class="field"><span class="label">AccessKey Secret</span><input type="password" name="cdn.access_key_secret" value="" placeholder="<?= setting('cdn.access_key_secret', '') !== '' ? '••••••••（已保存，留空不修改）' : '建议创建仅含 CDN 刷新权限的 RAM 子账号' ?>" autocomplete="new-password"></label>
        <p class="hint" style="grid-column:1/-1;margin:4px 0 0">配置后「运维工具 → 清理缓存」会同时提交阿里云 CDN 全站目录刷新，系统升级后前台无需强刷即自动切换到新页面。未配置时清理缓存仍会清空应用缓存与 PHP opcache。</p>
      </div>
      <h2>通知推送</h2>
      <div class="form-grid">
        <label class="field field-full"><span class="label">接收邮箱（支持多个值班邮箱，用 ; 分隔）</span><input type="text" name="notify.admin_email" value="<?= $v('notify.admin_email') ?>" placeholder="admin@example.com;duty@example.com（分号分隔，逐一发送）"></label>
        <label class="field switch-field"><span class="label">手机号在通知中打码</span>
          <label class="switch"><input type="checkbox" name="notify.mask_phone" value="1" <?= setting('notify.mask_phone', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
      </div>
      <h2>通知类型（勾选 = 发邮件；危机与登录告警始终发送）</h2>
      <div class="form-grid">
        <label class="field switch-field"><span class="label">新预约</span>
          <label class="switch"><input type="checkbox" name="notify.on_booking" value="1" <?= setting('notify.on_booking', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">咨询留言</span>
          <label class="switch"><input type="checkbox" name="notify.on_message" value="1" <?= setting('notify.on_message', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">访客提问（AI 会话，同会话 5 分钟节流）</span>
          <label class="switch"><input type="checkbox" name="notify.on_chat_question" value="1" <?= setting('notify.on_chat_question', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">AI 转人工 / 疑似未答</span>
          <label class="switch"><input type="checkbox" name="notify.on_chat_escalate" value="1" <?= setting('notify.on_chat_escalate', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">活动报名</span>
          <label class="switch"><input type="checkbox" name="notify.on_event" value="1" <?= setting('notify.on_event', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">系统事件（备份/清理/导入导出）</span>
          <label class="switch"><input type="checkbox" name="notify.on_system" value="1" <?= setting('notify.on_system', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field switch-field"><span class="label">授权与升级</span>
          <label class="switch"><input type="checkbox" name="notify.on_license" value="1" <?= setting('notify.on_license', '1') ? 'checked' : '' ?>><i></i></label>
        </label>
      </div>
      <h2>留言快捷回复模板（每行一条，留言回复时下拉选用）</h2>
      <label class="field field-full">
        <textarea name="reply.templates" rows="4" placeholder="感谢您的留言，我们会在1个工作日内回复。&#10;如需预约请直接在预约页提交，助理会电话与您确认时间。&#10;您的问题涉及具体个人情况，建议预约一次初次访谈详谈。"><?= e((string)setting('reply.templates', '')) ?></textarea>
      </label>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">保存全部设置</button>
        <button class="btn btn-ghost" type="button" id="testMailBtn">发送测试邮件</button>
      </div>
      <div id="mailTestResult" class="muted" hidden></div>
      <p class="hint">测试邮件先保存设置后再发。重要事件为实时直发（失败自动入队重试 3 次）；危机预警与登录告警不受开关限制。</p>
    </div>
  </div>

  <div class="tab-pane" id="tab-sec">
    <div class="panel">
      <h2>安全</h2>
      <div class="form-grid">
        <label class="field"><span class="label">后台空闲超时（秒）</span><input type="number" name="security.admin_idle_timeout" value="<?= $v('security.admin_idle_timeout', '7200') ?>"></label>
        <label class="field switch-field"><span class="label">授权远程校验</span>
          <label class="switch"><input type="checkbox" name="license.remote_check" value="1" <?= setting('license.remote_check', '1') ? 'checked' : '' ?>><i></i></label>
          <span class="hint">关闭后仅本地校验（离线宽限 7 天提示仍生效）</span>
        </label>
        <label class="field"><span class="label">授权远程校验地址</span><input type="text" name="license.check_url" value="<?= $v('license.check_url') ?>" placeholder="未来指向主站 API"></label>
        <label class="field"><span class="label">升级检查地址</span><input type="text" name="update.check_url" value="<?= $v('update.check_url') ?>" placeholder="返回 {version, package_url} 的 JSON"></label>
        <label class="field"><span class="label">后台入口门禁（自定义访问参数，建议设置）</span>
          <input type="text" name="security.admin_gate" value="<?= $v('security.admin_gate') ?>" placeholder="如 gate=8f3k9x">
          <span class="hint">设置后：直接访问 /admin 一律 404，需先访问 /admin?gate=<值>（本机保留 30 天入口 cookie）。留空关闭。</span>
        </label>
        <label class="field"><span class="label">后台 IP 白名单（逗号分隔，留空=不限制）</span>
          <input type="text" name="security.admin_ips" value="<?= $v('security.admin_ips') ?>" placeholder="如 1.2.3.4,5.6.7.0/24">
          <span class="hint">仅办公室固定 IP 场景使用；填写后名单外 IP 一律 403</span>
        </label>
        <label class="field"><span class="label">发行方激活地址（远程激活，留空=离线模式）</span>
          <input type="text" name="license.activate_url" value="<?= $v('license.activate_url') ?>" placeholder="如 https://api.123xl.cn/psycms">
        </label>
        <label class="field switch-field"><span class="label">后台登录成功邮件通知</span>
          <label class="switch"><input type="checkbox" name="notify.login_notice" value="1" <?= setting('notify.login_notice') ? 'checked' : '' ?>><i></i></label>
        </label>
        <label class="field">
          <span class="label">API Token（JSON API v1 集成用）</span>
          <div class="captcha-row">
            <input type="text" name="api_token_display" value="<?= setting('api.token') ? '••••••••••••（已生成，重新生成后旧值失效）' : '' ?>" readonly id="apiTokenBox" placeholder="未生成">
            <button type="button" class="btn btn-sm btn-ghost" onclick="if(confirm('生成新 Token 将使旧 Token 立即失效，继续？')){this.form.closest('form').querySelector('input[name=api_token_flag]').value='1';this.form.closest('form').submit();}">重新生成</button>
          </div>
          <span class="hint">用于微信小程序/公众号/第三方系统只读拉取内容（GET /api/v1/articles 等，Bearer 鉴权，60 次/分钟限流）。文档见 docs/API.md</span>
        </label>
        <label class="field switch-field"><span class="label">自托管访问统计</span>
          <label class="switch"><input type="checkbox" name="stats.enabled" value="1" <?= setting('stats.enabled', '1') ? 'checked' : '' ?>><i></i></label>
          <span class="hint">IP 仅哈希存储，无第三方追踪</span>
        </label>
      </div>
    </div>
  </div>
  <div class="form-actions" style="margin-top:20px;padding-top:14px;border-top:1px solid #e2e8f0">
    <button class="btn btn-primary btn-lg" type="submit">保存全部设置</button>
    <span class="hint">覆盖当前全部标签页的设置项；保存后立即生效</span>
  </div>
</form>