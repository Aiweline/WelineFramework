---
name: theme-development
description: 主题/前端开发。CSS 必须用主题变量、JS 必须 IIFE 闭包、禁止硬编码颜色、暗色模式兼容。
globs:
  - "**/view/**/*.phtml"
  - "**/view/**/*.js"
  - "**/view/**/*.css"
  - "**/theme/**/*"
  - "**/statics/**/*.css"
  - "**/statics/**/*.js"
alwaysApply: false
---

# theme-development（极简版）

## 何时使用

- 写 CSS 样式（颜色、背景、边框、阴影）
- 暗色/亮色模式适配
- 写 JS、组件、模板
- 使用 Toast、加载模块

## 必做

- CSS 禁止硬编码颜色，使用 `var(--backend-color-xxx)` 等主题变量
- JS 必须 IIFE 闭包，禁止全局变量污染
- 组件 CSS 使用独立作用域前缀（如 `.vendor-module-xxx`）
- 用户可见文案用 `__()` 或 `<lang>`，词条进 i18n/*.csv；弹窗/确认用 BackendToast/BackendConfirm，禁止 alert/confirm/prompt

## 最小示例

```css
.card { color: var(--backend-color-text-primary); }
```

```javascript
(function() { 'use strict'; })();
```

## 禁止

- 硬编码 #fff、rgb()、rgba()
- 全局 var/function 污染；通用类名 .card、.header 无前缀
- 禁止 alert/confirm/prompt，用 BackendToast/BackendConfirm
