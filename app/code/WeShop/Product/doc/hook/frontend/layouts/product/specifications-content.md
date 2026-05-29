# 产品详情规格内容 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::specifications-content`
- **使用位置**：商品详情页“Specifications”标签面板内部
- **默认行为**：未实现该 Hook 时，模板渲染 `product.specifications` 表格；规格为空时显示默认空态。

## 使用场景

- 替换规格参数表格
- 合并属性、参数、兼容性等结构化数据
- 对接行业模块的专属规格展示

## 可用数据

- `product`：当前商品数据数组，包含 `specifications`
- `reviews`：当前商品评价数据数组
- `qa`：当前商品问答数据数组

## 示例

```phtml
<?php
$product = (array) ($this->getData('product') ?? []);
$specifications = (array) ($product['specifications'] ?? []);

if (!$specifications) {
    return;
}
?>
<dl class="custom-product-specifications">
    <?php foreach ($specifications as $spec): ?>
        <dt><?= htmlspecialchars((string) ($spec['label'] ?? ''), ENT_QUOTES) ?></dt>
        <dd><?= htmlspecialchars((string) ($spec['value'] ?? ''), ENT_QUOTES) ?></dd>
    <?php endforeach; ?>
</dl>
```
