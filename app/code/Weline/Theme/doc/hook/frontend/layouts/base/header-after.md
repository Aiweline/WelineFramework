# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::header-after`
- **显示名称**：基础布局页头之后
- **功能说明**：在渲染基础布局的页头之后触发，允许其他模块在页头结束处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--header-after.phtml`

## 使用场景

- 在所有页面的 header 之后注入全局导航辅助
- 在所有页面的 header 之后注入全局搜索增强
- 在所有页面的 header 之后注入其他全局功能

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--header-after.phtml 文件中 -->
<div class="global-navigation-helper">
    <!-- 全局导航辅助功能 -->
</div>
```

## 执行顺序

1. Header partial 加载
2. 详细布局 hook（如 `homepage::header-after`）- 先执行
3. `base::header-after` - 最后执行

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook

