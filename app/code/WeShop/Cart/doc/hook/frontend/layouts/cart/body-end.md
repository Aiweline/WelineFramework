# WeShop_Cart::frontend::layouts::cart::body-end - 购物车页面 body 结束

## 概述
用于在购物车页面 `<body>` 结束前注入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::layouts::cart::body-end`
- 显示名称：购物车页面 body 结束
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：layouts
- 组件：cart
- 位置：body-end

## 触发时机
购物车页面 `<body>` 标签结束前触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/layouts/cart/body-end.phtml` 添加实现。

## 典型场景
- 注入页面尾部脚本
- 添加性能或统计埋点

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/layouts/cart/body-end.phtml`
