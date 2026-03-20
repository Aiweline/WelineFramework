# Backend Theme

`backend/` 是后台管理主题 area 的默认实现，负责后台布局、片段、表单/列表部件、
颜色变量和主题入口资源。

## 目录职责

- `assets/css/theme.css`
  - 后台 area 的公共基础样式。
- `assets/js/theme.js`
  - 后台统一入口脚本，处理模块加载、主题配置和全局交互。
- `layouts/`
  - 页面级布局模板，遵循 `layouts/<layoutType>/<option>.phtml`。
- `partials/`
  - 可配置后台片段，遵循 `partials/<type>/<option>.phtml`。
- `widgets/`
  - 后台部件模板，当前主要是 `data/form`、`data/list`。
- `components/`
  - 后台通用 UI 组件模板。
- `variables/`
  - 后台 token 文件，当前包含 `colors`、`spacing`、`typography`、`shadows`、`borders`。
- `colors/`
  - 后台色盘，当前包含 `default`、`light`、`dark`。
- `config/modules.json`
  - 后台主题私有模块配置；仅用于 `weline.modules.js` 编译。

## 当前清单

- 布局类型
  - `dashboard`
  - `default`
  - `fullscreen`
  - `login`
  - `minimal`
  - `print`

- partial 类型
  - `breadcrumb`
  - `footer`
  - `head`
  - `header`
  - `loading`
  - `right-sidebar`
  - `scripts`
  - `sidebar`
  - `topbar`
  - `topnav`

- widget 类型
  - `data`

## 规范提醒

- 布局和片段参数统一写在模板顶部的 `@param.*` 注释里，由主题元数据系统自动读取。
- 后台主题资源入口集中在 `partials/head/default.phtml`，不要在普通布局中重复做全局初始化。
- 主题配置来源是数据库配置与 `ThemeData`，不是 `theme.json` 文件。

