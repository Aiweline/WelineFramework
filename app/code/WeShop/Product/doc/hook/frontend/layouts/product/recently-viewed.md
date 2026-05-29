# 产品详情最近浏览 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::recently-viewed`
- **对应 Slot**：`product-recently-viewed`
- **使用位置**：商品详情页推荐区域的最近浏览接入点

## 使用场景

- 渲染访客最近浏览商品
- 结合用户行为数据展示浏览历史
- 在无登录状态下使用本地浏览记录渲染推荐

## 示例

```phtml
<section class="recently-viewed-widget">
    <h2><?= __('最近浏览') ?></h2>
    <!-- Render recently viewed product cards here. -->
</section>
```
