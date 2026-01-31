# WeShop_Cart::frontend::layouts::cart::content-after - 购物车内容区域之后

## 概述
用于在购物车页面内容区域渲染后插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::layouts::cart::content-after`
- 显示名称：购物车内容区域之后
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：layouts
- 组件：cart
- 位置：content-after

## 触发时机
购物车页面主内容区域渲染结束后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/layouts/cart/content-after.phtml` 添加实现。

## 典型场景
- 插入推荐商品或广告位
- 添加客户服务入口

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/layouts/cart/content-after.phtml`
