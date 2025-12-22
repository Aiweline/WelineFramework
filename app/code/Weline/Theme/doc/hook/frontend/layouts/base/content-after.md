# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::content-after`
- **显示名称**：基础布局内容之后
- **功能说明**：在渲染基础布局的主要内容之后触发，允许其他模块在内容结束处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--content-after.phtml`

## 使用场景

- 在所有页面的内容之后注入全局相关推荐
- 在所有页面的内容之后注入全局广告位
- 在所有页面的内容之后注入全局功能模块

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--content-after.phtml 文件中 -->
<div class="global-related-content">
    <!-- 全局相关内容 -->
</div>
```

## 执行顺序

1. 主要内容区域
2. 详细布局 hook（如 `homepage::content-after`）- 先执行
3. `base::content-after` - 最后执行

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook

