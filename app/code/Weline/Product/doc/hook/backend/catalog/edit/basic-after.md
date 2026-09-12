# 商品编辑 · 基础信息扩展

- **Hook 名称**：`Weline_Product::backend::catalog::edit::basic-after`
- **挂载位置**：后台商品编辑页「基础信息」面板字段区末尾
- **用途**：扩展模块（如 `Weline_B2B`）注入产品级经营开关与管理入口；禁止业务模块改写 Product 模板硬编码字段。
- **注入文件**：`view/hooks/Weline_Product/backend/catalog/edit/basic-after.phtml`
