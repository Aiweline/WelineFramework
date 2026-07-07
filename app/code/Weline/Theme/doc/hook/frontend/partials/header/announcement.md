# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::header::announcement`
- **显示名称**：页头公告内容
- **功能说明**：覆盖默认页头公告插槽，允许站点或业务模块提供自己的公告、活动横幅或服务承诺入口。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/partials/header/announcement.phtml`

## 边界

- 该 Hook 位于默认主题 Header 顶部公告插槽中。
- 实现应保持轻量，适合输出促销提醒、服务承诺、站点公告或快捷入口。
- 不应在公告 Hook 中重写 Header、导航、账户、购物车、结账或支付流程；这些能力应继续通过各自的公开 Hook、Provider 或核心模块承接。
