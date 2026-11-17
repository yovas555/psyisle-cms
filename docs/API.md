# 一二三 PsyIsle CMS JSON API v1（只读）

面向微信小程序 / 公众号 / 第三方系统的内容集成层。Bearer Token 鉴权，60 次/分钟限流。

## 鉴权
后台「系统设置 → 安全与统计 → API Token」生成。请求头：`Authorization: Bearer <token>`，或 `?token=<token>`。

## 端点（GET，JSON）
| 端点 | 说明 |
|---|---|
| /api/v1/articles?page=1&size=20 | 已发布文章（title/slug/summary/tags/published_at） |
| /api/v1/counselors | 咨询师公开资料（不含隐私联系方式） |
| /api/v1/services | 服务项目 |
| /api/v1/events | 进行中的活动（含满员状态） |
| /api/v1/faqs | 常见问题 |
| /api/v1/assessments | 量表目录（slug/name/description） |

## 响应
`{"ok":true,"resource":"articles","page":1,"size":20,"total":N,"data":[...]}`

## 代码约定
- 401 = token 缺失/错误；429 = 限流；404 = 未知资源
- 所有字段已 strip_tags；咨询师接口刻意不暴露任何联系方式字段
