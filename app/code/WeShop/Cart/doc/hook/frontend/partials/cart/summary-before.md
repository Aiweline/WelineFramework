# WeShop_Cart::frontend::partials::cart::summary-before - 订单摘要之前

## 概述
用于在订单摘要区域渲染前插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::summary-before`
- 显示名称：订单摘要之前
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：summary-before

## 触发时机
购物车页面订单摘要区域输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/summary-before.phtml` 添加实现。

## 典型场景
- 插入优惠提示或折扣说明
- 添加订单说明或提示信息

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/summary-before.phtml`
