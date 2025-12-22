# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::homepage::products-after`
- **显示名称**：首页产品推荐区块之后
- **功能说明**：在渲染首页布局的产品推荐区块之后触发，允许其他模块在产品推荐区块结束处注入内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--homepage--products-after.phtml`

## 使用场景

- 在首页产品推荐区块之后注入额外内容
- 在首页产品推荐区块之后注入相关链接
- 在首页产品推荐区块之后注入其他功能

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--homepage--products-after.phtml 文件中 -->
<div class="products-more">
    <a href="/products">查看更多产品</a>
</div>
```

## 执行顺序

1. `homepage::products-before` - 执行
2. 产品推荐区块内容
3. `homepage::products-after` - 执行

## 注意事项

- 此 hook 仅在首页布局中执行
- 如果需要在所有页面生效，请使用 base hook

