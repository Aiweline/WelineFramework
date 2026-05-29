# 产品详情相关产品 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::related-products`
- **对应 Slot**：`product-related-products`
- **使用位置**：商品详情页推荐区域的相关产品接入点

## 使用场景

- 渲染同分类、同品牌或同标签商品
- 对接商品关联规则输出的相关产品
- 替换默认相关产品部件

## 示例

```phtml
<?php
$product = (array) ($this->getData('product') ?? []);

if (empty($product['product_id'])) {
    return;
}
?>
<section class="related-products-widget">
    <h2><?= __('相关产品') ?></h2>
    <!-- Render related product cards here. -->
</section>
```
