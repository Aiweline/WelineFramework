# WeShop Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`WeShop_Product::frontend::layouts::product-list::grid-content`
- **显示名称**：产品列表网格内容
- **Hook 类型**：标准格式 Hook
- **定义模块**：WeShop_Product
- **功能说明**：在产品列表页网格区域渲染产品列表，支持网格视图和列表视图两种显示模式。如果没有产品数据，不渲染任何内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：
```
view/hooks/WeShop_Product/frontend/layouts/product-list/grid-content.phtml
```

## 触发时机

该 Hook 在以下时机触发：
- **触发位置**：产品列表页面的产品网格区域
- **触发条件**：当产品列表需要渲染时自动触发
- **使用位置**：产品列表页面布局模板中

## 可用数据

Hook 文件可以通过 `$this->getData()` 获取以下数据：

- `products` - 产品列表（数组）
  - `product_id` - 产品ID
  - `name` - 产品名称
  - `price` - 产品价格
  - `original_price` - 原价
  - `image` - 产品图片
  - `sku` - 产品SKU
  - `handle` - 产品URL标识
  - `in_stock` - 是否有库存
  - `rating` - 评分
  - `review_count` - 评价数量
- `current_view` - 当前视图模式（'grid' 或 'list'）

## 使用场景

该 Hook 适用于以下场景：
- 自定义产品列表的显示方式（网格或列表）
- 添加产品卡片的额外功能（如快速查看、收藏、对比等）
- 替换默认的产品列表渲染逻辑
- 集成第三方产品展示组件

## 示例代码

```phtml
<?php
/**
 * 模块名称 - 产品列表网格Hook
 * 
 * Hook名称：WeShop_Product::frontend::layouts::product-list::grid-content
 * 
 * @hook-priority 100      Hook优先级：100
 * @hook-sort-order 1      Hook排序顺序：1
 */

/** @var \Weline\Framework\View\Template $this */

// 获取产品列表数据
$products = $this->getData('products') ?? [];
$currentView = $this->getData('current_view') ?? 'grid';

// 如果没有产品数据，不渲染任何内容
if (empty($products)) {
    return;
}

// 渲染产品网格
?>
<div class="product-grid-container view-<?= htmlspecialchars($currentView) ?>">
    <?php foreach ($products as $product): ?>
        <!-- 渲染产品卡片 -->
    <?php endforeach; ?>
</div>
```

## 注意事项

- 如果没有产品数据，建议不渲染任何内容，让fallback显示
- 应该支持网格视图和列表视图两种显示模式
- 产品卡片应该包含基本的交互功能（如加入购物车、收藏等）
- Hook 文件应该处理数据为空的情况，避免报错
- 可以通过 `$this->getUrl()` 方法生成产品链接

## 相关文档

- [WeShop Product 模块文档](../../README.md)
- [Hook 使用指南](../../../../../../Weline/Framework/Hook/doc/Hook顺序机制设计.md)
