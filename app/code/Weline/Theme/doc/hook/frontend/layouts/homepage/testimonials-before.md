# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::homepage::testimonials-before`
- **显示名称**：首页客户评价区块之前
- **功能说明**：在渲染首页布局的客户评价区块之前触发，允许其他模块在客户评价区块开始处注入内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--homepage--testimonials-before.phtml`

## 使用场景

- 在首页客户评价区块之前注入额外内容
- 在首页客户评价区块之前注入装饰性元素
- 在首页客户评价区块之前注入其他功能

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--homepage--testimonials-before.phtml 文件中 -->
<div class="testimonials-intro">
    <h2>客户评价</h2>
</div>
```

## 执行顺序

1. `homepage::testimonials-before` - 执行
2. 客户评价区块内容
3. `homepage::testimonials-after` - 执行

## 注意事项

- 此 hook 仅在首页布局中执行
- 如果需要在所有页面生效，请使用 base hook

