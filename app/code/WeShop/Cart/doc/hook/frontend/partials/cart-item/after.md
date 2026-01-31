# WeShop_Cart::frontend::partials::cart-item::after - 购物车单个商品后（兼容）

## 概述
用于在购物车单个商品渲染后插入扩展内容（兼容旧布局）。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart-item::after`
- 显示名称：购物车单个商品后（兼容）
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart-item
- 位置：after

## 触发时机
购物车单个商品项输出后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart-item/after.phtml` 添加实现。

## 典型场景
- 插入赠品或加购提示
- 添加单品服务入口

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart-item/after.phtml`
