# 商品新增 · 基础信息扩展

- **Hook 名称**：`Weline_Product::backend::catalog::create::basic-after`
- **挂载位置**：后台商品创建向导「基础信息」步骤（交易与库存区块之后）
- **用途**：扩展模块注入创建态产品级开关；与编辑页 `edit::basic-after` 语义对齐。
- **注入文件**：`view/hooks/Weline_Product/backend/catalog/create/basic-after.phtml`
