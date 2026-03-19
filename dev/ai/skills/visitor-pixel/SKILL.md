---
name: visitor-pixel
description: Weline Visitor 像素模块。页面引入 <pixel> 后上报全自动；需统计的交互只在元素上加类名 weline-pixel::事件名。UV/PV 按 IP。
globs:
  - "**/Visitor/**/*.php"
  - "**/Visitor/**/*.phtml"
  - "**/view/taglib/js/pixel.phtml"
  - "**/PageBuilder/**/header.phtml"
  - "**/PageBuilder/**/tracking.phtml"
alwaysApply: false
---

# Visitor 像素模块技能

## 核心原则（必读）

- **上报是自动的**：页面输出 `<pixel name="..." />` 后，像素脚本会监听 `DOMContentLoaded` 与全局 `click`，**业务模板里不要手写 `WelinePixel.send()`**。
- **只需在 DOM 上「打标」**：在需要统计的**元素或其祖先**上加 **CSS 类名** `weline-pixel::事件名`（双冒号后是事件名，如 `download-click`）。点击时脚本会沿 DOM 向上查找该类并自动上报。
- **需要数值的事件**：在容器内再放子元素，类名为 `weline-pixel::事件名:value`，见模块文档。

## 1. 页面接入

```html
<pixel name="default" enabled="yes" />
```

- `name` 必填；`enabled` 可选（`yes`/`no`）。
- PageBuilder：各主题 `header.phtml` / `base/tracking.phtml` 已按 `pixel.enabled`、`pixel.name` 输出；需 `websiteId > 0` 且非预览。

## 2. 自动发生的上报

| 时机 | 行为 |
|------|------|
| 页面加载 | `DOMContentLoaded` 后按当前 URL 路径映射事件名（如 `homepage`、`page_xxx`、`view-item`、`blog` 等），自动发送。 |
| 点击 | 从 `event.target` 向上查找第一个带 `weline-pixel::xxx`（且非 `:value`）的节点，用 `xxx` 作为 `eventName` 发送。子元素（如 SVG、文字）点击也会命中外层带类的按钮。 |

## 3. 下载相关（PageBuilder 样式模板约定）

- **推荐**：下载按钮使用 **`GlrDownloadRegistry::register` + `data-glr-ref`**（见 `pagebuilder-style-templates` §7）。**footer-common** 在点击时按需调用 **`WelinePixel.send`**，勿再给下载按钮加 **`weline-pixel::download-*`** 类（易重复上报）。
- **旧页**：若仍用内联脚本跳转，可在按钮上加 `weline-pixel::download-click` / `download-secondary`；**勿**与 footer-common 委托同时使用。

**下载成功**：在成功页需要单独事件时，给可点击元素加 **`weline-pixel::download-success`**（用户点击即自动上报）。若只需统计「进入成功页」，依赖页面加载时的路径映射事件即可，不必再加类。

## 4. UV / PV（后端统计）

- **UV**：`Pixel::getUvCountByDateRange($websiteId, $start, $end)` — 按 **IP 去重**。
- **PV**：`Pixel::getPvCountByDateRange($websiteId, $start, $end)` — 同一时间范围内像素记录条数。
- 封装：`PixelStatisticsService::getUvPvByDateRange(...)` → `['uv'=>…,'pv'=>…]`。

## 5. 何时才碰 `WelinePixel` / API

- 普通页面与组件：**不要**在业务 JS 里调用 `send`。
- 仅在做 **Observer 注入 `pixel_code`**（`Weline_Visitor::taglib_pixel`）等扩展时，按模块文档改 `initData` 并 `send`。

## 参考

- `app/code/Weline/Visitor/doc/像素拓展使用指南.md`
- `Weline\Visitor\Taglib\Pixel`、`Weline_Visitor::taglib/js/pixel.phtml`
- `Weline\Visitor\Api\Rest\V1\Pixel`
