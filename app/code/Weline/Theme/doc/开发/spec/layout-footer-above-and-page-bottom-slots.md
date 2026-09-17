---
status: ready-for-plan
work_kind: feature
feature_slug: layout-footer-above-and-page-bottom-slots
module: Weline_Theme
updated: 2026-09-17
---

# 布局 footer-above 与页级底部空槽

## 澄清记录

| # | 问题 | 结论（本回合自洽假设） |
|---|------|------------------------|
| 1 | footer-above 放哪？ | 放进前台 `partials/footer/default.phtml`（及 minimal），所有带 Footer 的布局自动携带，避免改 40+ 布局重复粘贴 |
| 2 | trust-badges 如何「默认注入」？ | Theme 自有部件：槽内 `<w:hook>…::above<else/><w:widget trust-badges/></w:hook>` 模板嵌套；**不用** `layout_type:*` 的 `default_injections`（与 shared chrome 门禁一致） |
| 3 | 与 homepage/checkout 现有 trust 槽关系？ | 去掉两处内嵌 trust-badges，保留空槽供其它信任/评价部件；统一由 footer-above 默认携带 trust-badges |
| 4 | 「其他页面空 slot」范围？ | 主要前台 `layouts/**/default.phtml`（及 blog 变体）在页脚前补 `{page}-bottom` 空槽；已有 `promotion-bottom` / `page-bottom` 的保留不重复 |
| 5 | 后台布局？ | 非目标；仅 frontend |

## 目标 / 非目标

- **目标**：页脚上方统一扩展点 `footer-above` + 默认 trust-badges；各业务页底部空槽便于其它应用注入部件。
- **非目标**：改 footer-container 内部子槽结构；强制已安装主题 DB 账本回填；后台布局。

## 用户故事

作为主题/业务模块开发者，我希望所有前台页脚上方有稳定的 `footer-above` 槽且默认带信任徽章，并在各页主内容底有空槽，以便扩展模块用空槽 + `default_injections` 注入部件。

## EARS

1. WHEN 前台布局渲染且 `showFooter` 为真，系统 SHALL 在 footer 独占槽之前渲染 `id=footer-above` 的 `<w:slot>`。
2. WHEN `footer-above` 槽无覆盖实现且无编辑器移除记录，系统 SHALL 默认渲染 Theme `trust-badges` 部件。
3. WHEN Blog 默认布局渲染，系统 SHALL 在页脚前提供空的 `blog-bottom` 槽（无内嵌非 Theme 业务部件）。
4. WHEN 其它主要前台默认布局渲染，系统 SHALL 在页脚前提供对应 `{page}-bottom`（或已有等价底槽）供其它应用注入。
5. IF 某布局不渲染 Footer partial，THEN 该页可不出现 `footer-above`（与 showFooter=false 一致）。

## 用例

### UC1 博客页自动带信任徽章

1. 打开博客文章页（带 footer）
2. 观察页脚上方出现 trust-badges
3. 主题编辑器可在 `footer-above` 调整/移除；其它模块可向 `blog-bottom` 注入

### UC2 首页不重复徽章

1. 打开首页
2. `homepage-trust` 不再内嵌 trust-badges；页脚上方 `footer-above` 仍有一份默认徽章

## 验收绑定

- UT：契约断言 footer partial 含 `footer-above` + trust-badges 嵌套；blog 含 `blog-bottom`；homepage/checkout 不再在 trust 槽内嵌 trust-badges
- WB/e2e：博客与首页探活可见 `data-widget-code="trust-badges"` 位于 footer-above 区域
