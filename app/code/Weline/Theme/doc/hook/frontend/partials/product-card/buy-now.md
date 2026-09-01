# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::product-card::buy-now`
- **显示名称**：商品卡片立即购买按钮
- **功能说明**：商品卡片「立即购买」操作槽；默认由 Checkout/Cart 相关模块通过 Widget 或 Hook 提供跳转/加购并结账能力。Theme 卡片应通过 `partials/product/add-to-cart.phtml` 触发本 Hook，禁止内联手写旧版结账 API。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/partials/product-card/buy-now.phtml`
