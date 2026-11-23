# 安装与部署（基础版 2.22.1）

面向自主安装：下载发行包或克隆源码 → 配置 Web 服务器 → 运行安装向导 → 计划任务与授权。全程无需 Composer。

## 1. 环境准备

- PHP 7.4+（兼容 8.x），扩展：`pdo_mysql` `gd` `mbstring` `zip` `openssl` `sodium`
- MySQL 5.7+ / MariaDB 10.3+，可新建独立数据库
- Nginx 或 Apache + PHP-FPM（或等效 SAPI）

检查扩展：`php -m | grep -E 'pdo_mysql|gd|mbstring|zip|openssl|sodium'`

## 2. 获取代码

方式 A（推荐）：从 Releases 下载 `psyisle-2.22.1-release.zip`，解压到站点目录，例如 `/www/wwwroot/你的站点`。

方式 B：`git clone <本仓库地址> 你的站点目录`。

解压 / 克隆后应看到 `app/ public/ database/ plugins/ config/ docs/`。

## 3. Web 服务器（docroot 必须指向 public/）

Nginx 示例（PHP-FPM）：

```nginx
server {
    listen 80;
    server_name 你的域名;
    root /www/wwwroot/你的站点/public;   # 注意是 public/
    index index.php index.html;

    # 禁止访问敏感目录（即使误配置 docroot 也兜底）
    location ~ ^/(config|storage|database|app|plugins)/ { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;   # 按实际 FPM 地址改
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

要点：

- docroot 指到 `public/`，`app/ config/ storage/ database/` 不对外暴露。
- 伪静态统一回落到 `index.php`（系统内部路由）。
- 生产建议开启 HTTPS，并在后台关闭 debug。

## 4. 目录权限

`config/` 与 `storage/` 需对 PHP 运行用户可写（安装向导要写 `config/config.php`、`storage/installed.json`）。例如运行用户为 `www`：

```bash
chown -R www:www config storage
```

`storage/` 不存在时系统会自动创建 `cache/ logs/ backups/`。

## 5. 运行安装向导

浏览器访问 `http(s)://你的域名/install/`，按表单填写：

1. 数据库：主机 / 端口 / 库名 / 用户 / 密码（向导自动建库并导入 `database/schema.sql`）
2. 管理员：用户名 / 密码（≥8 位）/ 邮箱
3. 站点名称

提交成功后：

- 自动生成 `config/config.php`（含随机 `cron_secret` / `license_secret` / `stats_salt`）
- 写入初始数据（角色、栏目、合规单页模板、FAQ 等）
- 生成 `storage/installed.json` **自动落锁**，此后再访问 `/install/` 返回 403

> 重装：删除 `storage/installed.json`（会清空数据，请先备份）。

## 6. 计划任务（cron）

系统靠 cron 触发定时发布、邮件队列、预约提醒、插件每日任务等。端点用 `cron_secret` 鉴权，**推荐用请求头 `X-Cron-Secret` 传递**（避免 secret 进 access log）。`cron_secret` 在 `config/config.php`。

crontab 示例（把 `你的域名` 与 `你的cron_secret` 换掉）：

```cron
* * * * * curl -s -H "X-Cron-Secret: 你的cron_secret" https://你的域名/admin/cron/minute >/dev/null
5 9 * * * curl -s -H "X-Cron-Secret: 你的cron_secret" https://你的域名/admin/cron/plugins >/dev/null
```

可用端点：`/admin/cron/{minute,plugins,mail,stats,publish,booking-reminder,upgrade-check}`。

## 7. 激活授权（个人版免费 180 天）

安装后默认以试用状态运行。进入后台 `/admin` →「授权与升级」，输入授权码激活**个人版（免费 180 天）**。授权码向发行方免费获取：<https://cms.123xl.cn>。

- 个人版 / 试用不绑定域名；工作室版及以上绑定域名。
- 宽限超期不锁站，仅降级高级功能并后台提示。

## 8. 启用基础插件

后台「插件管理」启用随附基础插件：邮件通知（配置 SMTP）、AI 智能客服（配置大模型与知识库）、预约周报。增值插件需另行订阅后从官网安装。

## 9. 上线自查

- 首页 / 文章 / 咨询师 / 预约 / 留言 / AI 客服可访问
- 后台各模块可登录操作，审计日志有记录
- `/sitemap.xml` `/robots.txt` 可访问
- cron 日志正常（`storage/logs/`）
- 已开 HTTPS、关 debug、`config/` 与 `storage/` 外部不可访问

## 常见问题

- **安装页报扩展缺失**：按第 1 节补齐 PHP 扩展后重启 FPM。
- **open_basedir 限制**：若主机设置了 `open_basedir`，需把站点目录与临时目录纳入，否则安装向导写配置 / 读 schema 会失败。
- **写入失败 / 500**：多为 `config/`、`storage/` 权限不足，按第 4 节授权给 PHP 运行用户。
- **伪静态 404**：确认 docroot 是 `public/` 且 `try_files` 回落到 `index.php`。