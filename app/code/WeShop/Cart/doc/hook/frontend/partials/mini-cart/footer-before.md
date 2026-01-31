# WeShop_Cart::frontend::partials::mini-cart::footer-before - 迷你购物车底部之前

## 概述
用于在迷你购物车底部渲染前插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::mini-cart::footer-before`
- 显示名称：迷你购物车底部之前
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：mini-cart
- 位置：footer-before

## 触发时机
迷你购物车底部区域输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/mini-cart/footer-before.phtml` 添加实现。

## 典型场景
- 插入优惠提示或结算引导
- 添加额外操作按钮

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/mini-cart/footer-before.phtml`
