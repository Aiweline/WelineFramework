---
status: ready-for-plan
work_kind: feature
feature_slug: visual-editor-theme-id-url-isolation
module: Weline_Theme
updated: 2026-09-20
plan_complexity: complex
fe_be_scope: be+fe-contract
---

# 可视化编辑器：URL 携带 theme_id 隔离

## 背景

主题可视化编辑画布（态 1）身份权威是 **query + `editor_context`**。画布入口会带 `theme_id`，但店面 SSR 经 `Url::getFrontendUrl` 生成的站内链接默认不合并当前 query，iframe 内导航后丢失 `theme_id`，落到正式态默认/上线主题。

## 澄清记录

| # | 问题 | 决议 |
|---|------|------|
| 1 | Session vs URL | **URL 携带 theme_id**（态 1）；禁止用 Session 冒充态 1 |
| 2 | 新事件 vs 已有 | 复用 `Weline_Framework_Url::url_generate_rewrite` |
| 3 | SEO 门禁 | **方案 A**：始终派发 rewrite；Seo 观察者自早退；prefetch 仍仅 SEO=on |
| 4 | editor_context | **最小身份参** + 若当前请求已有 `editor_context` 则透传 |

## 用户故事

作为主题运营，在主题 A 的可视化编辑画布中点击店面导航/分类链接时，我希望下一跳仍停留在主题 A 的编辑身份，而不是跳到默认主题。

## EARS

1. WHEN 当前请求为可视化画布（`editor_mode=1` 或 `shell=theme-editor`）且存在正整数 `theme_id`（或 `frontend_theme_id`），THE system SHALL 在所有经 `Url::extractedUrl` 生成的**店面/前台**同站 URL 上补齐至少 `theme_id`、`frontend_theme_id`、`editor_mode=1`、`shell=theme-editor`。后台 URL（含主题编辑器「返回」等 chrome）SHALL NOT 被注入。
2. IF 当前请求没有画布身份或没有正 `theme_id`/`frontend_theme_id`，THEN the system SHALL NOT 因本功能改写生成的 URL。
3. IF 当前请求携带有效 `weline_preview_token`（态 2），THEN the system SHALL NOT 向生成 URL 注入可覆盖 Token 主题身份的 `theme_id` 包。
4. WHEN `Env::seo` 为 false，THE system SHALL 仍派发 `url_generate_rewrite`（供 Theme 注入），且 Seo 观察者不得做 path 重写。
5. WHEN 正式店面（非画布），THE system SHALL 生成不含编辑器主题身份 query 的干净 URL。

## 用例

### UC1 主成功：画布内导航保持主题

1. 打开 `theme-editor?theme_id=3`，画布 iframe 加载带 `theme_id=3` 的店面 path。
2. 页面内某 `href` 由 `@url` / `getFrontendUrl` 生成。
3. 断言 href query 含同一 `theme_id=3` 与 `editor_mode=1`。
4. 点击后下一跳仍解析为主题 3。

### UC2 备选：无 theme_id 不注入

1. 普通店面请求生成 URL。
2. 断言无 `editor_mode` / 无强制 `theme_id`。

### UC3 异常：态 2 Token 预览

1. 带 `weline_preview_token` 的真实预览请求。
2. 生成 URL 不因本功能追加 `theme_id` 覆盖包。

## 非目标

- 不用 Session 粘主题
- 不新建平行 URL 事件名（除非日后拆 params 钩子）
- 不改店面业务渲染 / Hook 同构链路
- 不全量强制每个 href 重建完整 `editor_context`（仅透传已有）

## 方案摘要

| 层 | 改动 |
|----|------|
| Framework `Url::extractedUrl` | **最终输出前**始终 `dispatch(url_generate_params)`；`url_generate_rewrite` 恢复仅 seo=on |
| Theme Observer | 只挂 `url_generate_params`，只追加 query |
| ThemeContextService | `theme_id` 缺失时可读 `frontend_theme_id` |

**禁止**：把画布身份参挂到 `url_generate_rewrite`（干扰 Seo；不走重写的 URL 带不上参）。

## 前端补洞（部件配置静态 href）

部件 `link` 等配置直出 `<a href>`（如 promo-banner）不经 `Url`。画布 `editor-mode.js` 在最终展示/点击前用当前 location 的主题身份 query 改写同站锚点（`bindEditorIdentityHrefCarry`）。

## 验收

- 单元/契约：Seo off 仍派发 rewrite；Theme 注入/早退用例 PASS
- Browser/WB：主题 A 画布点导航，地址栏/iframe URL 仍含 `theme_id=A`
