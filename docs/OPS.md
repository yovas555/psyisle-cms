# 一二三 PsyIsle CMS 运维档案

> 文档版本 2026-11-27 · 对应系统版本 v2.34.3 · 密级：内部（含敏感入口信息，禁止外发）
> 配套文档：《使用白皮书》docs/USER-MANUAL-v1.4.0.md · 接口细则 docs/API.md · 架构规则 docs/ARCHITECTURE.md

---

## 一、系统概览

**一二三 PsyIsle CMS** —— 面向心理咨询机构的商业建站系统，授权制发行。

| 项 | 值 |
|---|---|
| 当前版本 | 2.34.3（WW 已部署，核心 active；onzx 待自助升级复验；见 app/bootstrap.php CMS_VERSION） |
| 发布状态 | 核心 `psyisle-2.34.3-20261127-221404.zip`，min_from 2.34.2，SHA-256 `eb3ac56f1de4a88030f31c4b3a4b344cd5642d444390c017cf844b83c2eb774f`；ai-concierge 1.0.3；完整发布与回滚事实以 `docs/RELEASE.md` 为准 |
| 技术栈 | PHP 7.4（语法兼容 8.x）· MySQL 5.7+ utf8mb4 · Nginx · 自研 MVC（零 Composer 依赖） |
| 代码规模 | 约 90 个 PHP 文件 / 30+ 视图 / 6 套 CSS |
| 部署形态 | 单站点单库；文档根 public/，应用代码在 Web 不可达目录 |
| 架构分层 | public/index.php → bootstrap（autoload/安全头/异常兜底）→ Router → Controllers（前台 app\Controllers / 后台 app\Controllers\Admin）→ Models/Services |

功能面：内容（文章/单页/栏目/FAQ/心声/轮播/导航/友链）· 机构（咨询师/服务）· 业务（预约/留言/AI 客服/量表/活动）· 临床（个案/结构化咨询记录/危机闭环/作业随访，v2.22.0 起）· 运营（通知/审计/统计/SEO 推送）· 系统（用户角色 RBAC/两步验证/设置/工具/授权升级）· 集成（JSON API v1/健康检查）。

## 二、站点矩阵

| 站点 | 目录 | 数据库 | 用途 |
|---|---|---|---|
| ww.123xl.cn | /www/wwwroot/ww.123l.cn（public/） | psyisle | 生产站（授权绑定域名） |
| **demo.123xl.cn** | /www/wwwroot/demo.123xl.cn | demo_psyisle（50 表） | 演示站：demo/demo123（只读后台）+ csl_demo/csl123（咨询师）+ 13800138000/demo123（会员）；全插件不启用但可从主站安装；nginx LICENSE 修复 |
| **cms.123xl.cn** | /www/wwwroot/cms.123xl.cn | —（静态） | 营销官网（功能特点/版本对标/演示站入口） |

演示站配置：mail.enabled=0（不发邮件）、ICP 显示「演示站点」、Powered by 指向官网。

## 三、服务器与环境

