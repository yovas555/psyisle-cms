# PsyIsle · 一二三心理咨询网站系统（基础版）

一套面向心理咨询机构的建站系统：文章资讯、咨询师展示、在线预约、访客留言、AI 客服、会员体系、量表测评、活动报名，以及完整的后台管理与 SEO 能力。原生 PHP 实现，**零 Composer 依赖**，解压后通过安装向导即可上线。

本仓库发布的是**基础版（个人版）源码**，对应免费 180 天的个人授权，适合个人咨询师 / 小型工作室评估与使用。

- 当前版本：**2.17.1**
- 官网 / 授权 / 插件市场：<https://cms.123xl.cn>
- 安装步骤见 [INSTALL.md](INSTALL.md)，只读接口见 [docs/API.md](docs/API.md)，插件开发见 [docs/PLUGIN-GUIDE.md](docs/PLUGIN-GUIDE.md)

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | 7.4+（兼容 8.x），需 `pdo_mysql` `gd` `mbstring` `zip` `openssl` `sodium` |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+，独立库，`utf8mb4` |
| Web 服务器 | Nginx / Apache，**docroot 指向 `public/`** |
| 可写目录 | `config/`、`storage/`（安装向导自动写入） |

## 快速开始

1. 下载 [Releases](../../releases) 里的 `psyisle-2.17.1-release.zip`（或 `git clone` 本仓库）。
2. 解压到站点目录，把 Web 服务器 docroot 指到 `public/`。
3. 浏览器访问 `http(s)://你的域名/install/`，按向导填数据库与管理员信息。
4. 安装完成自动落锁，进入 `/admin` 后台。

完整步骤（含 Nginx 示例、伪静态、计划任务、授权激活）见 [INSTALL.md](INSTALL.md)。

## 基础版功能

核心能力（随系统内置）：

- 内容：文章 / 分类 / 标签 / 单页，定时发布，富文本净化
- 咨询师：档案、执业地点、对外主页、排班空档
- 预约：在线预约表单、时段容量控制、状态流转、来访者自助取消
- 留言 / 提问：访客留言、AI 转人工
- AI 客服：知识库优先应答，危机干预兜底在核心层强制生效
- 会员：注册登录、会员中心、我的预约
- 量表测评：自评量表与报告
- 活动报名：满员控制
- SEO：sitemap / robots / rss / llms.txt、canonical、百度 / IndexNow 推送
- 后台：权限节点、审计日志、TOTP 两步验证、登录限流、数据导出
- 安全：输入校验、输出转义、SQL 预处理、完整性自校验、官方签名校验

基础版授权特性（个人版）：AI 客服 + 在线预约，**1 位咨询师**，保留品牌标识，单门店。

## 随附基础插件（随授权免费）

| 插件 | 目录 | 说明 |
|---|---|---|
| 邮件通知 | `plugins/mail-notify` | 关键事件实时推送管理员邮箱，SMTP 自主配置，失败入队重试 |
| AI 智能客服 | `plugins/ai-concierge` | OpenAI 兼容大模型接入，知识库优先，角色与提示词可视化配置 |
| 预约周报 | `plugins/booking-weekly-report` | 每周一汇总上周预约 / 留言 / AI 会话，邮件周报 |

插件在后台「插件管理」一键启停。开发自定义插件见 [docs/PLUGIN-GUIDE.md](docs/PLUGIN-GUIDE.md)。

## 增值插件（不在本仓库，按年订阅）

下列能力为增值官方插件，需另行订阅授权后从官网分发安装，本基础版仓库不包含其源码：

量表扩展包、排班与考勤、咨询套餐与优惠券、微信登录、微信支付、微信服务号通知、短信通知、知情同意电子签署、企业 EAP 入口、多门店管理（专业版 / 永久版特性）。

## 授权说明

系统按授权档位开放功能与有效期：

| 档位 | 有效期 | 咨询师上限 | 多门店 | 去品牌 |
|---|---|---|---|---|
| 试用 | 15 天 | 1 | 否 | 否 |
| **个人版（本仓库）** | **180 天（免费）** | 1 | 否 | 否 |
| 工作室版 | 365 天 | 5 | 否 | 否 |
| 专业版 | 365 天 | 20 | 是 | 否 |
| 永久版 | 永久 | 99 | 是 | 是 |

安装后默认以试用状态运行；**个人版授权免费 180 天**，在后台「授权与升级」输入授权码激活（授权码向发行方免费获取，见官网）。宽限超期不锁站，仅降级高级功能并提示。

## 目录结构

```
app/          核心代码（控制器 / 服务 / 视图 / 核心库）
public/       Web 根目录（index.php、assets、install 安装向导）
database/     schema.sql（安装向导自动导入）
plugins/      基础插件（mail-notify / ai-concierge / booking-weekly-report）
config/       配置目录（安装向导生成 config.php，勿提交真实凭据）
docs/         文档（API / 架构 / 插件开发）
storage/      运行时生成（缓存 / 日志 / 备份 / 安装锁），不入库
```

## 文档

- [INSTALL.md](INSTALL.md) — 安装与部署
- [docs/API.md](docs/API.md) — 只读 JSON API
- [docs/PLUGIN-GUIDE.md](docs/PLUGIN-GUIDE.md) — 插件开发规范
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — 架构与约定

## 反馈与升级

系统内置反馈通道，站点问题 / 功能需求可回传发行方；升级与官方插件更新由发行方签名分发。见官网 <https://cms.123xl.cn>。

## 许可

本软件为**专有软件**，非开源。源码公开供评估与按授权条款使用；未经书面许可不得再分发、转售或去除授权 / 品牌标识。详见 [LICENSE](LICENSE)。