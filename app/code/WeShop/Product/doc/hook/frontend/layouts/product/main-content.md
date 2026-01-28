# WeShop Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::main-content`
- **显示名称**：产品详情主内容
- **Hook 类型**：标准格式 Hook
- **定义模块**：WeShop_Product
- **功能说明**：在产品详情页主内容区域渲染产品图片、信息、购买选项等。包括产品图片画廊、基本信息、价格、库存状态、购买按钮等核心内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：
```
view/hooks/WeShop_Product/frontend/layouts/product/main-content.phtml
```

## 触发时机

该 Hook 在以下时机触发：
- **触发位置**：产品详情页面的主内容区域
- **触发条件**：当产品详情页需要渲染主内容时自动触发
- **使用位置**：产品详情页面布局模板中

## 可用数据

Hook 文件可以通过 `$this->getData()` 获取以下数据：

- `product` - 产品信息（数组）
  - `product_id` - 产品ID
  - `name` - 产品名称
  - `short_description` - 简短描述
  - `description` - 详细描述
  - `price` - 产品价格
  - `cost` - 成本价
  - `sku` - 产品SKU
  - `stock` - 库存数量
  - `in_stock` - 是否有库存
  - `stock_status` - 库存状态
  - `weight` - 重量
  - `image` - 主图片
  - `images` - 图片列表（数组）
- `attributes` - 产品属性（数组）
  - `code` - 属性代码
  - `label` - 属性标签
  - `value` - 属性值

## 使用场景

该 Hook 适用于以下场景：
- 自定义产品详情页的显示方式
- 添加产品图片画廊功能
- 自定义购买选项和数量选择
- 添加产品属性展示
- 集成第三方产品展示组件

## 示例代码

```phtml
<?php
/**
 * 模块名称 - 产品详情主内容Hook
 * 
 * Hook名称：WeShop_Product::frontend::layouts::product::main-content
 * 
 * @hook-priority 100      Hook优先级：100
 * @hook-sort-order 1      Hook排序顺序：1
 */

/** @var \Weline\Framework\View\Template $this */

// 获取产品数据
$product = $this->getData('product');
$attributes = $this->getData('attributes') ?? [];

// 如果没有产品数据，不渲染任何内容
if (empty($product) || empty($product['product_id'])) {
    return;
}

// 渲染产品详情
?>
<div class="product-detail-view">
    <div class="product-detail-row">
        <!-- 产品图片区域 -->
        <div class="product-gallery">
            <!-- 渲染产品图片 -->
        </div>
        
        <!-- 产品信息区域 -->
        <div class="product-info-section">
            <h1 class="product-title"><?= htmlspecialchars($product['name']) ?></h1>
            
            <!-- 价格 -->
            <div class="product-price-box">
                <span class="product-price">¥<?= number_format($product['price'], 2) ?></span>
            </div>
            
            <!-- 购买区域 -->
            <div class="product-actions">
                <button class="btn-add-to-cart">加入购物车</button>
            </div>
        </div>
    </div>
</div>
```

## 注意事项

- 如果没有产品数据，建议不渲染任何内容，让fallback显示
- 产品图片应该支持多图切换和放大功能
- 购买按钮应该根据库存状态禁用或启用
- 数量选择应该限制在库存范围内
- Hook 文件应该处理数据为空的情况，避免报错
- 可以通过 `$this->getUrl()` 方法生成产品链接

## 相关文档

- [WeShop Product 模块文档](../../README.md)
- [Hook 使用指南](../../../../../../Weline/Framework/Hook/doc/Hook顺序机制设计.md)
