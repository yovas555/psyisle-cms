# 一二三 PsyIsle CMS —— 心理咨询机构商业建站系统

> 版本 1.0.0 · 本文档为最底层开发规则（用户 2026-11-08 拍板），任何改动须经用户确认。

## 一、产品定位

面向**心理咨询机构**（个人工作室 → 小型咨询公司）的成熟商业 CMS，PHP + MySQL，
以**授权（License）方式发售商业版**，也可从用户主站升级部署。安全、专业、大气、功能齐全。

- 技术基线：**PHP 7.4**（语法兼容 8.x，禁用 8.0+ 特性）、MySQL 5.7+（utf8mb4）、Nginx/Apache 均可。
- **零 Composer 运行依赖**：自研轻量 MVC，随包分发即装即用（国内廉价主机/宝塔友好）。
- 前端零第三方 CDN 依赖（国内网络+隐私合规），全部自托管。
- 产品名「一二三 PsyIsle」为暂定名，可由用户更改；代码命名空间/目录 `psyisle` 不变。

## 二、目录结构

```
psyisle-cms/
├── public/                 # 唯一文档根（nginx root 指向此处，含 install/）
│   ├── index.php           # 入口
│   ├── assets/             # 主题 css/js/img（自托管）
│   ├── uploads/            # 上传文件（禁 PHP 执行）
│   └── .well-known/
├── app/
│   ├── bootstrap.php
│   ├── Core/               # App/Router/Request/Response/Database/Model/View/
│   │                       # Cache/Session/Csrf/Validator/Uploader/Mailer/Http/Captcha/RateLimiter/Logger
│   ├── Controllers/        # 前台：Home Article Counselor Service Booking Message Page Search Chat Static
│   ├── Admin/              # 后台：Dashboard Article Page Counselor Service Booking Message
│   │                       # Chat Media Nav Setting User Tool License Update Audit
│   ├── Models/
│   ├── Services/           # SeoService AiService NotifyService LicenseService Updater StatsService
│   └── views/              # frontend/（主题）+ admin/
├── config/config.php       # 框架配置（安装向导写入 db 凭据到 storage/installed.json）
├── storage/                # cache/ logs/ backups/ （Web 不可达）
├── public/install/         # 安装向导（安装完成自动落锁）
├── upgrades/               # 升级包暂存区
├── database/schema.sql     # 全量建表（utf8mb4）
└── docs/                   # 本文档、部署手册、授权手册
```

## 三、模块清单（v1.0 必须全部实现）

### 前台
1. **首页**：轮播、机构简介、最新动态、推荐咨询师、服务项目、FAQ 摘录、联系方式页脚。
2. **新闻动态**（articles，分类树）：列表+详情，浏览量，上一篇/下一篇。
3. **咨询师团队**：列表（筛选擅长方向）、详情（资历/流派/擅长/收费/可约时段）。
4. **咨询服务**（services）：列表+详情（形式/时长/收费区间）。
5. **在线预约**：选服务/咨询师 → 表单（姓名/联系方式/咨询方式/期望时段/主诉简述）→ 提交 →
   状态查询码。后台状态流转：待确认→已确认→已完成/改期/取消。
6. **咨询留言**：留言表单 + 凭「查询码」查看回复（私密），后台回复可设为对用户可见。
7. **单页系统**：关于我们、收费标准、隐私政策、知情同意、法律声明等（pages，slug 路由）。
8. **常见问题 FAQ**：分类，FAQPage 结构化数据。
9. **站内搜索**：文章/咨询师/服务/单页/FAQ 全文 LIKE 检索。
10. **AI 客服浮窗**：全站右下角，LLM 驱动，可转人工留言。
11. 联系页（地图/微信二维码）、404 页、维护模式页。

### 后台
1. 仪表盘：待办（待确认预约/未回复留言/AI 升级会话/系统告警）+ 近 7 日趋势。
2. 内容：文章、单页、栏目、FAQ、标签；广告法违禁词检测提示。
3. 咨询师管理、服务项目管理。
4. 预约管理：状态流转+备注+邮件通知客户（可选）。
5. 留言管理：回复、可见性控制、关闭。
6. AI 客服：API 配置（OpenAI 兼容：DeepSeek/通义/智谱/Kimi/OpenAI）、
   知识库（自动汇集站点内容+自定义词条）、会话记录、危机干预兜底话术（不可关闭）、
   未答问题沉淀为 FAQ 草稿。
7. 媒体库：统一上传/引用/删除。
8. 导航/轮播/友情链接管理。
9. 用户与角色：admin（超级）/editor（内容）/assistant（预约与留言）/viewer（只读），操作审计。
10. 系统设置：站点信息、SEO 中心、SMTP、AI、安全、备案信息、统计开关。
11. 工具：sitemap 重建、缓存清理、数据库备份/下载、日志查看、robots.txt 编辑。
12. 授权与升级：License 状态、检查更新、一键升级（带备份回滚点）。
13. 通知中心：站内未读通知 + 邮件推送（见下）。

### 通知推送（实时→管理员邮箱）
触发即时入队/直发：**新预约、新留言、AI 客服人工升级请求、连续登录失败告警、
系统升级包可用、安装/授权变更**。SMTP 后台可配，队列表 email_queue，
重要事件同步发送（失败自动重试 3 次），每日 9 点可开关摘要邮件。**通知永远不发给咨客侧**。

