# WeShop_Cart::frontend::partials::cart::shipping-options - 配送方式选择

## 概述
用于扩展购物车页面配送方式选择区域的输出内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::shipping-options`
- 显示名称：配送方式选择
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：shipping-options

## 触发时机
购物车页面渲染配送方式选择区域时触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/shipping-options.phtml` 添加实现。

## 典型场景
- 增加配送说明或时效提示
- 插入自定义配送选项

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/shipping-options.phtml`
