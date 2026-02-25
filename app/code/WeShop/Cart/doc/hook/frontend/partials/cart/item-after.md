# WeShop_Cart::frontend::partials::cart::item-after - 购物车单个商品后

## 概述
用于在购物车单个商品渲染后插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::item-after`
- 显示名称：购物车单个商品后
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：item-after

## 触发时机
购物车单个商品项输出后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/item-after.phtml` 添加实现。

## 典型场景
- 添加赠品信息
- 显示促销标签
- 插入商品关联推荐

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/item-after.phtml`
