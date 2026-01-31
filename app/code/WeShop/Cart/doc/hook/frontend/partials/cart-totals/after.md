# WeShop_Cart::frontend::partials::cart-totals::after - 购物车总计后（兼容）

## 概述
用于在购物车总计区域渲染后插入扩展内容（兼容旧布局）。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart-totals::after`
- 显示名称：购物车总计后（兼容）
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart-totals
- 位置：after

## 触发时机
购物车总计区域输出后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart-totals/after.phtml` 添加实现。

## 典型场景
- 插入结算说明或协议入口
- 添加额外推荐信息

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart-totals/after.phtml`
