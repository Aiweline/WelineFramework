# Weline Theme 主题模块

## 当前有效入口

如果你现在要开发主题、页面、布局、slot、widget、Theme.js 或主题覆盖，先读：

1. [`AI-INDEX.md`](./AI-INDEX.md)
2. [`开发/Theme开发总指南.md`](./开发/Theme开发总指南.md)
3. [`theme-inheritance-and-file-conventions.md`](./theme-inheritance-and-file-conventions.md)
4. [`../view/theme/README.md`](../view/theme/README.md)
5. 按任务继续读：
   - 布局：[`layout-discovery-guide.md`](./layout-discovery-guide.md)
   - 部件：[`部件开发指南.md`](./部件开发指南.md)
   - Slot：[`widget-slot-attributes.md`](./widget-slot-attributes.md)
   - Theme.js：[`Theme.js使用指南.md`](./Theme.js使用指南.md)
   - 浏览器请求：[`../../Frontend/doc/Weline.Api使用指南.md`](../../Frontend/doc/Weline.Api使用指南.md)

本 README 现在只做 Theme 模块索引，不再承载旧时代的 `theme.xml`、`design/frontend/default/layout.html`、`{block}` / `{include}` 那套示例。

## 模块职责

`Weline_Theme` 负责：

- 默认主题源目录 `view/theme/{frontend|backend}`
- 布局发现与覆盖优先级
- partial / component / widget / variables / colors / assets 组织
- 主题配置读取与运行时主题选择
- 可视化编辑器使用的 layout / slot / widget 元数据
- `Theme.js` 前端运行时

## 当前开发要点

### 1. 源文件位置

当前默认主题源目录：

- `app/code/Weline/Theme/view/theme/frontend`
- `app/code/Weline/Theme/view/theme/backend`

设计主题覆盖放在：

- `app/design/{Vendor}/{theme}/frontend/...`
- `app/design/{Vendor}/{theme}/theme/frontend/...`
- `app/design/{Vendor}/{theme}/view/theme/frontend/...`

### 2. 发现优先级

同一逻辑 key 的优先级固定为：

1. `app/design` 当前主题链
2. `Weline_Theme/view/theme`
3. 其他模块 `view/theme`

所以：

- `app/design` 可以覆盖默认主题
- 业务模块只能追加新布局，不能覆盖默认主题布局

### 3. 浏览器业务请求

站内业务请求必须走：

- `theme.js`
- `Weline.Api.resource()`
- `Weline.Api.graph()`
- `Weline.Api.stream()`

禁止：

- 禁止 `fetch`
- 禁止 `XMLHttpRequest`
- 禁止 `$.ajax`
- 禁止 `axios`
- 禁止手写 `/api/framework/query-bin`

### 4. 严格边界

不要改：

- `generated/`
- `view/tpl/`
- 编译后的模板输出

不要再按旧文档去创建：

- `etc/theme.xml`
- `design/frontend/default/layout.html`
- 旧 `{block}` / `{include}` 模板结构

## 常用文档地图

- 布局发现与覆盖：[`layout-discovery-guide.md`](./layout-discovery-guide.md)
- 主题继承与文件约定：[`theme-inheritance-and-file-conventions.md`](./theme-inheritance-and-file-conventions.md)
- 部件元数据、参数、slot：[`部件开发指南.md`](./部件开发指南.md)
- Slot 属性：[`widget-slot-attributes.md`](./widget-slot-attributes.md)
- Widget 规则：[`widget-rules.md`](./widget-rules.md)
- Partials 配置：[`Partials配置系统使用指南.md`](./Partials配置系统使用指南.md)
- Hook：[`Hook使用指南.md`](./Hook使用指南.md)
- 元数据：[`主题元数据工作流程.md`](./主题元数据工作流程.md)
- Theme.js：[`Theme.js使用指南.md`](./Theme.js使用指南.md)
- 默认主题目录规范：[`../view/theme/README.md`](../view/theme/README.md)

## 对外能力

### `w:theme:template`

用于按主题配置动态加载 partial/template，详细见：

- [`Partials配置系统使用指南.md`](./Partials配置系统使用指南.md)

### Theme QueryProvider

Theme 对外提供 `w_query('theme', 'copyTargetLayoutData', ...)`，供 CMS 等模块复制 Theme-owned 布局数据。调用方只传契约参数，不得直接写 Theme 布局表。

## 相关计划与专题文档

- [`virtual-layout-scope-plan.md`](./virtual-layout-scope-plan.md)
- [`widget-slot-system.md`](./widget-slot-system.md)
- [`widget-page-types.md`](./widget-page-types.md)
- [`visual-editor/`](./visual-editor/)
- [`version-control/`](./version-control/)

## 迁移说明

仓库里仍然存在一些历史主题文档和旧示例。若它们与以下文档冲突，以当前文档为准：

- [`开发/Theme开发总指南.md`](./开发/Theme开发总指南.md)
- [`theme-inheritance-and-file-conventions.md`](./theme-inheritance-and-file-conventions.md)
- [`layout-discovery-guide.md`](./layout-discovery-guide.md)
- [`../../Frontend/doc/Weline.Api使用指南.md`](../../Frontend/doc/Weline.Api使用指南.md)
- `dev/ai/global-constraints.md`
