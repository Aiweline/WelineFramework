# Backend Theme

`backend/` 是后台管理主题 area 的默认实现，负责后台布局、片段、表单/列表部件、
颜色变量和主题入口资源。

## 目录职责

- `assets/css/theme.css`
  - 后台 area 的**旧类兼容桥接层**（Bootstrap/Admin 旧类 → 主题 Token）。
  - 基础组件原语（`w-button` / `w-card` / `w-table` / `w-badge` / `w-input` 等）只由
    `view/ui/css/foundation.css` + `view/ui/css/backend.css` 提供，禁止在此文件重复定义。
  - 默认层禁止 `!important`（`prefers-reduced-motion` 例外）。
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
  - `default`（含 `blank`；旧 `1280`/`1440` 变体已删除）
  - `fullscreen`
  - `login`
  - `minimal`
  - `print`
  - `theme-editor`

- partial 类型
  - `head`
  - `loading`
  - `right-sidebar`
  - `sidebar`（`left`）
  - `topbar`
  - `topnav`
  - `layout`（`main-content` / `content`）
  - 旧 `breadcrumb/footer/header/scripts` partial 已删除（页面头/页脚由布局与
    `main-content.phtml` 统一输出）。

- widget 类型
  - `data`

## 规范提醒

- **唯一开发标准**：[`../../doc/backend/后端主题开发标准.md`](../../doc/backend/后端主题开发标准.md)。
- **UI 设计系统**：[`../../doc/设计系统/组件与Token规范.md`](../../doc/设计系统/组件与Token规范.md)（Token/组件树/Apple 尺度/静态与动态标签）。
- **工具页例外登记**：[`../../doc/backend/工具页迁移清单.md`](../../doc/backend/工具页迁移清单.md)（§12 专业工具页，目标收敛）。
  新增/修改后台页面、布局、片段、组件、Token、脚本加载前必须先读它。
- 布局和片段参数统一写在模板顶部的 `@param.*` 注释里，由主题元数据系统自动读取。
- 后台主题资源入口集中在 `partials/head/default.phtml`；head 核心脚本全部 `defer`，
  启动桩 `data-weline-boot-stub` 负责回放 body 内联脚本的早期 `Weline.*` 调用。
- 页面骨架统一走 `.w-backend-page__heading/__title/__breadcrumb`，页面内容只消费
  `w-*` 语义类与主题 Token；禁止页面内 `<style>`、行内视觉字面量与 `!important`。
- 主题配置来源是数据库配置与 `ThemeData`，不是 `theme.json` 文件。
- 静态审计：`php app/code/Weline/Theme/bin/backend-theme-audit.php [--strict] [--json]`。

