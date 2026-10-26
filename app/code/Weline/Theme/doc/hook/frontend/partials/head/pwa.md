# Weline_Theme::frontend::partials::head::pwa

## 触发位置

`Weline_Theme::view/theme/frontend/partials/head/assets-suffix.phtml`（以及组合 head 的
`assets-suffix`）在 `module-declarations` 之后输出。

## 用途

允许模块向前台 `<head>` 注入 PWA 安装信息：

- `<link rel="manifest" href="...">`
- `<meta name="theme-color" content="...">`
- `mobile-web-app-capable` / `apple-mobile-web-app-*`
- `<link rel="apple-touch-icon" href="...">`

实现方：`Weline_App::view/hooks/Weline_Theme/frontend/partials/head/pwa.phtml`。

## 约定

- 站点名称、图标、主题色必须从当前 Website 品牌配置读取，禁止写死品牌字面量。
- manifest URL 必须带当前 `lang` / `currency`，保证多语言、多货币安装入口不串档。
- head 标签随页面进入 FPC；manifest 本身由路由按 `lang` / `currency` 输出并走 HTTP 缓存。
