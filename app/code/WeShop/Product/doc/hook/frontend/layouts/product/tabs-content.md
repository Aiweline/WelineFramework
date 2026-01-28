# WeShop Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::tabs-content`
- **显示名称**：产品详情标签内容
- **Hook 类型**：标准格式 Hook
- **定义模块**：WeShop_Product
- **功能说明**：在产品详情页标签区域渲染产品描述、规格、评价、问答等内容。支持多个标签页切换，包括商品详情、规格参数、用户评价、问答等。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：
```
view/hooks/WeShop_Product/frontend/layouts/product/tabs-content.phtml
```

## 触发时机

该 Hook 在以下时机触发：
- **触发位置**：产品详情页面的标签内容区域
- **触发条件**：当产品详情页需要渲染标签内容时自动触发
- **使用位置**：产品详情页面布局模板中

## 可用数据

Hook 文件可以通过 `$this->getData()` 获取以下数据：

- `product` - 产品信息（数组）
  - `product_id` - 产品ID
  - `description` - 产品描述
- `attributes` - 产品属性（数组）
  - `code` - 属性代码
  - `label` - 属性标签
  - `value` - 属性值
- `reviews` - 用户评价列表（数组）
  - `user_name` - 用户名
  - `rating` - 评分
  - `content` - 评价内容
  - `created_at` - 创建时间
- `qa` - 问答列表（数组）
  - `question` - 问题
  - `answer` - 答案

## 使用场景

该 Hook 适用于以下场景：
- 自定义产品详情页的标签内容
- 添加额外的标签页（如相关推荐、视频介绍等）
- 自定义评价和问答的显示方式
- 集成第三方评价系统
- 添加产品规格参数的展示

## 示例代码

```phtml
<?php
/**
 * 模块名称 - 产品详情标签内容Hook
 * 
 * Hook名称：WeShop_Product::frontend::layouts::product::tabs-content
 * 
 * @hook-priority 100      Hook优先级：100
 * @hook-sort-order 1      Hook排序顺序：1
 */

/** @var \Weline\Framework\View\Template $this */

// 获取产品数据
$product = $this->getData('product');
$attributes = $this->getData('attributes') ?? [];
$reviews = $this->getData('reviews') ?? [];
$qa = $this->getData('qa') ?? [];

// 如果没有产品数据，不渲染任何内容
if (empty($product) || empty($product['product_id'])) {
    return;
}

// 构建标签页数据
$tabs = [
    ['id' => 'description', 'label' => '商品详情', 'active' => true],
    ['id' => 'specifications', 'label' => '规格参数', 'active' => false],
    ['id' => 'reviews', 'label' => '用户评价', 'active' => false],
    ['id' => 'qa', 'label' => '问答', 'active' => false],
];

// 渲染标签内容
?>
<div class="product-tabs-wrapper">
    <!-- 标签导航 -->
    <div class="tabs-nav">
        <?php foreach ($tabs as $tab): ?>
            <button class="tab-btn <?= $tab['active'] ? 'active' : '' ?>" data-tab="<?= $tab['id'] ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </button>
        <?php endforeach; ?>
    </div>
    
    <!-- 标签内容 -->
    <div class="tabs-content">
        <!-- 商品详情 -->
        <div class="tab-pane active" id="tab-description">
            <?= $product['description'] ?? '' ?>
        </div>
        
        <!-- 规格参数 -->
        <div class="tab-pane" id="tab-specifications">
            <!-- 渲染属性表格 -->
        </div>
        
        <!-- 用户评价 -->
        <div class="tab-pane" id="tab-reviews">
            <!-- 渲染评价列表 -->
        </div>
        
        <!-- 问答 -->
        <div class="tab-pane" id="tab-qa">
            <!-- 渲染问答列表 -->
        </div>
    </div>
</div>
```

## 注意事项

- 如果没有产品数据，建议不渲染任何内容，让fallback显示
- 标签切换应该通过JavaScript实现，提升用户体验
- 评价和问答应该支持分页加载
- Hook 文件应该处理数据为空的情况，避免报错
- 可以通过 `$this->getUrl()` 方法生成相关链接

## 相关文档

- [WeShop Product 模块文档](../../README.md)
- [Hook 使用指南](../../../../../../Weline/Framework/Hook/doc/Hook顺序机制设计.md)
