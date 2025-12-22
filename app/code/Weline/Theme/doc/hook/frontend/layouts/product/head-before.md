# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::product::head-before`
- **显示名称**：产品通用布局头部之前
- **功能说明**：在渲染产品通用布局的 `<head>` 标签之前触发，允许其他模块在头部开始处注入内容。适用于所有产品相关页面（产品详情、产品列表等）。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--product--head-before.phtml`

## 使用示例

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--product--head-before.phtml -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/product-common.css') ?>">
```

## 可用变量

- `$this->getData('product')` - 产品对象（如果当前页面是产品详情页）
- `$this->getData('meta')` - 布局元数据数组
- `$this->getData('theme')` - 主题相关数据

## 执行顺序

产品通用hook在所有产品相关布局hook之前执行，适用于所有产品页面。

