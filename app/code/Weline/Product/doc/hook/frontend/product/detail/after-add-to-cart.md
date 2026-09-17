# Weline Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Product::frontend::product::detail::after-add-to-cart`
- **显示名称**：商品详情加购之后
- **功能说明**：商品详情页购买栏加购/快捷支付之后的扩展槽。分销分享以默认收起的 disclosure 注入此处，避免抢主 CTA。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml`
