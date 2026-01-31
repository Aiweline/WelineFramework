# WeShop_Cart::frontend::layouts::cart::content-before - 购物车内容区域之前

## 概述
用于在购物车页面内容区域渲染前插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::layouts::cart::content-before`
- 显示名称：购物车内容区域之前
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：layouts
- 组件：cart
- 位置：content-before

## 触发时机
购物车页面主内容区域渲染之前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/layouts/cart/content-before.phtml` 添加实现。

## 典型场景
- 插入营销提示或公告
- 添加自定义导航或面包屑

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/layouts/cart/content-before.phtml`
