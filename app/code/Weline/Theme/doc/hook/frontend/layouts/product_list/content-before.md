# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::product_list::content-before`
- **显示名称**：产品列表布局内容之前
- **功能说明**：在渲染产品列表布局的主要内容之前触发，允许其他模块在内容开始处注入内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--product_list--content-before.phtml`

## 使用示例

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--product_list--content-before.phtml -->
<div class="product-list-banner">
    <h2>热门产品推荐</h2>
</div>
```

## 可用变量

- `$this->getData('meta')` - 布局元数据数组
- `$this->getData('theme')` - 主题相关数据

## 执行顺序

1. `Weline_Theme::frontend::layouts::base::content-before`
2. `Weline_Theme::frontend::layouts::product::content-before`
3. `Weline_Theme::frontend::layouts::product_list::content-before`
4. 主要内容渲染
5. `Weline_Theme::frontend::layouts::product_list::content-after`
6. `Weline_Theme::frontend::layouts::product::content-after`
7. `Weline_Theme::frontend::layouts::base::content-after`

