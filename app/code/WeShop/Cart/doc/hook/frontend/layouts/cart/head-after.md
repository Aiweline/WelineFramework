# WeShop_Cart::frontend::layouts::cart::head-after - 购物车页面头部之后

## 概述
用于在购物车页面布局的 `<head>` 之后注入扩展内容，便于添加额外样式、脚本或元信息。

## Hook 信息
- Hook 名称：`WeShop_Cart::frontend::layouts::cart::head-after`
- 显示名称：购物车页面头部之后
- 定义模块：WeShop_Cart
- 区域：frontend
- 类型：layouts
- 组件：cart
- 位置：head-after

## 触发时机
购物车页面布局渲染 `<head>` 结束后触发。

## 使用方法
在 `view/hooks/WeShop_Cart/frontend/layouts/cart/head-after.phtml` 添加实现。

## 典型场景
- 注入页面级 CSS/JS
- 添加自定义 Meta 信息
- 插入第三方统计脚本

## 相关文件
- `app/code/WeShop/Cart/hook.php`
- `app/code/WeShop/Cart/view/hooks/WeShop_Cart/frontend/layouts/cart/head-after.phtml`
