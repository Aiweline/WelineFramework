# 产品详情热销商品 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::bestsellers`
- **对应 Slot**：`product-bestsellers`
- **使用位置**：商品详情页推荐区域的热销商品接入点

## 使用场景

- 渲染站内热销商品
- 渲染当前分类、品牌或价格带的热销榜
- 对接已有 bestsellers widget

## 示例

```phtml
<section class="bestsellers-widget">
    <h2><?= __('热销商品') ?></h2>
    <!-- Render bestseller product cards here. -->
</section>
```
