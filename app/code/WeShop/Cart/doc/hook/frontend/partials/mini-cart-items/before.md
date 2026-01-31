# WeShop_Cart::frontend::partials::mini-cart-items::before - 迷你购物车商品列表前（兼容）

## 概述
用于在迷你购物车商品列表渲染前插入扩展内容（兼容旧布局）。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::mini-cart-items::before`
- 显示名称：迷你购物车商品列表前（兼容）
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：mini-cart-items
- 位置：before

## 触发时机
迷你购物车商品列表输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/mini-cart-items/before.phtml` 添加实现。

## 典型场景
- 插入引导提示
- 添加列表说明

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/mini-cart-items/before.phtml`
