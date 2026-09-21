# 技术方案会：visual-editor-theme-id-url-isolation

日期：2026-09-20  
模式：team  
冻结：是

## 决议

1. 主方案：URL 携带 `theme_id`（态 1），否决 Session 隔离。
2. 扩展点：复用 `Weline_Framework_Url::url_generate_rewrite`（不新造事件名）。
3. SEO：方案 A — 始终派发 rewrite；Seo 观察者自早退；prefetch 仍 SEO=on。
4. 注入字段：`theme_id`、`frontend_theme_id`、`editor_mode=1`、`shell=theme-editor`；已有 `editor_context` 透传。
5. 态 2：请求带 `weline_preview_token` 时不注入。

## 席位表态

| 席位 | 态度 |
|------|------|
| 架构师 | 同意并冻结 |
| 后端 | 同意 |
| 前端 | 同意（SSR 为主） |
| 测试 | 同意验收要点 |
| 项目经理 | 落实施工；审查反攻 |

## 施工清单

- [x] Framework Url 始终 dispatch + 文档
- [x] Seo early-return + 单测更新
- [x] Theme Observer + event.xml + PreviewContextService 助手方法
- [x] ThemeContextService frontend_theme_id 回退
- [x] 版本 bump + 开发日志 + 验收
- [x] 汇审（含反攻与残留）
