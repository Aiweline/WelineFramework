# WeShop_Cart::frontend::layouts::cart::body-start - 购物车页面 body 开始

## 概述
用于在购物车页面 `<body>` 起始位置注入扩展内容。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::layouts::cart::body-start`
- 显示名称：购物车页面 body 开始
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：layouts
- 组件：cart
- 位置：body-start

## 触发时机
购物车页面 `<body>` 标签开始后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/layouts/cart/body-start.phtml` 添加实现。

## 典型场景
- 注入全屏提示或弹层容器
- 添加页面初始化脚本

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/layouts/cart/body-start.phtml`
