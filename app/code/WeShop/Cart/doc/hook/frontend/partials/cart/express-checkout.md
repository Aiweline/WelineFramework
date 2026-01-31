# WeShop_Cart::frontend::partials::cart::express-checkout - 快捷支付

## 概述
用于扩展购物车页面快捷支付区域的输出内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::express-checkout`
- 显示名称：快捷支付
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：express-checkout

## 触发时机
购物车页面渲染快捷支付入口时触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/express-checkout.phtml` 添加实现。

## 典型场景
- 对接第三方快捷支付
- 插入快捷结算提示

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/express-checkout.phtml`
