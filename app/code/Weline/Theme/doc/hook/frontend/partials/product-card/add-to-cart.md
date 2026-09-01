# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::product-card::add-to-cart`
- **显示名称**：商品卡片加购按钮
- **功能说明**：商品卡片加购操作槽；默认由 `Weline_Cart` 通过 Cart V2 `addV2` 提供加购按钮。Theme 卡片/列表/推荐部件应通过 `partials/product/add-to-cart.phtml` 触发本 Hook，禁止内联手写加购表单或旧版 Cart API。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/partials/product-card/add-to-cart.phtml`
