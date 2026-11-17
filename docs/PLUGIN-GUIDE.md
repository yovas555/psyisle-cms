# 一二三心理咨询网站系统 · 插件开发规则

> 适用版本 ≥ v2.0.0（插件接口已完整化）· 文中「强制」项由系统在运行时校验

## 一、目录结构（强制）

```
plugins/
└── {slug}/                    # 小写字母/数字/连字符（系统会清洗非法字符）
    ├── plugin.json            # 清单（强制，缺失则不加载）
    ├── Plugin.php             # 引导类（强制）：namespace plugins\{SlugCamel}; final class Plugin
    ├── views/                 # 插件视图（可选）：PluginManager::view('slug','tpl',$data)
    └── assets/                # 静态资源（可选，自行输出）
```

命名空间映射：`plugins\{slug 去连字符驼峰}\` → `plugins/{slug}/`。
例：`mail-notify` → `namespace plugins\mailnotify;`

## 二、plugin.json 完整字段

```json
{
  "slug": "mail-notify",
  "name": "邮件通知",
  "version": "1.0.0",
  "author": "PsyIsle Official",
  "description": "…",
  "min_cms_version": "1.9.0",
  "dependencies": ["other-slug"],
  "permissions": ["hooks","cron","admin_menu","settings","routes","frontend"],
  "cron": [
    {"schedule": "minutely", "action": "flushQueue"},
    {"schedule": "daily",    "action": "dailyJob"}
  ],
  "admin_menu": {"title": "邮件通知", "icon": "📮", "path": ""},
  "settings": [
    {"key":"mail.enabled","label":"启用邮件通知","type":"switch","default":"0","help":"…"},
    {"key":"mail.host","label":"SMTP 服务器","type":"text","placeholder":"smtp.qq.com"},
    {"key":"mail.port","label":"端口","type":"number","default":"465"},
    {"key":"mail.encryption","label":"加密方式","type":"select","default":"ssl",
     "options":{"ssl":"SSL（465）","tls":"STARTTLS（587）","none":"不加密"}},
    {"key":"mail.pass","label":"SMTP 密码","type":"password"},
    {"key":"notify.admin_email","label":"接收邮箱","type":"textarea","rows":2}
  ],
  "frontend_route": true,
  "signature": "<base64 Ed25519>"
}
```

### 字段说明

| 字段 | 作用 |
|---|---|
| `min_cms_version` | 启用时校验核心版本，低于此值拒绝启用并提示 |
| `dependencies` | 依赖插件必须已启用；**停用被依赖插件也会被阻止** |
| `cron[].schedule` | `minutely`（每分钟，`/admin/cron/minute`）或 `daily`（每日，`/admin/cron/plugins`） |
| `admin_menu` | 后台侧栏菜单项；`path` 为空 → 插件设置页，非空 → `/admin/plugin/{slug}/{path}` |
| `settings` | 后台可视化配置项；`type` 支持 text / password / number / textarea / switch / select |
| `frontend_route` | 为 `true` 时才允许前台 `/plugin/{slug}/…` 访问 |
| `signature` | 发行方 Ed25519 签名，后台显示「官方签名」 |

## 三、Plugin.php 引导类

```php
<?php
namespace plugins\mailnotify;

use app\Core\Hook;

final class Plugin
{
    public static function boot(array $meta): void
    {
        Hook::on('notify.push', [self::class, 'onPush']);   // 注册钩子
    }

    public static function onPush(array $ctx): array        // 监听器：返回非 null 会替换 payload
    {
        // …
        return $ctx;
    }

    public static function flushQueue(): array              // 与 manifest.cron 对应；返回值写入 cron 日志
    {
        return ['sent' => 3];
    }

    public static function adminIndex($req): void            // 后台首页（覆盖默认设置页）
    {
        \app\Core\PluginManager::view('mail-notify', 'admin', ['kb' => []]);
    }

    public static function admin_stats($req): void          // admin_menu.path="stats" → 后台子页
    {
    }

    public static function addKb($req): void                // POST /admin/plugin/{slug}/add-kb（驼峰）
    {
    }

    public static function frontIndex($req): void          // 前台 /plugin/{slug}（需 frontend_route）
    {
    }

    public static function front_api($req): void           // 前台 /plugin/{slug}/api
    {
    }

