# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::header::notice-rights`
- **显示名称**：通知条右侧入口默认内容
- **功能说明**：覆盖 `top-bar-rights` 槽的附加内容。默认部件已嵌在布局 slot 内（`<w:widget>`），仅当用户改过才写入布局 JSON。

## 对应 Slot

- **Slot ID**：`top-bar-rights`
- **显示名称**：通知右侧入口
- **位置**：页头最顶部通知条右侧
- **模式**：`multiple=true`（无 `append`：有部件时替换回退 HTML，避免与默认注入重复），最多 12 个，横向并排，项间 `|` 分隔
- **仅接受右侧入口类型**：
  - `accept`：`notice-rights`（`@widget.type`）、以及 `layout-header-notice-rights` / 具体部件码
  - `reject`：`header,navigation,product,category,footer,container,banner,search,social,content,logo,notice`
- **默认部件**（模板内嵌，非 `default_injections`）：
  - `help-center-link`
  - `order-tracking-link`
- **可选通用部件**：`notice-right-link`（其他模块可配置 label/url 追加）

## Scope

默认部件随布局模板渲染；用户在主题编辑器中增删改后，放置关系写入对应 scope 的布局草稿/发布态。

## 使用方法

1. 主题编辑器：把 `@widget.type {notice-rights}` 的部件拖入右侧槽；可拖多个。
2. 其他模块：新增同类型部件，或复用 `notice-right-link` 配置 URL。
3. Hook：在模块 `view/hooks/Weline_Theme/frontend/partials/header/notice-rights.phtml` 覆盖空槽回退 HTML。

## 边界

- 左侧 `top-bar`（notice）与右侧 `top-bar-rights`（notice-rights）互斥类型，不可交叉拖放。
- 入口应保持单行链接级轻量，不在此槽放置容器、导航或大块内容。
