# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::product_detail::head-before`
- **显示名称**：产品详情布局头部之前
- **功能说明**：在渲染产品详情布局的 `<head>` 标签之前触发，允许其他模块在头部开始处注入内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--product_detail--head-before.phtml`

## 使用示例

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--product_detail--head-before.phtml -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/product-detail-custom.css') ?>">
<meta name="product-id" content="<?= $this->getData('product') ? $this->getData('product')->getId() : '' ?>">
```

## 可用变量

- `$this->getData('product')` - 产品对象（Product模型实例）
- `$this->getData('meta')` - 布局元数据数组
- `$this->getData('theme')` - 主题相关数据

## 执行顺序

1. `Weline_Theme::frontend::layouts::base::head-before`
2. `Weline_Theme::frontend::layouts::product::head-before`
3. `Weline_Theme::frontend::layouts::product_detail::head-before`
4. Head partial 加载
5. `Weline_Theme::frontend::layouts::product_detail::head-after`
6. `Weline_Theme::frontend::layouts::product::head-after`
7. `Weline_Theme::frontend::layouts::base::head-after`

