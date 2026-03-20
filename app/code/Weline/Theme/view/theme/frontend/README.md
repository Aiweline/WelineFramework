# Frontend Theme

`frontend/` 是前台主题 area 的默认实现，覆盖前台布局、片段、部件、变量、色盘以及
主题级 CSS/JS。

## 目录职责

- `assets/css/theme.css`
  - 前台 area 的公共基础样式。
- `assets/js/theme.js`
  - 前台统一入口脚本，消费 `window.__WelineThemeConfig`、模块配置和主题切换逻辑。
- `layouts/`
  - 页面级布局模板，命名遵循 `layouts/<layoutType>/<option>.phtml`。
- `partials/`
  - 可配置片段，命名遵循 `partials/<type>/<option>.phtml`。
- `widgets/`
  - 前台可拖拽/可配置部件，命名遵循 `widgets/<type>/<code>/default.phtml`。
- `components/`
  - 通用展示组件模板。
- `variables/`
  - 前台 token 文件，当前包含 `colors`、`spacing`、`typography`、`shadows`、`borders`。
- `colors/`
  - 前台色盘，当前包含 `default`、`light`、`dark`、`amazon`。
- `config/modules.json`
  - 前台主题私有模块配置；仅用于 `weline.modules.js` 编译。

## 当前清单

- 布局类型
  - `account`
  - `account_auth`
  - `account_logout`
  - `account_orders`
  - `account_profile`
  - `activity`
  - `cart`
  - `category`
  - `checkout`
  - `checkout_failer`
  - `checkout_success`
  - `cms_page`
  - `default`
  - `homepage`
  - `policy`
  - `product`
  - `product_list`
  - `test`

- partial 类型
  - `breadcrumb`
  - `footer`
  - `head`
  - `header`
  - `pagination`
  - `sidebar`

- widget 类型
  - `banner`
  - `breadcrumb`
  - `carousel`
  - `category`
  - `category-filters`
  - `container`
  - `content`
  - `faq`
  - `footer`
  - `header`
  - `navigation`
  - `newsletter`
  - `pagination`
  - `product`
  - `search`
  - `sidebar`
  - `social`
  - `testimonial`
  - `video`

## 规范提醒

- 新增或修改布局、片段、部件时，先补 `@meta.*` / `@param.*` / `@widget.*` 注释。
- 主题配置走 `ThemeData` / `ThemeConfigManager`，不要在这里重新引入 `theme.json`。
- `head/default.phtml` 负责加载前台 theme 资源和生成的布局 CSS；其他模板不要重复做全局入口工作。
