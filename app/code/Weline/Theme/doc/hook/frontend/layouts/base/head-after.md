# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::head-after`
- **显示名称**：基础布局头部之后
- **功能说明**：在渲染基础布局的 <head> 标签之后、</head> 之前触发，允许其他模块在头部结束处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--head-after.phtml`

## 使用场景

- 在所有页面的 head 标签结束前注入全局 CSS
- 在所有页面的 head 标签结束前注入全局 JavaScript
- 在所有页面的 head 标签结束前注入 meta 标签
- 在所有页面的 head 标签结束前注入其他全局资源

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--head-after.phtml 文件中 -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/custom.css') ?>">
<meta name="custom-meta" content="custom-value">
<script>
    // 全局 JavaScript 代码
    console.log('Base head after hook executed');
</script>
```

## 执行顺序

1. `base::head-before` - 最先执行
2. Head partial 加载
3. `base::head-after` - 在 head partial 之后执行
4. 详细布局 hook（如 `homepage::head-after`）- 最后执行

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook

