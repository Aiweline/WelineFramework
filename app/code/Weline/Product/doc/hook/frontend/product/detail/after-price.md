# 商品详情价格之后

- **Hook 名称**：`Weline_Product::frontend::product::detail::after-price`
- **渲染位置**：历史 Hook；现行 PDP 主路径为 `product-info` 内 `product-selling-mode` 槽。
- **用途**：其它模块可挂价格旁扩展。**禁止**用于 `Weline_B2B` 售卖模式切换器。
- **B2B XOR**：`b2b-selling-mode-switcher` 只允许 `@widget.default_injections` → `product-selling-mode`；B2B 的 after-price 实现为空（不得 fetch/include 该部件）。

在模块的 `view/hooks/` 目录下创建：

`view/hooks/Weline_Product/frontend/product/detail/after-price.phtml`
