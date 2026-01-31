# WeShop_Cart::frontend::partials::cart::item-before - 购物车单个商品前

## 概述
用于在购物车单个商品渲染前插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::item-before`
- 显示名称：购物车单个商品前
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：item-before

## 触发时机
购物车单个商品项输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/item-before.phtml` 添加实现。

## 典型场景
- 插入活动标签或赠品提示
- 添加个性化推荐入口

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/item-before.phtml`
