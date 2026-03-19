---
name: theme-development
description: 主题/前端开发。模板优先 {{}}/@lang/@static/@url/@backend-url/@var 等静态标签，少写 PHP；CSS 用主题变量；JS IIFE；禁止硬编码颜色。
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
- **【硬性】自定义标签（非 HTML）— 属性禁止 PHP，必须用静态标签**：
  - **范围**：`<w:...>`、`<w:module:tag:...>` 等 Taglib/框架自定义标签，以及**非标准 HTML 元素名**的标签（非 `div`/`span`/`input`/`a` 等原生标签）。
  - **禁止**：在上述标签的**任意属性名/属性值**中出现 `<?php`、`<?=`、`<?` 等 PHP 代码。
  - **必须**：用框架静态标签表达语义，例如 `@lang{}`/`@lang()`、`@var()`、`{{...}}`、`@static()`、`@url()`、`@backend-url()`、`@api()`（项目若有）、`@if{}` 等；或该标签文档明确允许的**无 PHP 的变量名字符串**（由 `Weline_Taglib_resolve` 等解析，如 `value="myVarName"`），在**标签前**的 `<?php` 块中赋值。
  - **原因**：编译期 taglib 会抽取/还原 PHP 占位符，属性里嵌 PHP 易截断引号 → `ParseError`（如 `unexpected identifier "true"`）。
  - **对比**：普通 HTML 元素（如 `<input value="...">`）上是否允许 `<?=` 以框架模板规范为准；**自定义标签一律按本条执行**。

## 模板：少用 PHP，多用静态标签

- **原则**：`.phtml` 展示层能不用 `<?php` / `<?=` 就不用；用框架提供的占位与标签，便于编译、i18n 与标签解析。
- **`{{ ... }}`**：输出数据绑定（如 `{{page.title}}`、管道 `{{page.local_name | page.name}}` 等，以当前模块模板约定为准）。
- **`@lang{...}` / `@lang(...)`**：用户可见文案；**自定义（非 HTML）标签的属性里必须用此类写法**，禁止 `<?= __('...') ?>`。
- **`@static(模块::路径)`**：静态资源 URL（如 `@static(Weline_Admin::assets/...)`）。
- **`@url('...')`**：前台路由 URL。
- **`@backend-url('...')`**：后台路由 URL（如 `@backend-url('*/backend/...')`）。
- **`@var(...)`**：在属性或片段中输出变量（如 `id='@var(id)'`）。
- **`@api(...)`**：若当前模块/项目提供统一的 API URL 静态标签，优先使用，避免在模板里 PHP 拼接 endpoint。
- **其它**：项目中已有的条件/片段标签（如 `@if{...}`）按需使用；复杂逻辑、集合循环仍可 `<?php foreach` 或下放 Block `assign`，勿在模板堆业务。

## 最小示例

```css
.card { color: var(--backend-color-text-primary); }
```

```javascript
(function() { 'use strict'; })();
```

```html
<link href="@static(Vendor_Module::css/app.css)" rel="stylesheet"/>
<a href="@backend-url('*/backend/foo/index')"><lang>管理</lang></a>
<span title="@lang{保存}">{{item.label}}</span>
```

## 禁止

- 硬编码 #fff、rgb()、rgba()
- 全局 var/function 污染；通用类名 .card、.header 无前缀
- 禁止 alert/confirm/prompt，用 BackendToast/BackendConfirm
- **在任意自定义（非 HTML）标签的属性里写 PHP**（含 `<?=`、`<?php`）→ 一律禁止；改为静态标签或标签文档允许的变量名 + 标签前赋值（示例见 `Weline\Websites\Taglib\DomainSelect`）
- **能用 `{{}}` / `@static` / `@url` / `@backend-url` / `@var` / `@lang` / `@api` 却写一长段 `<?php`** → 优先改为静态标签；动态值放在**标准 HTML 外**的 `<?php` 块或 Block 中计算
