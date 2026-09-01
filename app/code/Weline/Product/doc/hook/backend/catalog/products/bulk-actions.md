# 商品目录批量操作扩展 Hook

Hook 名：`Weline_Product::backend::catalog::products::bulk-actions`

## 挂载位置

万能产品目录列表（`Weline_Product` → `backend/catalog/products`）在勾选商品后显示的批量操作栏。

内置操作：

- **删除**：调用 `product_admin.bulkCommand`，`action=archive`（归档，非物理删除）

## 扩展方式

在任意模块的 `view/hooks/Weline_Product/backend/catalog/products/` 下新增 `.phtml` 即可注入按钮或控件。

### 前端契约

商品目录脚本会暴露：

```javascript
window.WelineProductAdminCatalog = {
  getSelection: () => [{ global_product_uuid, product_id, identity_version, local_version }],
  getWebsiteId: () => number,
};
```

并在选择变化时分发：

```javascript
document.dispatchEvent(new CustomEvent('weline:product:catalog:selection-change', {
  detail: { count, items, website_id },
}));
```

### 分类模块示例

`Weline_Catalog` 已实现 **调整分类**：

- UI：`view/hooks/Weline_Product/backend/catalog/products/bulk-actions.phtml`
- JS：`view/statics/js/backend/product-catalog-bulk-categories.js`
- API：`product_admin.bulkAssignCategories`
  - `mode`: `add` | `replace` | `remove`
  - `category_ids`: 分类 ID 列表
  - `items`: 与批量删除相同的选择项结构

分类树通过 `catalog.tree`（`space=product`）加载。

## 注册

Hook 规约见 `Weline_Product/hook.php`。变更后执行：

```bash
php bin/w hook:rebuild
```

或全量：

```bash
php bin/w setup:upgrade
```

## ACL

批量写操作走 `product_admin` Resource，ACL 源：`Weline_Product::commerce:catalog:products`。