### SEO（传统）+ GEO（生成式引擎优化）
- 每实体可自定义 seo_title/keywords/description，缺省自动生成；canonical 全站唯一。
- 路由语义化：`/news`、`/news/{slug}.html`、`/counselors`、`/counselors/{slug}`、
  `/services/{slug}`、`/p/{slug}`、`/faq`、`/search`。
- sitemap.xml（articles/counselors/services/pages/faqs）+ robots.txt + rss.xml（动态生成）。
- JSON-LD：Organization/ProfessionalService、Person（咨询师）、Article、BreadcrumbList、
  FAQPage、WebSite(站内搜索 action)。
- **GEO**：`/llms.txt`（站点结构+业务摘要，便于大模型抓取引用）、语义化 HTML5、
  明确问答块、强 alt 提示、每实体「一句话摘要」字段（利于 AI 引用）。
- 搜索引擎站长通道：百度（token 主动推送+链接）、必应 IndexNow、360/搜狗/谷歌提交页；
  验证文件上传通道（域名根放置）。
- Open Graph + Twitter Card meta；结构化面包屑。

### 商业授权（LicenseService）
- 授权码 `PSY-{EDITION}-{xxxx-xxxx-xxxx}`，HMAC 签名防伪；绑定主域名（hash 比对）。
- 版本：Trial（15 天全功能）/ Studio（1 咨询师+1 服务位）/ Standard / Pro（全功能+去版权标）。
- 功能矩阵在授权内 features：`ai_chat`、`booking`、`max_counselors`、`remove_brand`、`multisite`。
- 离线宽限 7 天（不锁死业务，只显示提醒——机构可用性优先）；安装后落锁 install/lock。
- 升级通道：`upgrades/` zip 包 + version.json + HMAC 校验 → 备份 → 解压覆盖 → 迁移脚本 `upgrade.php` →
  记录 upgrade_history；失败自动回滚文件备份。更新检查源 URL 可配（未来指向主站 API）。

### 安全（最高优先级）
1. PDO 预处理全覆盖；输出统一 `e()` 转义，富文本经白名单过滤入库（allowlist 标签）。
2. CSRF token 全后台/前台表单；同站 cookie SameSite=Lax。
3. 后台登录：验证码（GD）、失败 5 次锁 15 分钟、登录成功审计、异常登录邮件告警。
4. 上传：扩展+MIME 双白名单、重命名、图片内容校验、`uploads/` 禁 PHP（nginx location deny）。
5. 限流：留言/预约/AI 聊天（滑动窗口，文件缓存实现）。
6. 安全头：X-Frame-Options SAMEORIGIN、X-Content-Type-Options nosniff、
   Referrer-Policy strict-origin-when-cross-origin、宽松 CSP。
7. `storage/`、`app/`、`config/`、`upgrades/` 全部置于文档根之外。
8. 会话：HttpOnly、后台 2 小时空闲超时、密码 password_hash（PASSWORD_DEFAULT）。
9. 敏感字段（手机号）展示打码开关；审计日志 180 天滚动。
10. 危机干预兜底：AI 检测自伤关键词 → 固定话术（24 小时热线 12356 / 希望24热线 400-161-9995）+ 建议线下就诊，**此规则不可通过配置关闭**。

### 行业公认必备（用户未提但必须有）
隐私政策/知情同意模板、备案信息字段（ICP+公安）、可访问性（语义 HTML+对比度）、
移动端自适应、数据导出、访问统计（自托管 PV/UV/来源，无第三方追踪）、
友情链接、面包屑导航、站点地图页、错误页、时间显示统一、API 就绪（JSON 接口层）、
多主题目录结构（themes/psy 默认）。

### 面向未来（本版预留结构，不强求全开）
多语言 i18n 路由、微信服务号通知、短信通道（阿里云）、心理测评量表模块、
会员/咨客中心、SaaS 多租户（multi-site license 已预留）、WebSocket 人工接管。

## 四、数据表（MySQL 5.7，utf8mb4_unicode_ci）

settings, users, roles, audit_logs, categories, articles, pages, counselors,
counselor_slots, services, bookings, messages, message_replies, chat_sessions,
chat_messages, faqs, banners, navigations, links, attachments, email_queue,
notifications, visit_logs, visit_stats, licenses, upgrade_history, ai_kb
（27 表，完整 DDL 见 `database/schema.sql`）

## 五、路由约定

- 前台 `/{controller}/{action}` + 语义化 slug 路由；后台固定 `/admin/{controller}/{action}`。
- 动态 SEO 资产：`/sitemap.xml /robots.txt /rss.xml /llms.txt /favicon.ico`。

## 六、部署目标（测试）

- 服务器：腾讯云广州 106.52.109.65（CentOS 7，宝塔 13.1，PHP 7.4，MySQL 5.7）。
- 站点：ww.123xl.cn（复用宝塔既有 vhost「ww.123l.cn」，SSL 已签至 2027-02）。
- docroot 调整为 `/www/wwwroot/ww.123l.cn/public`，需保留宝塔 include 结构。
- 数据库：独立新库 `psyisle`，不与 edusoho 等既有资产共库；不触碰同机其他站点。
- ⚠ 该机为生产机（www.123xl.cn 网校在跑）：只动 ww 站点目录与新增数据库，禁止改动全局服务。

## 七、开发纪律

1. 本文档规则优先级最高；新增模块须先补文档。
2. 全部用户输入走 Validator；全部输出走 e()；全部 SQL 走 Database 预处理。
3. 每个后台写操作记 audit_logs。
4. 提交前自测：安装向导 → 首页/文章/咨询师/预约/留言/AI 客服 → 后台全模块 → SEO 资产。
