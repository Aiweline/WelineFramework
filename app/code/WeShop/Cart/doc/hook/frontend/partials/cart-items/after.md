# WeShop_Cart::frontend::partials::cart-items::after - 购物车商品列表后（兼容）

## 概述
用于在购物车商品列表渲染后插入扩展内容（兼容旧布局）。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart-items::after`
- 显示名称：购物车商品列表后（兼容）
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart-items
- 位置：after

## 触发时机
购物车商品列表输出后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart-items/after.phtml` 添加实现。

## 典型场景
- 插入推荐商品或营销区块
- 添加列表总结或提示

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart-items/after.phtml`
