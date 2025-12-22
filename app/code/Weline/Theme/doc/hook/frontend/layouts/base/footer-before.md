# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::footer-before`
- **显示名称**：基础布局页脚之前
- **功能说明**：在渲染基础布局的页脚之前触发，允许其他模块在页脚开始处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--footer-before.phtml`

## 使用场景

- 在所有页面的 footer 之前注入全局友情链接
- 在所有页面的 footer 之前注入全局合作伙伴
- 在所有页面的 footer 之前注入其他全局内容

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--footer-before.phtml 文件中 -->
<div class="global-partners">
    <h3>合作伙伴</h3>
    <!-- 合作伙伴列表 -->
</div>
```

## 执行顺序

1. `base::footer-before` - 最先执行
2. 详细布局 hook（如 `homepage::footer-before`）- 随后执行
3. Footer partial 加载

## 注意事项

- 此 hook 会在所有前端布局页面中执行
- 适合用于需要全局生效的功能
- 如果需要为特定布局定制，请使用详细布局 hook

