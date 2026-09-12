# 商品编辑 · 规格与价格扩展

- **Hook 名称**：`Weline_Product::backend::catalog::edit::offers-after`
- **挂载位置**：后台商品编辑页「规格与价格」面板末尾（Offer 基础价表之后）
- **用途**：扩展模块（如 `Weline_B2B`）注入产品级批发启用开关与按 SKU 的数量阶梯价编辑面；禁止在 Product 内自建第二套阶梯价表。
- **注入文件**：`view/hooks/Weline_Product/backend/catalog/edit/offers-after.phtml`
- **透传数据**：编辑模板 scope 中的 `offers`、`snapshot`、`productEntityId` / `product_id`、website 与 `edit_business` 权限上下文。
