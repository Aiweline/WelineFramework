# Weline_Compare

## 模块定位

对比栏、快速查看对话框由本模块在 `frontend/layouts/base/body-end` Hook 与 markup 同文件挂载：`product-shopper-chrome.css`（组件自带）。产品卡按钮脚本经 `shopper-actions` 的 `data-weline-load="compareShopper"` 声明加载 `product-card-actions.js`。

## 产品卡操作样式

- 样式文件：`view/statics/css/product-shopper-chrome.css`（随 Compare body-end 浮动组件输出，非 Theme layout 全局）
- 标记类：`product-actions product-actions--amz`（由 Theme `shopper-actions.phtml` 输出）
- 视觉约定：亚马逊风格圆形轻量控件——无硬边框、半透明白底、柔和阴影；hover `#c45500`；active `#e47911`
- 行为脚本：`view/statics/js/product-card-actions.js`（class 契约：`btn-wishlist` / `btn-compare` / `btn-quickview`）

## 知识维护约定

- 长期事实写入本模块 `doc/`。
- 不在本文复制全局规则或客户端规则。
- 无法由当前证据确认的行为必须标记待确认。
