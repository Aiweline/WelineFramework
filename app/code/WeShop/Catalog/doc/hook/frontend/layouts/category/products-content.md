# WeShop Catalog 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`WeShop_Catalog::frontend::layouts::category::products-content`
- **显示名称**：分类页产品列表内容
- **Hook 类型**：标准格式 Hook
- **定义模块**：WeShop_Catalog
- **功能说明**：在分类页产品区域渲染该分类下的产品列表。如果分类有子分类，优先显示子分类网格；如果没有产品数据，显示空状态提示。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：
```
view/hooks/WeShop_Catalog/frontend/layouts/category/products-content.phtml
```

## 触发时机

该 Hook 在以下时机触发：
- **触发位置**：分类页面的产品列表区域
- **触发条件**：当分类页面渲染产品列表时自动触发
- **使用位置**：分类页面布局模板中

## 可用数据

Hook 文件可以通过 `$this->getData()` 获取以下数据：

- `category` - 当前分类信息（数组）
  - `category_id` - 分类ID
  - `name` - 分类名称
  - `handle` - 分类URL标识
  - `image` - 分类图片
  - `description` - 分类描述
  - `children` - 子分类列表（数组）
- `products` - 产品列表（数组）
  - `product_id` - 产品ID
  - `name` - 产品名称
  - `price` - 产品价格
  - `image` - 产品图片
  - `sku` - 产品SKU
  - `handle` - 产品URL标识
  - `in_stock` - 是否有库存
  - `stock` - 库存数量

## 使用场景

该 Hook 适用于以下场景：
- 自定义分类页产品列表的显示方式
- 添加产品列表的额外功能（如筛选、排序等）
- 替换默认的产品列表渲染逻辑
- 在子分类存在时显示子分类网格

## 示例代码

```phtml
<?php
/**
 * 模块名称 - 分类产品列表Hook
 * 
 * Hook名称：WeShop_Catalog::frontend::layouts::category::products-content
 * 
 * @hook-priority 100      Hook优先级：100
 * @hook-sort-order 1      Hook排序顺序：1
 */

/** @var \Weline\Framework\View\Template $this */

// 获取分类和产品数据
$category = $this->getData('category') ?? [];
$products = $this->getData('products') ?? [];
$childCategories = $category['children'] ?? [];

// 如果有子分类，显示子分类网格
if (!empty($childCategories)) {
    // 渲染子分类网格
    foreach ($childCategories as $subcat) {
        // 渲染子分类卡片
    }
    return;
}

// 如果没有产品，显示空状态
if (empty($products)) {
    echo '<div class="no-products">暂无商品</div>';
    return;
}

// 渲染产品列表
foreach ($products as $product) {
    // 渲染产品卡片
}
?>
```

## 注意事项

- 如果分类有子分类，系统会优先显示子分类网格，而不是产品列表
- 如果没有产品数据，建议显示友好的空状态提示
- Hook 文件应该处理数据为空的情况，避免报错
- 可以通过 `$this->getUrl()` 方法生成产品链接

## 相关文档

- [WeShop Catalog 模块文档](../README.md)
- [Hook 使用指南](../../../../../../Weline/Framework/Hook/doc/Hook顺序机制设计.md)