| 项 | 值 |
|---|---|
| 生产服务器 | 腾讯云广州 106.52.109.65（CentOS 7，宝塔 13.1）⚠ 同机另有 www.123xl.cn 网校生产业务，**只动 ww 站点目录，禁碰其他站点** |
| SSH | `ssh gz-123xl`（root，公钥 id_ed25519_onzx 已装，密钥在本机 ~/.ssh） |
| 站点目录 | /www/wwwroot/ww.123l.cn（宝塔站点名历史拼写遗留，server_name = ww.123xl.cn） |
| 文档根 | …/ww.123l.cn/**public**（nginx root 已改；rewrite：`try_files $uri $uri/ /index.php?$query_string`） |
| PHP | /www/server/php/74（CLI：/www/server/php/74/bin/php；fpm socket） |
| MySQL | 5.7.43；库 `psyisle` + 用户 `psyisle@localhost`（凭据在服务器 config/config.php；宝塔 root 密码 BT- 加密存 default.db，用宝塔 pyenv `db.Sql()` 解密） |
| 安全拦截 | nginx deny：app/config/storage/upgrades/database/docs；uploads 禁 PHP 执行；宝塔 WAF（会 403 拦含 script 的 POST——测试富文本勿放 script 标签）。**宝塔默认敏感文件正则含裸词 LICENSE，会误杀 `/admin/license`；`deploy/nginx-snippet.conf` 与 deploy.sh 已加精确匹配段** |
| SSL | Let's Encrypt 至 2027-02（到期前在宝塔续） |

**服务器关键文件**
```
/www/wwwroot/ww.123l.cn/
├── config/config.php          # db 凭据 / cron_secret / license_secret / stats_salt（rsync 排除，勿覆盖）
├── storage/installed.json     # 安装锁（删除=允许重装，危险）
├── storage/backups/           # AES-256-CBC / PSYENC1 加密备份（本地保留 7 天）
├── upgrades/                  # 升级包暂存 + last-applied.json（www 属主）
└── public/uploads/            # 上传文件（禁 PHP 执行）
```

## 三、部署流程（本机 → 生产）

```bash
# 日常发布（本机执行）
bash /home/kawah/.zcode/workspace/…/psyisle-cms/deploy/deploy.sh
```
流程：rsync（--delete，排除 storage/、config/config.php、public/uploads/、.user.ini、.well-known、tools/、dist/、docs/）→ 权限修正（uploads/storage/upgrades 归 www）→ 全量 php -l 语法门禁 → nginx 配置核对+reload。

**门禁**：开发与发布为同一责任链；开发阶段完成本地修改/自测并提交验收，验收通过后才进入部署、构建、登记与发布回填。v2.24.4 已完成 WW 部署、构建、登记、远端全量回归、RTC 桌面/移动端浏览器验收，以及 CMS 公告、六页、插件目录与桌面/移动端官网走查，结果见 `docs/RELEASE.md`。

**注意**
- rsync --delete 会删除服务器上不在本地的文件——新文件先落本地仓库再部署，严禁在服务器直接改代码
- .user.ini 被 chattr +i 锁定，永远排除
- 部署后自动跑 php -l，任何 Parse error 会在第 3 步暴露

**回滚**：升级失败的代码回滚由 Updater 自动完成（restoreBackup）；手工回滚用 storage/backups/upgrade-backup-*.zip 解压覆盖 app/config/public。

## 四、数据库

表清单（33）：settings, users, roles, audit_logs, categories, articles, pages, counselors,
counselor_slots, services, bookings, messages, message_replies, chat_sessions, chat_messages,
ai_kb, faqs, banners, navigations, links, attachments, email_queue, notifications, visit_logs,
visit_stats, licenses, upgrade_history, assessments, assessment_results, testimonials, events, event_registrations, revisions。

- 全量 DDL：database/schema.sql（含后续 ALTER 记录；MySQL 5.7 无 ADD COLUMN IF NOT EXISTS，用 information_schema+PREPARE 条件执行）
- 备份：工具页「数据库备份」（mysqldump 或内置 PHP 导出）+「全量备份」（DB+uploads）；本地滚动保留 **7 天**，v2.21.0 起为 **AES-256-CBC / PSYENC1** 加密备份（`*.enc`）。
- 恢复：先用 `BackupCrypto::decryptFile()` 解密，再校验并解压 gzip/zip 后导入目标库（完整步骤见 §十四）；不得只按明文 SQL 导入操作加密备份。
- 隐私约定：visit_logs 只存 IP 哈希（stats_salt）；assessment_results 不存答案明细；手机号展示打码

## 五、计划任务（crontab，root）

| 时间 | 端点 | 作用 |
|---|---|---|
| 每分钟 | /admin/cron/mail | 邮件队列发送（重要事件实时直发，失败入队重试 3 次） |
| 每日 3:10 | /admin/cron/stats | 访问统计聚合（清 7 天前明细） |
| 每日 4:15 | /admin/cron/upgrade-check | 升级检查 + 授权远程校验心跳 |
| 每分钟 | /admin/cron/publish | 定时发布：到时的 scheduled 文章自动发布+推送+钩子 |
| 每分钟 | /admin/cron/plugins | 插件每日任务调度（周报等 daily 任务按星期执行） |
| 每日 18:00 | /admin/cron/booking-reminder | 次日已确认预约 → 管理员提醒邮件 |

鉴权：`X-Cron-Secret` 请求头（secret 在 config.php 的 cron_secret；**不要用 query 传**，会进 access log）。迁移服务器时按 config.php 提示重配。探针：`curl -H 'X-Cron-Secret: …' https://ww.123xl.cn/admin/cron/mail`。

## 六、接口清单

### 6.1 对外集成 API v1（docs/API.md）
`GET /api/v1/{articles|counselors|services|events|faqs|assessments}`，Bearer Token（设置页生成，60/min 限流），只读、strip_tags、无隐私字段。健康检查 `GET /health`（10/min 限流）。

### 6.2 前台页面路由
`/` `/news[/category/{slug}]` `/news/{slug}` `/counselors[/{slug}]` `/services[/{slug}]` `/events[/{slug}[/register]]` `/assessment[/{slug}]` `/qa` `/tag/{tag}` `/faq` `/booking` `/booking/slots` `/message` `/message/reply` `/p/{slug}`（about/privacy/consent/crisis/pricing/process/contact）`/search`
注：`/booking/check` 已在 v2.24.0 废除，不在当前路由清单；历史版本记录保留。
SEO 资产：`/sitemap.xml /robots.txt /rss.xml /llms.txt /favicon.ico /{indexnow-key}.txt`

### 6.3 后台路由
`/admin`（仪表盘）`/admin/{seg}` CRUD（seg = articles/categories/pages/faqs/banners/navs/links/media/counselors/services/testimonials/events）`/admin/bookings` `/admin/messages[/reply/{id}|close/{id}|export]` `/admin/chat[/…|kb/{id}]` `/admin/assessments[/export|toggle/{id}]` `/admin/settings[/save|testmail]` `/admin/tools[/backup|full-backup|cache|robots|verify|push-urls]` `/admin/license[/activate|check]` `/admin/release[/upload|status|delete|client]`（升级分发主站）`/admin/upgrade[/upload|run|remote|plugin-update]` `/admin/users[/roles|totp-*]` `/admin/audits` `/admin/notifications[/read]`。
无登录端点：`/admin/login /admin/totp /admin/captcha /admin/cron/*`；公开分发端点：`/upgrade-api/activate|check|token|download`（仅 config.distribute.enabled=true 的主站生效，否则 404）。

### 6.4 前台交互端点
`POST /booking`（需 consent+验证码；未成年人强制 guardian_consent）`POST /message` `POST /assessment/{slug}` `POST /events/{slug}/register` `/chat/start|send|escalate|contact|poll`（AI 客服 JSON）。

## 七、安全基线

- **认证**：password_hash（bcrypt）· 登录验证码 · 失败 5 次锁 15 分钟 · IP 20 次/10 分钟限流 · session_regenerate_id · 后台空闲超时（默认 7200s）· **TOTP 两步验证**（app/Core/Totp.php，RFC 6238 兼容主流验证器；开启后登录强制二段；丢失验证器需服务器置空 users.totp_secret）
- **RBAC**：roles.permissions JSON（"*"=全部）；Base 构造器按 $permNode 校验，openNodes=dashboard/notification 全员；越权 403。new Base(false) 仅用于 Dashboard/Setting 的工具式调用
- **CSRF**：全表单 `_token`；guard 失败 JSON 419；AJAX 用全局 CSRF_TOKEN
- **输出**：模板一律 e()；富文本入库过 Purifier 白名单；JSON-LD 全 HEX_TAG/APOS/QUOT（存储型 XSS 防线）
- **上传**：扩展+MIME 双白名单、内容扫描（<?php/script 拒绝）、哈希重命名、媒体库登记、nginx 禁执行
- **限流**：登录 20/10min · 留言 5/h · 预约 5/h · 量表 10/10min · 活动 5/h · 聊天 12/min · API 60/min（文件缓存滑动窗口）
- **响应头**：index.php 统一发射 XFO/nosniff/Referrer-Policy/CSP（frame-src 白名单 B站/腾讯视频；object-src none）/X-Powered-By 移除
- **AI 客服**：危机词→内置兜底话术（12356/110，代码级不可关）；escalated 会话 AI 静默；输出 markdownLite 先转义
- **审计**：audit_logs 全后台写操作（只读无删除入口），180 天滚动
- **邮件**：SMTP 头注入防御（CR/LF 拒绝 + RFC 校验）
- **升级包**：HMAC 签名校验 + 目录白名单（app/public/database）+ 拒绝 config/ 覆盖 + 自动备份回滚

## 七点五、插件体系（v2.0 完整接口）

| 能力 | 说明 |
|---|---|
| 清单 | plugins/{slug}/plugin.json：min_cms_version / dependencies / permissions / cron / admin_menu / settings / frontend_route / signature |
| 后台菜单 | manifest.admin_menu 由侧栏自动渲染；path 为空→插件设置页，非空→/admin/plugin/{slug}/{path} |
| 路由 | 后台 GET/POST `/admin/plugin/{slug}/{...}` → Plugin::admin{Path}()；动作 `POST /admin/plugin/{slug}/{kebab}` → `camelAction()`；前台 `/plugin/{slug}/...`（需 frontend_route=true） |
| 设置（接口数据） | manifest.settings 声明 → 后台自动渲染表单，保存到 settings 表；支持 text/password/number/textarea/switch/select |
| 任务 | cron[].schedule = minutely（/admin/cron/minute，每分钟）/ daily（/admin/cron/plugins，每日 4:20） |
| 启停校验 | 核心版本 ≥ min_cms_version；dependencies 全部启用；被依赖时禁止停用 |
| 卸载 | 停用 + 调 Plugin::uninstall() + 清理 sgroup='plugin:{slug}' 私有设置（共享配置如 mail.* / ai.* 保留） |
| 签名 | Ed25519（slug|version|author），author 须与 manifest 完全一致 |

**内置官方插件**：邮件通知 mail-notify（分钟任务：队列发送）、AI 智能客服 ai-concierge（钩子 ai.reply）、预约周报 booking-weekly-report（每日任务）。

**官方插件版本对照**

| 插件 | 版本 | 最近更新 | 签名 |
|---|---|---|---|
| 📮 邮件通知 `mail-notify` | **1.1.0** | **夜间免打扰时段（延迟不丢失）**、危机告警豁免、SMTP 智能提示、失败原因可见 | 官方 ✔ |
| 🎧 AI 智能客服 `ai-concierge` | 1.0.1 | Agnes AI 服务商预设（含中国区）、endpoint 兼容 /v1、改名「AI 客服配置」 | 官方 ✔ |
| 📊 预约周报 `booking-weekly-report` | 1.0.0 | 每周一运营数据周报 | 官方 ✔ |

> 插件改版本号后**必须重新签名**（签名内容含 version），否则后台显示「未签名」。

**危机兜底不可插件化**：`Chat@send` 在任何插件之前执行危机词检测并返回 12356/110 话术；插件停用亦生效（实测）。

## 八、授权与升级（发行侧）

**四档商业授权（v2.1 起）**

| 版本 | 有效期 | 咨询师 | 多门店 | 去版权 | 建议价 |
|---|---|---|---|---|---|
| 个人版 personal | 180 天 | 1 | — | 否 | 免费 |
| 工作室版 studio | 1 年 | 5 | — | 否 | ¥1,000/年 |
| 专业版 pro | 1 年 | 20 | ✔ | 否 | ¥3,000/年 |
| 永久版 lifetime | 永久 | 不限 | ✔ | ✔ | ¥9,800 |

授权码 `PSY-{PERSONAL|STUDIO|PRO|LIFETIME}-XXXX-XXXX-XXXX-签名`；trial 为未激活兜底（15 天）。
有效期由 `LicenseService::EDITION_DAYS` 决定；personal/trial 不绑定域名。

**信任模型 v2（Ed25519 远程激活）**
- 发行方密钥：tools/issuer_ed25519_private.b64（0600，仅本机/发行机）；公钥写入客户站 config.issuer_public_key（公开可分发）
- 远程激活：客户站 settings license.activate_url 指向发行方激活服务（协议：POST /activate {license,domain,nonce} → Ed25519 签名 token，7 天有效期）；客户站 sodium 验签+nonce 防重放；cron 每日心跳续期；**宽限 7 天后降级 ai_chat**（不锁站点）
- 客户站无私钥 → 改文件/改库均无法伪造授权
- 离线应急：`php tools/issuer.php offline <SECRET> <EDITION> <DOMAIN>`（HMAC 码，未配远程时本地校验）
- 插件官方签名：`php tools/issuer.php sign-plugin <slug> <version> <author>` → 写入 plugin.json.signature
- Mock 发行服务器：tools/issuer-server.php（php -S 127.0.0.1:8930，systemd psyisle-issuer；测试白名单 /tmp/issuer-licenses.json）——生产替换为真实 api 域名
- 完整性自校验：storage/integrity.json 基线（LicenseService/Totp/PluginManager/Api/config）——cron 每日校验+邮件告警；后台授权页可重建基线
- 构建升级包：`python3 tools/pack.py <x.y.z>`（HMAC 签名；绝不含 config/）
- 客户升级：后台「授权与升级」上传包 → 执行升级（备份→覆盖→迁移→失败回滚）

## 九、通知链路

NotifyService::push(type, title, body, ref) —— 站内 notifications + 邮件（urgent 同步直发，失败入队）。
触发：booking（新预约）/ message（新留言）/ chat_escalate（转人工/疑似未答）/ crisis（危机词命中）/ login_alert（连续失败≥3）/ system（活动报名等）/ upgrade / license。
管理员邮箱与 SMTP 在设置页配置；**mail.enabled 关闭时全链静默**（站内通知仍写）。
会员兜底短信自 v2.20.1 起由管理员人工补发，30 分钟未查阅只通知管理员，不再自动发送会员短信；详见 `docs/DEV-RULES.md` §五点五。

## 十、主题系统

4 套：healing（默认）/calm-blue/warm-terra/violet-dusk。settings site.theme → layout 加载 `assets/css/themes/{name}.css`（纯变量覆盖层：色板+hero 渐变+footer）。新增主题：复制任一覆盖文件改 :root 变量 + .hero/.brand-mark/.site-footer 三处渐变，设置页下拉加项即可。
缓存：settings_all 60s；home_data 300s——**Base afterSave/afterDelete 与 Setting@save 已自动失效**，手工清理在工具页。

## 十点四、通知免打扰（v2.1.1）

`Core\QuietHours`：所有主动推送渠道（邮件 / 微信服务号 / 短信）共用的静默时段策略。

| 配置 | 说明 |
|---|---|
| `notify.quiet_enabled` | 启用免打扰（默认关，ww 已开 22:00–08:00） |
| `notify.quiet_start` / `quiet_end` | 起止时间，支持跨午夜（start > end） |
| `notify.quiet_exempt_crisis` | 危机预警与登录告警豁免（默认开，**建议保持**） |

行为：静默期内通知**延迟入队**（`scheduled_at` = 时段结束），不丢失；cron 每分钟到点自动发出。
`QuietHours::resolve($type)` 返回 `[是否静默, 投递时间戳]`，新渠道直接复用。

## 十点五、安全强化（v2.1）

| 措施 | 说明 |
|---|---|
| 敏感配置加密 | SMTP 密码 / AI Key / API Token 以 `enc:`（AES-256-GCM）存库，读取自动解密，展示掩码；空值或全 `•` 表示不修改 |
| 会话绑定 | 后台会话绑定 UA 指纹，指纹变化强制登出（防会话劫持） |
| 登录全局限流 | 全站 15 分钟内失败 > 60 次 → 登录入口暂停 10 分钟（防分布式撞库） |
| 审计链式哈希 | `audit_logs.prev_hash/row_hash`，删除或篡改即断链；后台「操作审计」页显示校验状态 |
| 安全响应头 | 增加 Permissions-Policy（禁用摄像头/麦克风/定位/支付）、Cross-Origin-Opener-Policy |
| 设置提交保护 | 完整表单带 `_full_settings` 标记；部分提交不清零其他开关 |

密钥：`config.secret_key`（安装生成）；缺失时回退 `stats_salt` 派生（可解旧数据）。

## 十点六、开发规则与路线图

- **跨 agent 强制规则**：仓库根 `AGENTS.md`（铁律+规范+自检命令）
- **当前测试基线**：后台 45/0、前台 41/0、安全 26/0、CRUD 30/30、链接全部可达、SCHEMA 18/0、MANIFESTS_OK(17)；这是 v2.24.4 本次 WW 远端全量回归结果
- **技术详解与 14 条历史事故**：`docs/DEV-RULES.md`
- **发布清单与内部版本事实源（核心 14 项 + 插件 11 项）**：`docs/RELEASE.md`；仓库无根 `CHANGELOG.md`，包内 `version.json` 由构建工具生成
- **v2.x → v3.0 路线图**：`docs/ROADMAP.md`

## 十一、故障排查速查

| 症状 | 排查 |
|---|---|
| 前台 500 | storage/logs/当日-error.log；常见：DB 凭据（config.php）/新文件未部署 |
| 后台页面变 404 | 通常是控制器 fatal 被 error.php 渲染成 404 外观——先看日志 UNCAUGHT/ROUTE |
| 改了内容前台没变 | home_data 缓存 300s（正常），确认已部署缓存失效逻辑；急清在工具页 |
| 邮件发不出 | 设置页「发送测试邮件」看报错；QQ/163 用授权码非登录密码；查 email_queue.error |
| 表单提交 403 | 宝塔 WAF 拦截（多为含 script/敏感词 payload）；富文本测试勿放 script 标签 |
| 表单提交 419 | CSRF 过期，刷新页面重试 |
| 管理员被锁 | 等 15 分钟或：`UPDATE users SET failed_count=0, locked_until=NULL WHERE username='…'` |
| TOTP 丢失 | 服务器：`UPDATE users SET totp_secret=NULL WHERE username='…'` |
| sitemap/llms 内容过旧 | 实时生成，settings 缓存 60s；站长平台重新提交 |
| 微信登录失败 | 后台「微信登录」核对回调地址与绑定列表；回调 flash 带报错码（40029=code 无效、40013=appid 不匹配）；确认公众号「网页授权域名」已配置并放了 MP_verify 文件 |
| 支付未入账 | 「微信支付」后台对订单行点「查单」实时核对微信侧状态；paid 未入账点「补入账」；回调失败检查 verify_mode/公钥 ID 与商户平台是否一致（nginx 日志看回调请求） |
| 签署链接打不开 | 「知情同意签署」后台核对链接与记录状态；待签署可撤销重发；已过期显示过期页；7 天有效期 |
| 哈希链校验断链 | 后台签署页顶部链状态变红 = 存证被外部改动/删除；从备份恢复 consent_signatures 表；系统自身只追加不删除 |
| 门店选择不出现 | stores-pro 需要授权含 multisite 特性（专业版/永久版）且门店状态=启用 ≥1 家 |
| EAP 入口 404 | eap-pro 插件设置「开启企业 EAP 入口」未打开；或插件未启用 |
| 会员通知没收到 | 「微信中台/通知台账」查 member_notify_log：skip 原因（无邮箱/偏好关闭/未绑微信）；邮件 fail 查 SMTP 配置 |
| 会员密码问题 | 后台暂无改密入口（会员自助在 /member/settings）；未设密码的会员用查询码登录后自行设置 |
| 升级后异常 | upgrade_history 看 result；restoreBackup 自动回滚；再人工覆盖 upgrade-backup zip |

## 十一（补）、后台导航分组（v2.8.1）

侧栏结构（插件菜单经 `manifest.admin_menu.group` 自动归入对应组尾，未声明 group 的进「插件扩展」）：

| 分组 | 内容 |
|---|---|
| 概览 | 仪表盘、通知中心（未读徽标） |
| 业务 | 预约（待办徽标）、留言、AI 会话、心理量表、活动、· 知情同意签署（consent-pro） |
| 会员 | 会员管理 |
| 机构 | 咨询师、服务项目、· 门店管理（stores-pro） |
| 内容 | 文章、栏目、单页、常见问题、会员心声、媒体库 |
| 呈现 | 轮播图、导航菜单、友情链接 |
| 渠道与自动化 | · AI 客服、邮件通知、微信通知、短信通知、预约周报 |
| 专业能力 | · 量表扩展、排班考勤、课时包 |
| 微信生态 | · 微信登录、微信支付 |
| 系统 | 用户与角色、系统设置、插件管理、运维工具、操作审计 |
| 商业 | 授权与升级、升级分发主站（仅 distribute.enabled 站显示） |
| 帮助 | 使用手册、查看前台 |

会员中心（前台）结构（v2.8.1）：问候区（含插件徽标，如「已绑定微信」）→ 待办条（member.todo 钩子，如待签署）→ 功能卡片网格（核心预约/测评 + 插件卡片按 sort 排序：签署 30 / 课包 40 / 购买 45 / 绑定 60）→ 兑换码 → 近期预约。

## 十一（补）、后台两级导航（v2.8.3，EduSoho 式）

- **顶部一级域**（8 个，随页面自动高亮）：首页 / 教务 / 会员 / 机构 / 内容 / 运营 / 微信 / 系统。
- **左侧二级目录**：显示当前域的子菜单（含待办徽标与分节小标题）。
- **插件归域**：`admin/layout.php` 的 `$pluginDomain` 映射表（slug → 域），新插件在此加一行即可归位；未映射的插件归入「系统 → 插件扩展」。manifest 的 `admin_menu.group` 仍保留作分组语义。
- 域定义（items/section/徽标）也在 layout.php 的 `$navDef`；左侧品牌下方显示当前域标题。
- 快捷查找条（客户查找/高频直达）保留在正文顶部。

| 顶部域 | 二级内容 |
|---|---|
| 首页 | 仪表盘、通知中心、使用手册 |
| 教务 | 预约、留言、AI 会话、心理量表、活动、· 知情同意签署 |
| 会员 | 会员管理、· 企业 EAP |
| 机构 | 咨询师、服务项目、· 门店管理 |
| 内容 | 内容管理：文章/栏目/单页/FAQ/心声/媒体库 + 站点呈现：轮播/导航/友链 |
| 运营 | 渠道与自动化：AI 客服/邮件/短信/周报 + 专业能力：量表扩展/排班考勤/咨询套餐 |
| 微信 | 服务号通知、微信登录、微信支付 |
| 系统 | 用户角色/设置/插件管理/运维工具/审计 + 商业授权：授权与升级（分发站另见升级分发主站） |

## 十二、版本历史（对外呈现的演进节奏）

| 版本 | 日期 | 要点 |
|---|---|---|
| v1.0.0 | 2025-03-05 | 系统诞生：内容/预约/留言/AI 客服/SEO-GEO/授权，发行链路同日验证 |
| v1.1.0 | 2025-05-18 | 量表 PHQ-9/GAD-7、四主题、预约时段、确认邮件、心声、真编辑器、人工接管、RBAC |
| v1.2.0 | 2025-07-22 | 行业合规：知情同意+监护同意、来源归因、危机提示条、预约提醒 cron |
| v1.3.0 | 2025-09-28 | 活动报名、公开问答、标签聚合、咨询师视频+14 天日历、SAS/PSQI |
| v1.4.0 | 2025-11-30 | 集成层：JSON API v1、/health、全量备份、SMTP 防御、cron Header |
| v1.5.0 | 2026-02-14 | 第一梯队：Hook、定时发布、修订回滚、回收站、缩略图、导入导出、自动内链 |
| v1.6.0 | 2026-04-26 | 插件体系+防破解：官方签名、Ed25519 远程激活、完整性校验、TOTP、门禁 |
| v1.7.0 | 2026-06-20 | AI 治理：提示词管理、多邮箱通知、访客提问邮件、人工接管规则、会话清理 |
| v1.8.0 | 2026-08-12 | 效率包：客户查找、内联状态、保存并继续、快捷模板、自动暂存 |
| v1.9.0 | 2026-10-08 | 定名一二三、发行封装、三站矩阵、导航治理 |
| v2.0.0 | 2026-11-02 | 插件接口完整化 + 邮件/AI 插件化；危机兜底留核心 |
| **v2.0.1** | **2026-11-10** | 插件框架化、Agnes 接入、品牌统一、页脚版本标识+版权开关 |
| v2.1.0 | 2026-11-10 | 商业包装与安全强化：四档授权、配置加密、会话绑定、登录全局限流、审计链式哈希 |
| v2.2.0 | 2026-11-11 | 通知渠道扩展：微信服务号通知 + 短信通知插件、授权用量面板 |
| v2.3.0 | 2026-11-11 | 会员自助中心：手机号+查询码/短信码登录、我的预约/测评/隐私中心 |
| v2.4.0 | 2026-11-11 | 量表扩展包 assessment-pro（SCL-90 官方 90 题/SDS/SAS/PSQI/SSRS）+ 报告页/短链 |
| v2.5.0 | 2026-11-11 | 排班与考勤 schedule-pro：例外排班/冲突拦截/考勤标记/课时统计 |
| v2.6.0 | 2026-11-11 | 课时包与优惠券 packages-pro：售课核销/兑换码/转介绍/对账流水 |
| **v2.7.0** | **2026-11-12** | 微信生态补全：wechat-login（扫码+网页授权，会员一体化）、wechat-pay（V3 Native/JSAPI，课包自动入账）；packages-pro 1.0.1 修复「我的课包」页 |
| **v2.8.0** | **2026-11-15** | 生态扩展：consent-pro（知情同意电子签署+哈希链存证）、stores-pro（多门店，multisite 门控）、eap-pro（企业 EAP 入口）；核心加四处 Hook 扩展点；修复 v2.5.0 预约 $id 断链 |
| **v2.8.1** | **2026-11-15** | 体验整理：后台导航重组（11 组，13 插件按职能归组）；会员中心重构（待办条/卡片排序/绑定徽标）；新增 member.todo / member.badges 钩子；顶部导航「会员中心」→「会员」 |
| **v2.8.2** | **2026-11-15** | 行业术语更新：「课时/课包」→「咨询套餐/咨询次数」（计费单位为次，每次约 1 小时；机构可在套餐名自定义小时数）；排班「课时统计」→「咨询时长统计」；packages-pro 1.0.3 / wechat-pay 1.0.2 / schedule-pro 1.0.2 |
| **v2.8.3** | **2026-11-15** | 后台两级导航：顶部 8 一级域（首页/教务/会员/机构/内容/运营/微信/系统）+ 左侧二级目录；13 插件按职能归域；域映射表在 admin/layout.php（$pluginDomain） |
| **v2.8.4** | **2026-11-15** | 域总览页：8 个顶部域各配数据总览落地页（/admin/area/{domain}，Admin\Area 控制器） |
| **v2.9.0** | **2026-11-15** | **会员体系补齐：注册/密码登录（手机号+密码，零外部依赖）、会员通知总线（邮件全量+微信双发+偏好路由+全通道台账 member_notify_log）、咨询套餐会员自助下单（package_requests 线下收款申请）；微信接入路线定为「仅 CMS 直连」（wechat-login/pay/notify 三插件，凭据由机构自有）** |
| **v2.13.1** | **2026-11-17** | **反馈 API 首批 9 个 bug 修复**：#1 404 静态调用、#68 View 列表注入、#5 consent-pro 清单 BOM/转义、#2 错误处理尊重 @、#3 宝塔 LICENSE 精确匹配、#4 活动列表 reg 缺键、#71 首页统计去编造默认值、#69 品牌徽章/页脚去重、#70 移动端头部；deploy 新增 plugin.json 门禁。v2.10–v2.13 迭代见 cms 官网 changelog |
| **v2.16.0** | **2026-11-18** | **反馈闭环落地（#73/#72/#67/#74）**：克隆/换身份向导、演示内容治理（清除演示+审计）、内置反馈客户端（后台 /admin/tools/feedback，密钥 feedback.site_key/site_secret 加密存储）、导入器 CLI；强制会员登录三开关默认关；sms-notify 1.1.2 / wechat-notify 1.0.2 重签登记。发行 md5 846c70fa…；GitHub 同步完成 |
| **v2.17.0** | **2026-11-19** | **预约/排班深化**：统一槽位服务 BookingSlots（切片/例外/请假/提前截止/缓冲/每日上限/分时定价，三处单一事实来源）；咨询师日历可点击时段→预约确认页（锁时段+价格预览+二次重查）→会员需支付直跳微信收银台；原表单定位「预约申请通道」；通知双推统一出口（四态双侧三通道+咨询完成+开始前 30 分钟）；修复提醒置位顺序/例外排班状态值/确认页列名（验收修复）；rtc-pro 1.1.1 · schedule-pro 1.0.3 · wechat-notify 1.0.4。发行 md5 3d1071ff…（min_from=2.16.0，含 register NULL-slug 重复行修复）；GitHub v2.17.0 同步（36 文件+运维残留清理说明） |
| **v2.17.1** | **2026-11-19** | **短信模板变量适配**：阿里云禁用 site 作模板变量名，短信参数键 site→store，配套已过审通用通知模板 SMS_512638908（告警/会员通知/提醒全路径）；sms-notify 1.1.4（变量适配）、wechat-notify 1.0.5（会员模板消息中转短链 /n/{token} 点击回执）；发行 md5 92cf6622…（min_from=2.17.0）；GitHub v2.17.1 同步 |
| **v2.17.2** | **2026-11-19** | **通知规则落地 + 密码策略统一**：通道定位收敛（站内信=台账恒发/微信=主力/邮件=三用途）；短信兜底制（30 分钟无查阅回执自动补发，notify_escalations 幂等建表，查阅三信号统一 /n/{token} 短链）；PasswordPolicy 统一密码策略（8 位大小写+数字，管理员放宽/会员收紧）；修复会员站内信中心 404（v2.14 遗留）。发行 md5 f61d2364…（min_from=2.17.1）；GitHub v2.17.2 同步 |
| **v2.24.4** | **2026-11-25** | **RTC 浏览器验收补丁**：rtc-pro 1.1.5 清空本地视频占位文字、修复移动端竖排残留；轨道释放改用 `close/stopPlay`；离开频道 Promise 显式兜底。核心 md5 `a22aa781…`（min_from=2.17.2）；RTC md5 `82fa1f9d…`（min_from=2.17.0）；WW 已部署登记，全量回归及 RTC 桌面/移动端真实浏览器验收通过；GitHub v2.24.4 同步（公开包 md5 d1fbc11b…）。 |
| **v2.24.3** | **2026-11-25** | **个案照护与 RTC 安全修复**：`Admin\Casebook::care()` 补齐 `activePlans`；rtc-pro 1.1.4 删除公开 `front_diag`/`views/diag.php`，SDK 错误文本改 `textContent`、缓存版本改 1.1.4，保留阿里云 RTC 3.0 MCU `audio` 主动订阅、`remoteAudioTrack` 与重连修复；构建器排除 `.DS_Store`，恢复微信登录官方签名。核心 md5 `fe075e90…`（min_from=2.17.2）；RTC md5 `af398ac4…`（min_from=2.17.0）；WW 已部署登记，远端全量回归通过。 |
| **v2.24.2** | **2026-11-24** | **后台体验优化（纯界面）**：列表操作列降噪（「下线/启用」「删除」改轻量文字链接、删除二次确认）、表格视觉（斑马纹/悬浮高亮/行距加大/表头吸顶）、「一键清理缓存」提到运维工具页顶部。发行 md5 45840838…；GitHub v2.24.2（基础包 64b38bf4…） |
| **v2.24.1** | **2026-11-24** | **账号体系热修**：咨询师入驻（从会员添加，一键建档 + 自动建工作台账号）、微信扫码识别咨询师直进工作台（wechat-login 1.0.4）、一键清理缓存（应用缓存 + opcache + 阿里云 CDN 刷新）、微信绑定发码端点恢复、会员中心测评入口。发行 md5 5ee773f5… |
| **v2.24.0** | **2026-11-24** | **账号体系正规化**：统一前台登录分流（会员 / 咨询师）、预约强制登录、废除查询码查询与短信登录、备份保留 30→7 天、专业版量表选项格式修复。发行 md5 eed37421… |
| **v2.23.0** | **2026-11-24** | **P2 随访计划 + 经营驾驶舱 + 插件安装令**：`followup_plans` 周期随访、驾驶舱指标一页（CSV 导出）、《指标字典》冻结、插件下载按授权位校验（`internal_secret` 签名）。发行 md5 927bee2d…；新表迁移 `20261124_0005_followup_plans.sql` |
| **v2.22.2** | **2026-11-24** | **定时自动备份**：`/admin/cron/backup`（cron_secret 鉴权，kind=db/full），备份服务统一、按钮与 cron 共用；端点回 `exec` 自诊断。发行 md5 670a57bd… |
| **v2.22.1** | **2026-11-24** | **备份可用性补丁**：后台备份不再依赖外部命令——`disable_functions` 禁用 `exec/shell_exec/proc_open` 的环境（宝塔默认）此前点备份直接报错，现先探测再选路径，可用走 `mysqldump`、禁用或失败切内置纯 PHP 导出（`app/Services/PhpDbDumper.php`，PDO 读结构+数据、主键分页、边写边压缩），压缩不依赖 `gzip`；修复双层 gz（导出自身已压缩 + 外层再压，解压一次仍是压缩流，恢复误判坏包），现导出纯 SQL、外层只压一次；恢复路径实测（独立库完整导入、71 张表 / 4484 行逐表比对一致、演练库完整回滚），确认可恢复而非「生成成功」；前台资讯列表分页未定义变量告警修复。**运维要点**：备份验证的唯一硬标准是真导入一次并逐表对行数，不是「生成成功」。发行 md5 c4e0906e…；GitHub v2.22.1 同步（基础包 md5 35c1ee17…） |
| **v2.22.0** | **2026-11-24** | **个案主线**：新增 `cases`/`case_sessions`（同刻仅一个 active 案，开案须有预约关系）、`case_records`/`case_record_versions`（草稿可改、锁定即定稿且升版、修订链 `amends_id`、快照只增不改、默认不可见）、`ai_tasks`（AI 只产草稿，三重闸门 + 外发脱敏）、`crisis_events`/`case_homeworks`/`case_followups`（未闭环危机常驻工作台，闭环必填结论；作业逾期、随访到期提醒）；历史咨询小结转只读；引入轻量迁移器（`database/migrations/*.sql` + `schema_migrations`，bootstrap 自动跑，可重复且并发安全）。运维要点：迁移失败只记日志不阻塞访问；`schema.sql` 由 migrations 生成，用 `cms-ops/tests/schema_check.php` 校验列+索引。插件随版 miniapp-pro 1.1.5 / sms-notify 1.1.6。发行 md5 94aa9d8b…；GitHub v2.22.0 同步（基础包 md5 246be5f6…） |
| **v2.21.0** | **2026-11-24** | **安全加固**：咨询师数据域隔离（写记录须有预约关系，越权记日志）、会员登录会话轮换（五入口统一重建 session）、备份 AES-256-CBC 加密（`PSYENC1` 头 + 随机 IV，凭据文件导出，后台标注加密状态）、记录查阅留痕、AI 外发脱敏（手机号/身份证/邮箱/卡号，可全关外发）、`/install` 全路径 404、后台控制器目录诊断脚本迁出。**运维注意**：v2.21.0 之前的明文备份 `.sql.gz`/`.zip` 含会员手机号与咨询记录正文，需按「明文·待清理」清掉。发行 md5 7e579441…；GitHub v2.21.0 同步（基础包 md5 5dc8f7b2…） |
| **v2.20.3** | **2026-11-23** | **插件授权位与升级分发修复**：授权位三通道写入（支付回调/主动查单/定时补签）幂等，以 `plugin_entitlement_grants` UNIQUE(order_id,slug) 保证「同一订单跑 100 次结果不变」；`latestCore($clientVer)` 无匹配返回 null（绝不 fallback 到最高版本包）；`register-release` 只退役 active 不碰 ladder；低版本客户走 ladder 阶梯包（当前 2.17.1 / 2.17.2）逐级升。发行 md5 6fc7aae2…；GitHub v2.20.3 同步（基础包 md5 f11580da…） |
| **v2.20.0** | **2026-11-22** | **修复与会员体验**：微信绑定断点修复（intent 分流+直绑当前会员，wechat-login 1.0.2/wxhub-client 1.1.1）；Mailer SMTP context 根因修复+空授权码快速失败；我的预约待付费/去支付视图自算；AssessmentService 计分双结构归一化；MemberService::updateProfile。插件同步发行：miniapp-pro 1.1.1（会员全模块进小程序）、rtc-pro 1.1.3（电话进房+统一时间窗）。发行 md5 3466c359…（min_from=2.17.2）；GitHub v2.20.0 同步（基础包 md5 1cccb42b…）；授权发行服务 systemd 化重建（/tmp 版散佚致 AI 客服降级事故已修复） |
| **v2.19.0** | **2026-11-19** | **专业内核 + AI Agent 就绪**：内核加固（事务/死锁自动重试/断线重连/慢查询、缓存单飞防击穿、上传 EXIF 剥离、Logger warning、`GET /healthz`）；AI 就绪（公开只读 JSON API `/api/public/*`、MCP Server `/mcp` 六工具、llms.txt、JSON-LD、robots AI 爬虫分区），新开关默认关闭；新插件 miniapp-pro 1.0.0、wechat-pay 1.2.1（小程序 JSAPI）。发行 md5 7a513a85…（min_from=2.17.2）；验收修复 MCP 关闭态 403（原 200）；GitHub v2.19.0 同步（基础包 md5 16ab6f32…） |
| **v2.18.0** | **2026-11-19** | **时段预约免确认直付**（用户拍板）：免费时段提交即生效；需支付时段直跳收银台、支付成功自动确认（wechat-pay 1.2.0 applyCredit + 咨询师到账通知补齐 + 会员单条告知 + 收银台文案适配）；申请通道维持机构确认；后台列表标注免确认通道。发行 md5 dba06b8f…（min_from=2.17.2）；wechat-pay 1.2.0 md5 d9937ecd…；GitHub v2.18.0 同步 |
| v2.9.1 | 待规划 | 签署归档增强、门店深度、EAP 完整版、忘记密码（邮件找回） |
| **v3.0.0** | **2028-03（稳定商用版）** | 架构定型：多租户 SaaS、企业 EAP、开放插件市场 |

> 版本日期编排规则见 docs/RELEASE.md 第六节：早期密集迭代（约 2 个月一版），进入稳定期后拉大到 4–6 个月一版；v3.0.0 为稳定商用版节点。

## 十三、待办与路线

- 用户侧：AI API Key、SMTP、admin 改密、备案/真实信息、演示数据替换
- **微信生态联调（等用户提供凭据）**：① 开放平台 AppID/Secret（PC 扫码登录）；② 认证服务号 AppID/Secret（网页授权 + 模板消息，可复用微信通知插件配置）；③ 微信商户号（mchid/API 证书序列号/APIv3 密钥/apiclient_key.pem + 微信支付公钥）。填入各插件后台即生效
- 技术路线（已预留）：i18n 路由 · 短信/微信通知通道 · 人工接管 WebSocket · SaaS 多租户 · 拼音 slug · 量表结果关联预约
- 服务器：SSL 续期（2027-02 前）；CentOS 7 EOL 迁移评估（2026-06 已 EOL，注意内核安全更新）

---

## 十四、备份与恢复（v2.21.0 起加密）

### 14.1 变更说明

v2.21.0 之前备份是明文 `.sql.gz` / `.zip`，内含会员手机号、姓名、咨询记录、知情同意正文；文件一旦被拷走即整体泄露。现改为：

- 落盘前一律 **AES-256-CBC** 加密，文件名 `*.enc`，权限 `0600`，属主 `www`。
- 格式：`PSYENC1`（7 字节魔数）+ 16 字节随机 IV + 密文（PKCS7）。
- 密钥：`config/config.php` 的 `secret_key` 经 `hash('sha256','backup|'.$secret)` 派生 32 字节；缺失时回退 `stats_salt`。
- 导出凭据：走临时 `--defaults-extra-file`（0600，执行完立即删除），**不再把数据库密码拼进命令行**（原先同机 `ps` 可见）。
- **v2.22.1 起不再强依赖外部命令**：宝塔等环境默认在 `php.ini` 的 `disable_functions` 里禁用 `exec / shell_exec / proc_open`，此时 `mysqldump` 与 `gzip` 都调不动。现先探测 `exec` 是否可用：可用走 `mysqldump`；被禁用或执行失败自动切到内置纯 PHP 导出（`app/Services/PhpDbDumper.php`，PDO 读结构+数据、主键分页、边写边压缩），压缩同样不依赖 `gzip` 命令。两条路径产出一致：外层只有**一层** gzip，解压一次即标准 SQL。
- 本地滚动清理 **7 天**，只清本模块产物（`db-*`、`full-*`、`backup-*`），不动 `upgrade-backup-*`。
- 升级回滚包 `upgrade-backup-*.zip` 含 `config/config.php`，创建后立即 `chmod 0600`。

### 14.2 管理台操作

后台「工具 → 数据库备份 / 全量备份」，产出：

| 类型 | 文件名 | 内容 |
|---|---|---|
| 数据库备份 | `db-YYYYmmdd-HHMMSS.sql.gz.enc` | 全库 dump，gzip 后加密 |
| 全量备份 | `full-YYYYmmdd-HHMMSS.zip.enc` | 全库 dump + `public/uploads`，zip 后加密 |

列表页对未加密的旧文件标「明文·待清理」，迁移完成后用 14.4 的方式转换或删除。

### 14.2.1 定时自动备份（v2.22.2 起）

后台按钮只能手动点；要做「无人值守」，在服务器 crontab 加两行（`cron_secret` 见 `config/config.php`）：

```bash
# 每日 04:30 数据库备份（加密 .enc，本地保留 7 天）
30 4 * * * curl -s -H 'X-Cron-Secret: <cron_secret>' 'https://站点域名/admin/cron/backup?kind=db' >/dev/null 2>&1
# 每周日 04:50 全量备份（库 + uploads）
50 4 * * 0 curl -s -H 'X-Cron-Secret: <cron_secret>' 'https://站点域名/admin/cron/backup?kind=full' >/dev/null 2>&1
```

端点返回 JSON（`ok/kind/msg/file/bytes/exec`），`exec` 会告诉你是走 `mysqldump` 还是内置 PHP 导出——用于确认当前环境是否禁用了外部命令。无密钥一律 403。

### 14.2.2 异地副本（发行方自用，客户可按同思路配置）

`/root/bin/offsite-backup.sh`：把最近 3 天的 `db-*.enc` / `full-*.enc` 推到新加坡主机 `ubuntu@161.118.234.81:~/psyisle-backups`，逐文件校验字节数，远端保留 30 天。仅推密文，异地也无明文。密钥 `/root/.ssh/id_offsite_backup`，对端 `authorized_keys` 已加 `restrict,from="106.52.109.65"` 限制。定时：每天 05:05（在 04:30 备份之后）。

### 14.2.3 整库演练与月度自动演练（发行方已建，客户可复制思路）

- **专用演练库**：`psyisle_drill`（按宝塔面板规则创建并在面板登记，id=20，备注「非生产」）。**绝不触碰生产库**。
- **一键整库演练**：`/root/bin/db-restore-drill.sh` —— 取最新 `db-*.enc` → 解密 → 清空演练库 → 整库导入 → 与生产库逐表比行数 → 写 `/root/restore-drill.log`。
- **月度自动**：cron `30 5 1 * *`（每月 1 日 05:30）。判据：演练库行数**多于**生产库视为异常（生产库在备份后新增属于正常漂移；`users` 允许多 3 个以内，那是回归探针账号）。
- **WorkBuddy 已完成实测**（2026-11-24）：71 表全部建成、4 条迁移齐备；临时把 ww 配置指向演练库后站点前台/健康检查全部 200 且能正常读写（visit_logs 有写入），随后配置按 md5 精确还原。即「解密 → 建库 → 导入 → 站点立即可用」全链路验证通过。
- 表级小演练（不动库结构）：`cms-ops/tools/restore-drill.sh`（抽几张表改 `zzr_` 前缀导入比对后即删）。

### 14.3 恢复步骤（服务器上执行）

```bash
cd /www/wwwroot/ww.123l.cn
# 1) 解密（用应用自身的密钥派生，不要手工算）
/www/server/php/74/bin/php -r 'require "app/bootstrap.php"; $ok=app\Services\BackupCrypto::decryptFile($argv[1],$argv[2]); fwrite(STDERR,$ok?"DECRYPT_OK\n":"DECRYPT_FAIL\n"); exit($ok?0:1);' \
  storage/backups/db-20261124-010000.sql.gz.enc /tmp/restore.sql.gz
# 2) 校验压缩包完整
gzip -t /tmp/restore.sql.gz
# 3) 解压并导入（先确认目标库名！）
gunzip -c /tmp/restore.sql.gz > /tmp/restore.sql
mysql -u psyisle -p psyisle < /tmp/restore.sql
# 4) 清理
shred -u /tmp/restore.sql /tmp/restore.sql.gz 2>/dev/null || rm -f /tmp/restore.sql /tmp/restore.sql.gz
```

全量包（`.zip.enc`）先解密成 `.zip`，再 `unzip` 到临时目录，`database.sql` 按上面导入，`uploads/` 覆盖回 `public/uploads/`。

### 14.4 明文旧备份转换

```bash
/www/server/php/74/bin/php -r 'require "app/bootstrap.php"; $s=$argv[1]; $e=$s.".enc"; $ok=app\Services\BackupCrypto::encryptFile($s,$e) && app\Services\BackupCrypto::decryptFile($e,"/tmp/chk"); echo $ok?"OK\n":"FAIL\n";' \
  storage/backups/backup-20261115-230256.sql.gz
# 校验 /tmp/chk 与原件 md5 一致后再删明文
```

### 14.5 已知边界

- 密钥与备份同机（`config.php` 0600）：防"文件被拷走/被下载"，**不防"整机被控"**。
- 异地副本为独立脚本方案（见 §14.2.2），不由后台备份模块直接管理；不能替代本地 `storage/backups` 的 7 天滚动保留。
- **恢复演练已完成**（WorkBuddy，2026-11-24；见 §14.2.3），不再列为待办；首次真实恢复或重大备份格式变更前仍应在测试库演练。
- 密钥丢失 = 备份永久不可解，`config.php` 必须与备份分开保管。
