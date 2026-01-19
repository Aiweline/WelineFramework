# WeShop Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`frontend::product::detail::after_add_to_cart`
- **显示名称**：产品详情页加入购物车之后
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在产品详情页面加入购物车之后触发，允许其他模块在加入购物车后执行自定义逻辑。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/frontend/product/detail/after_add_to_cart.phtml`

## 使用场景

- 在加入购物车后显示提示信息
- 执行相关的业务逻辑
- 触发其他模块的功能

## 示例代码

```html
<!-- 在模块的 view/hooks/frontend/product/detail/after_add_to_cart.phtml 文件中 -->
<?php
// 加入购物车后的处理逻辑
// 例如：显示推荐商品、触发营销活动等
?>
<script>
    // 可以执行 JavaScript 逻辑
    console.log('商品已加入购物车');
</script>
```

## 注意事项

- 此 hook 在产品加入购物车操作完成后触发
- 可以用于显示提示、执行 JavaScript 等
- 建议不要在此 hook 中执行耗时操作
