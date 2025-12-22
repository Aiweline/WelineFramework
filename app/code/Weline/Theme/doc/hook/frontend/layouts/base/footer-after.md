# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::footer-after`
- **显示名称**：基础布局页脚之后
- **功能说明**：在渲染基础布局的页脚之后触发，允许其他模块在页脚结束处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--footer-after.phtml`

## 使用场景

- 在所有页面的 footer 之后注入全局返回顶部按钮
- 在所有页面的 footer 之后注入全局客服功能
- 在所有页面的 footer 之后注入其他全局功能

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--footer-after.phtml 文件中 -->
<div class="global-back-to-top">
    <button onclick="window.scrollTo({top: 0, behavior: 'smooth'})">返回顶部</button>
</div>
```

## 执行顺序

1. Footer partial 加载
2. 详细布局 hook（如 `homepage::footer-after`）- 先执行
3. `base::footer-after` - 最后执行

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook

