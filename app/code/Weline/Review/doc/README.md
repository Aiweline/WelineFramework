# Weline_Review

`Weline_Review` 是通用评论与审核模块，当前版本为 `1.0.0`。默认 Product Provider 支持总体及扩展评分、匿名或登录评论、图片/视频媒体和后台审核。

## 主要入口

- `ReviewTypeProviderInterface` / `ReviewTypeRegistry`：注册评论类型及动态字段。
- `ReviewService`：提交、校验和公开列表。
- `ReviewMediaService`：媒体票据、数量与类型约束。
- `ReviewAdminService`：后台筛选、评分明细及审核动作。
- `ReviewAiModerationService` 与 `Cron/AiModeration.php`：在可选 AI/Cron 能力存在时执行辅助审核。

## 关键边界

- 公开页面只展示已通过评论；审核状态变更必须通过受权限保护的后台入口。
- 媒体、评分字段和类型规则由 Provider 与服务端共同校验，前端字段不能成为唯一约束。
- Product、Customer、Msg、Cron、Ai 均为可选依赖，缺失时核心评论能力不得形成硬依赖。

## 文档

- [需求](需求.md)
- [开发日志](开发日志.md)
- 专题 Hook 与运营文档位于本目录的 `hook/`、`运营/`（存在时）。
