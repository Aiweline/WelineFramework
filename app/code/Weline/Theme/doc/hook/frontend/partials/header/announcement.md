# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::header::announcement`
- **显示名称**：页头公告内容
- **功能说明**：覆盖默认「店铺通知」槽内的默认通知条目，允许站点或业务模块提供自己的公告文案。

## 对应 Slot

- **Slot ID**：`top-bar`
- **显示名称**：店铺通知
- **位置**：页头最顶部通知条左侧
- **模式**：`multiple=true`，最多 8 个，横向并排
- **仅接受通知类型**：
  - `accept`：`notice`（`@widget.type`）、以及 `layout-header-notice` / `top-bar` / `site-notice` / `announcement` 等通知码
  - `reject`：`header,navigation,product,category,footer,container,banner,search,social,content,logo,notice-rights`
- **推荐部件**：`top-bar`（`@widget.type {notice}`）

## 右侧槽（关联）

右侧为独立多槽 `top-bar-rights`，仅接受 `@widget.type {notice-rights}`（帮助中心、订单跟踪等入口），见 `notice-rights.md`。

## 使用方法

1. 主题编辑器：把「店铺通知」(`top-bar`) 等 **notice** 类型部件拖入左侧通知槽；可拖多个。
2. Hook：在模块 `view/hooks/Weline_Theme/frontend/partials/header/announcement.phtml` 覆盖默认条目。

## 边界

- 非 notice 类型部件（如 Logo、导航、促销横幅 banner、notice-rights 入口）不可放入该槽。
- 实现应保持轻量，适合输出促销提醒、服务承诺、站点公告。
- 不应在公告区重写 Header、导航、账户、购物车、结账或支付流程。
