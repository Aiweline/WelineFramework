# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::product_detail::content-before`
- **显示名称**：产品详情布局内容之前
- **功能说明**：在渲染产品详情布局的主要内容之前触发，允许其他模块在内容开始处注入内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--product_detail--content-before.phtml`

## 使用示例

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--product_detail--content-before.phtml -->
<?php
$product = $this->getData('product');
if ($product && $product->getId()):
?>
<div class="product-special-banner">
    <h2><?= htmlspecialchars($product->getName()) ?></h2>
    <p>限时特价，仅此一天！</p>
</div>
<?php endif; ?>
```

## 可用变量

- `$this->getData('product')` - 产品对象（Product模型实例）
- `$this->getData('meta')` - 布局元数据数组
- `$this->getData('theme')` - 主题相关数据

## 执行顺序

1. `Weline_Theme::frontend::layouts::base::content-before`
2. `Weline_Theme::frontend::layouts::product::content-before`
3. `Weline_Theme::frontend::layouts::product_detail::content-before`
4. 主要内容渲染
5. `Weline_Theme::frontend::layouts::product_detail::content-after`
6. `Weline_Theme::frontend::layouts::product::content-after`
7. `Weline_Theme::frontend::layouts::base::content-after`

