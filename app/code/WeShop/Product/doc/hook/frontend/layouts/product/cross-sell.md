# 产品详情搭配推荐 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::cross-sell`
- **对应 Slot**：`product-cross-sell`
- **使用位置**：商品详情页推荐区域的搭配推荐接入点

## 使用场景

- 渲染搭配购买商品
- 渲染交叉销售商品
- 渲染向上销售商品

## 示例

```phtml
<section class="cross-sell-widget">
    <h2><?= __('搭配推荐') ?></h2>
    <!-- Render cross-sell or up-sell product cards here. -->
</section>
```