    public static function uninstall(): void               // 卸载钩子（清理自建表等）
    {
    }
}
```

## 四、可用的钩子（核心触发点）

| 钩子 | 触发时机 | 载荷 |
|---|---|---|
| `notify.push` | 任何通知产生（预约/留言/提问/转人工/活动/系统/授权） | `{type,title,body,ref,urgent}` |
| `ai.reply` | 访客在 AI 会话提问、非危机、非人工接管 | `{session_id,msg}`；监听器返回 `{reply,type}` |
| `article.published` | 文章发布 | 文章 id |
| `article.scheduled` | 文章进入定时状态 | 文章 id |
| `booking.created` | 新预约入库 | 预约 id |
| `content.import` | 内容导入完成 | 计数数组 |

## 五、可用的核心能力

| 能力 | 用法 |
|---|---|
| 数据库 | `app\Core\Database::fetchAll/fetch/value/insert/update/delete/run`（预处理，**禁止拼接 SQL**） |
| 设置 | `setting('key')` / `\app\Core\Model::saveSetting('key',$v,'plugin:{slug}')`；插件配置建议读 manifest 声明项 |
| 邮件 | `app\Core\Mailer::send($to,$subject,$html)` / `Mailer::enabled()` |
| 视图 | `\app\Core\PluginManager::view($slug,$tpl,$data)`（输出一律 `e()`） |
| HTTP | `\app\Core\Http::get/post` |
| 钩子 | `Hook::on($name,$callable)`；`Hook::fire($name,$payload)` |
| 日志 | `\app\Core\Logger::info/error('channel','msg',array $ctx)` |
| 缓存 | `\app\Core\Cache::remember/forget` |

## 六、安全规则（强制）

1. **禁止修改核心文件**（app/、config/、public/index.php）——升级会被覆盖，改动必丢。
2. **禁止** `eval` / `assert` / `create_function` / 动态 include 用户输入路径。
3. 所有 SQL 走 Database 预处理；所有输出走 `e()`；富文本入库过 `Purifier::clean`。
4. 上传必须复用 `app\Core\Uploader`。
5. **不得绕过**：危机干预兜底、CSRF、限流、审计日志、RBAC、授权校验。
6. 涉及来访者数据的插件须在 description 声明用途，遵守隐私政策与保密伦理。
7. 违反上述规则的插件，发行方有权移出官方签名名单。

## 七、后台管理约定

- 插件配置项写进 `settings` 数组即可，后台「插件管理 → 配置」自动生成表单（**接口数据在后台可编辑**）。
- 需要自定义界面时实现 `adminIndex($req)`，并用 `PluginManager::view()` 渲染自己的视图。
- 子页动作：`POST /admin/plugin/{slug}/{kebab-action}` → 调用插件静态方法 `{camelAction}($req)`（核心已做 CSRF 校验与审计）。

## 八、发布与签名

1. 自测：启停不报错、boot 无异常、cron 正常返回。
2. 签名：`php tools/issuer.php sign-plugin <slug> <version> "author 原样"` → 写入 `plugin.json.signature`
   ⚠ **author 必须与 manifest 中的 `author` 完全一致**（含空格），否则验签失败。
3. 打包整个 `{slug}/` 目录交付；客户上传到 `plugins/` 后在后台「插件管理」启用。

## 八·A、通知渠道抽象（v2.2.0）

核心 `NotifyService::push()` 分发顺序：站内通知中心 → `notify.push` 钩子（mail-notify 兼容模式）→ **已注册渠道**（`NotifyChannel` 接口）。
插件在 `Plugin::boot()` 里 `NotifyService::registerChannel($impl)` 注册；实现方自行处理类型开关、免打扰（`QuietHours::resolve`）、失败降级与日志，并向 `stats()` 报告今日成功/失败/队列数（通知中心页渠道卡展示）。
渠道安全底线：危机与登录告警即时送达；通知只发管理员，绝不发咨客。

## 九、官方插件清单

| 插件 | 版本 | 能力 |
|---|---|---|
| 📮 邮件通知 `mail-notify` | **1.1.1** | 多收件人值班邮箱、七类事件开关矩阵、SMTP 自主配置（缺失时按邮箱后缀推断并一键填入）、失败原因可见、每分钟队列重试 |
| 🎧 AI 智能客服 `ai-concierge` | **1.0.1** | 服务商预设（DeepSeek / **Agnes AI** / Agnes 中国区 / 通义 / 智谱 / Kimi / OpenAI / 自定义）、角色与附加提示词、知识库增删启停、未答提醒（危机兜底在核心） |
| 📊 预约周报 `booking-weekly-report` | 1.0.0 | 每周一运营数据周报（每日任务） |
| 💬 微信服务号通知 `wechat-notify` | **1.0.0** | 模板消息推送到管理员 OpenID（事件级模板 ID）、夜间免打扰延迟队列、失败自动降级邮件、到达率统计（v2.2.0 首发渠道注册制） |
| 📱 短信通知 `sms-notify` | **1.0.0** | 阿里云 Dysmsapi RPC 签名、危机预警即时送达（免打扰豁免）、AI 转人工提醒、按条计量与脱敏日志 |
| 🧮 量表扩展包 `assessment-pro` | **1.0.0** | SCL-90（官方 90 题/十因子）、SDS、SAS、PSQI、SSRS 五套量表；报告页维度得分/分级/打印；短链脱敏回看（v2.4.0） |
| 🗓 排班与考勤 `schedule-pro` | **1.0.0** | 例外排班（停诊/限定开诊）、预约冲突拦截、考勤标记（完成/爽约/迟到）、月度课时统计+CSV（v2.5.0） |
| 🎫 课时包与优惠券 `packages-pro` | **1.0.1** | 课时包售课核销、预约扣次、兑换码/转介绍邀请码（推荐人返课时）、对账流水；1.0.1 修复「我的课包」前台页并开放 openOrder 入账接口（v2.6.0） |
| 🔑 微信登录 `wechat-login` | **1.0.0** | 开放平台扫码（PC）+ 公众号网页授权（微信内）双通道；首次绑定手机验证（短信码/预约查询码）、可选自动注册；会员中心绑定卡片、后台绑定列表与解绑（v2.7.0） |
| 💰 微信支付 `wechat-pay` | **1.0.0** | 微信支付 V3：Native 扫码 + JSAPI；回调验签解密、金额核对、幂等防重；与课时包联动自动入账；查单兜底 + 每日对账；后台订单/查单/补入账（v2.7.0） |
| ✍️ 知情同意电子签署 `consent-pro` | **1.0.0** | 模板版本管理、7 天一次性签署链接、手写签名、全文快照哈希 + 哈希链存证（篡改可检出）、凭证打印、公开验真（存证号+手机后四位）、会员「我的签署」（v2.8.0） |
| 🏢 多门店管理 `stores-pro` | **1.0.0** | 门店 CRUD、咨询师归属门店、前台预约选门店、后台详情显示门店、按门店统计；`multisite` 授权特性门控（v2.8.0） |
| 🤝 企业 EAP 入口 `eap-pro` | **1.0.0** | 签约企业管理（企业码自动生成/重置、名额上限）、员工入口页、预约企业来源标记、按企业统计（v2.8.0） |

### 插件变更日志

**consent-pro 1.0.0 / stores-pro 1.0.0 / eap-pro 1.0.0**（2026-11-15，v2.8.0）
- 知情同意电子签署：哈希链存证模型（evidence_hash = sha256(prev_hash|业务字段)，创世哈希含站点 stats_salt）；已签不可撤销；签署链接 token 32 hex 一次性 7 天
- 多门店：`stores` 表 + `bookings.store_id` / `counselors.store_id`；前台功能由 `LicenseService::feature('multisite')` 门控
- 企业 EAP：`enterprises` 表 + `bookings.enterprise_id`；企业码 `EAP+12hex` 可重置；名额按 EAP 预约数计
- 核心新增 Hook：`booking.form.extras`（表单注入）/ `booking.before_insert`（提交补充字段）/ `booking.detail.info`（详情信息块）/ `admin.form.extras`（CRUD 表单扩展）/ `admin.form.saved`（保存后插件写库）；插件前台参数化路由 `/plugin/{slug}/{seg}/{arg…}`

**wechat-login 1.0.0 / wechat-pay 1.0.0**（2026-11-12，v2.7.0）
- 微信登录：openid/unionid/wx_openid 幂等迁移进 members；绑定必经手机验证（身份主键仍是手机号）；secret 加密入库；登录页 Hook `member.login.extras` 注入按钮
- 微信支付：商户私钥/APIv3 密钥加密存储；回调时间戳窗口 300s + 公钥序列号核对 + AES-256-GCM 解密 + 金额核对 + 状态幂等；未启用/配置不完整时前台全量拒绝下单
- packages-pro 1.0.1：修复会员中心「我的课包」卡片 404（补 front_my）；openOrder 对官方支付插件开放

**assessment-pro 1.0.0 / schedule-pro 1.0.0 / packages-pro 1.0.0**（2026-11-11，v2.4–v2.6）
- 量表扩展包、排班考勤、课时包优惠券三插件首发并登记分发（详见 ACCEPTANCE-v2.6.0.md）

**mail-notify 1.1.0**（2026-11-10）
- 新增**夜间免打扰时段**（可设起止时间、支持跨午夜）：静默期内通知**延迟到时段结束发送**，不丢失
- **危机预警与登录告警默认豁免**（可关，建议保持开启）
- 队列新增「计划发送」列，延迟项标记

**mail-notify 1.0.1**（2026-11-10）
- SMTP 服务器为空时按发件邮箱后缀推断（qq.com→smtp.qq.com 等 10 种）并提供「一键填入」
- 发送队列表格新增「失败原因」列
- 修复通知类型开关重复渲染

**ai-concierge 1.0.1**（2026-11-10）
- 新增 `ai.provider` 服务商预设（含 Agnes AI 与 Agnes 中国区），选中自动填充地址与模型
- `endpoint()` 兼容 base 地址带或不带 `/v1`
- 后台插件名改为「AI 客服配置」，与核心「AI 会话」区分

**booking-weekly-report 1.0.0**（2026-11-08）
- 每周一汇总上周预约/留言/AI 会话/活动报名，发送运营周报
