# 商品详情价格之后

- **Hook 名称**：`Weline_Product::frontend::product::detail::after-price`
- **渲染位置**：`product-info` 价格区块之后、规格/购买区之前
- **用途**：注入售卖模式切换（ToC/ToB）、批发身份申请入口等价格旁扩展，禁止替换整页 PDP。

在模块的 `view/hooks/` 目录下创建：

`view/hooks/Weline_Product/frontend/product/detail/after-price.phtml`
