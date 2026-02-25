# WeShop_Cart::frontend::partials::cart::items-before - 购物车商品列表前

## 概述
用于在购物车商品列表渲染前插入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::partials::cart::items-before`
- 显示名称：购物车商品列表前
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：partials
- 组件：cart
- 位置：items-before

## 触发时机
购物车商品列表输出前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/partials/cart/items-before.phtml` 添加实现。

## 典型场景
- 添加提示信息或横幅广告
- 显示促销活动通知
- 插入满减/包邮提示

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/partials/cart/items-before.phtml`
