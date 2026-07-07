# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::header::cart`
- **显示名称**：页头购物车操作
- **功能说明**：覆盖默认页头购物车入口，允许购物车模块或站点模块接入自己的购物车地址、徽标或轻量状态。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/partials/header/cart.phtml`

## 边界

- 该 Hook 位于默认主题 header actions 的购物车插槽中。
- 实现应保持轻量，优先渲染购物车入口、数量徽标或短状态。
- 购物车持久化、增删改、结算状态机应由购物车/结账模块拥有，不应在主题 Hook 内重写。
