# WeShop_Cart::frontend::partials::cart::summary-after - 订单摘要之后

## 概述
用于在订单摘要区域渲染后插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::summary-after`
- 显示名称：订单摘要之后
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：summary-after

## 触发时机
购物车页面订单摘要区域输出后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/summary-after.phtml` 添加实现。

## 典型场景
- 插入推荐商品
- 添加结算引导信息

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/summary-after.phtml`
