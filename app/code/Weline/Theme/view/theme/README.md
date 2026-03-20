# Theme 目录规范

`view/theme/` 是 `Weline_Theme` 的默认主题源目录。主题文件按 `frontend` / `backend`
分区组织，布局、片段、变量、色盘、部件和通用资源都从这里被扫描、解析和回退。

## 当前结构

```text
theme/
├── frontend/
│   ├── assets/css/theme.css
│   ├── assets/js/theme.js
│   ├── colors/_*.css
│   ├── components/*.phtml
│   ├── config/modules.json
│   ├── layouts/<layoutType>/<option>.phtml
│   ├── partials/<type>/<option>.phtml
│   ├── variables/_*.css
│   └── widgets/<type>/<code>/default.phtml
├── backend/
│   ├── assets/css/theme.css
│   ├── assets/js/theme.js
│   ├── colors/_*.css
│   ├── components/*.phtml
│   ├── config/modules.json
│   ├── layouts/<layoutType>/<option>.phtml
│   ├── partials/<type>/<option>.phtml
│   ├── variables/_*.css
│   └── widgets/<type>/<code>/default.phtml
└── README.md
```

## 核心约定

- `layouts/`
  - 路径规则是 `theme/{area}/layouts/{layoutType}/{option}.phtml`。
  - 控制器设置 `layoutType` 后，`ControllerFetchFileBefore` 会结合主题配置和预览 scope
    解析最终模板；`account.auth` 这类写法会把 `account` 识别为类型、`auth` 识别为选项。
  - 布局顶部的 `@meta.*`、`@param.*` 注释会被扫描到 `ThemeData`，运行时注入 `meta`。

- `partials/`
  - 路径规则是 `theme/{area}/partials/{type}/{option}.phtml`。
  - 应通过 `Weline\Theme\Block\Partials` 加载，由主题配置、预览 scope、父主题回退共同决定最终文件。
  - partial 模板可直接使用 `meta`、`layout`、`theme`、`colors` 等数据。

- `widgets/`
  - 路径规则是 `theme/{area}/widgets/{type}/{code}/default.phtml`。
  - 部件使用 `@widget.*` 描述元数据，使用 `@param.*` 声明可配置参数。
  - widget 列表由 `extends/module/Weline_Widget/Weline_Theme/widget.php` 和部件扫描流程共同消费。

- `variables/` 与 `colors/`
  - `variables/_*.css` 定义主题 token，例如颜色、间距、字体、边框、阴影。
  - `colors/_*.css` 定义成套色盘覆盖。
  - 这两类文件都要求有 `@meta.name` / `@meta.description` 注释，扫描后进入 `ThemeData` / `Meta`。

- `assets/`
  - `assets/css/theme.css`、`assets/js/theme.js` 放 area 级公共资源。
  - `head` partial 会加载这些基础资源，并在运行时拼接生成的布局 CSS。

- `config/modules.json`
  - 这里只存放主题区域自己的 JS 模块路径和别名配置，供 `weline.modules.js` 编译使用。
  - 主题本身的布局、色盘、partials、variables 等配置不再依赖 `theme.json`，而是由
    `ThemeConfigManager` / `ConfigLoader` / `ThemeData` 从数据库和 Meta 系统加载。

## 文档入口

- `frontend/README.md`：前端 area 总览
- `frontend/layouts/README.md`：前端布局命名与清单
- `frontend/partials/README.md`：前端片段命名与加载方式
- `backend/README.md`：后端 area 总览
- `backend/layouts/README.md`：后端布局命名与清单
- `backend/partials/README.md`：后端片段命名与加载方式

