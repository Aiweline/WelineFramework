# WeShop_Cart::frontend::partials::cart-totals::before - 购物车总计前（兼容）

## 概述
用于在购物车总计区域渲染前插入扩展内容（兼容旧布局）。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart-totals::before`
- 显示名称：购物车总计前（兼容）
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart-totals
- 位置：before

## 触发时机
购物车总计区域输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart-totals/before.phtml` 添加实现。

## 典型场景
- 插入优惠提示
- 添加运费说明入口

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart-totals/before.phtml`
