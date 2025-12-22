# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::body-start`
- **显示名称**：基础布局 Body 开始
- **功能说明**：在渲染基础布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--body-start.phtml`

## 使用场景

- 在所有页面的 body 开始处注入全局脚本
- 在所有页面的 body 开始处注入全局样式
- 在所有页面的 body 开始处注入全局 HTML 结构
- 在所有页面的 body 开始处注入全局 JavaScript 初始化代码

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts::base--body-start.phtml 文件中 -->
<div id="global-notification" style="display: none;"></div>
<script>
    // 全局初始化代码
    window.globalConfig = {
        apiUrl: '<?= $this->getUrl('api') ?>'
    };
</script>
```

## 执行顺序

1. `<body>` 标签开始
2. `base::body-start` - 最先执行
3. 详细布局 hook（如 `homepage::body-start`）- 随后执行
4. 页面内容

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook

