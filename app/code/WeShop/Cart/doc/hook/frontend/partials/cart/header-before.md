# WeShop_Cart::frontend::partials::cart::header-before - 购物车标题之前

## 概述
用于在购物车标题区域渲染前插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::header-before`
- 显示名称：购物车标题之前
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：header-before

## 触发时机
购物车标题区域输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/header-before.phtml` 添加实现。

## 典型场景
- 插入促销提示
- 添加页面说明文本

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/header-before.phtml`
