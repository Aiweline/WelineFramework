# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::body-end`
- **显示名称**：基础布局 Body 结束
- **功能说明**：在渲染基础布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。此 hook 适用于所有使用基础布局的页面。Theme 实现仅保留店面占位图与 float-layer JS 加载；可编辑悬浮槽 `storefront-float-start/end` 在 `partials/footer/default.phtml`。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--body-end.phtml`

## 使用场景

- 在所有页面的 body 结束前注入全局 JavaScript
- 在所有页面的 body 结束前注入全局统计代码
- 在所有页面的 body 结束前注入全局分析代码
- 在所有页面的 body 结束前注入其他全局资源

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--body-end.phtml 文件中 -->
<script>
    // 全局统计代码
    (function() {
        // 统计代码
    })();
</script>
```

## 执行顺序

1. 详细布局 hook（如 `homepage::body-end`）- 先执行
2. `base::body-end` - 最后执行
3. `</body>` 标签结束

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook
- Theme 自身实现会输出 `#w-storefront-float-layer`，并嵌套调用 `float-slot-start` / `float-slot-end`；左侧音乐 / 右侧客服等悬浮应挂对应 float-slot Hook，不要再裸挂本 Hook 各自 `position:fixed` 同步 bottom

