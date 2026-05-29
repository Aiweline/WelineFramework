# 产品详情描述内容 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::description-content`
- **使用位置**：商品详情页“Product Description”标签面板内部
- **默认行为**：未实现该 Hook 时，模板渲染产品 `description` 字段；字段为空时显示默认空态。

## 使用场景

- 替换商品描述的排版或富文本渲染方式
- 增加视频、图文详情、卖点模块
- 对接外部 PIM 或 CMS 提供的详情内容

## 可用数据

- `product`：当前商品数据数组
- `reviews`：当前商品评价数据数组
- `qa`：当前商品问答数据数组

## 示例

```phtml
<?php
$product = (array) ($this->getData('product') ?? []);
$description = (string) ($product['description'] ?? '');

if ($description === '') {
    return;
}
?>
<div class="custom-product-description">
    <?= $description ?>
</div>
```
