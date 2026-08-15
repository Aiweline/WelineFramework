# Weline Theme 主题模块

## 当前有效入口

如果你现在要开发主题、页面、布局、slot、widget、Theme.js 或主题覆盖，先读：

1. [`AI-INDEX.md`](./AI-INDEX.md)
2. [`需求.md`](./需求.md)
3. [`开发日志.md`](./开发日志.md)
4. [`开发/Theme开发总指南.md`](./开发/Theme开发总指南.md)
5. [`theme-inheritance-and-file-conventions.md`](./theme-inheritance-and-file-conventions.md)
6. [`../view/theme/README.md`](../view/theme/README.md)
7. 按任务继续读：
   - 布局：[`layout-discovery-guide.md`](./layout-discovery-guide.md)
   - 部件：[`部件开发指南.md`](./部件开发指南.md)
   - **前台 section `weline-code`（强约束）**：[`frontend-section-weline-code.md`](./frontend-section-weline-code.md) — 字面 `<section>` 与 `w:slot wrapper="section"` 必须非空语义 code；改模板后跑 `php bin/w frontend:check-section-code`
   - Slot：[`widget-slot-attributes.md`](./widget-slot-attributes.md)
   - Theme.js：[`Theme.js使用指南.md`](./Theme.js使用指南.md)
   - WLS 视图预热贡献：[`worker-view-warmup-contributions.md`](./worker-view-warmup-contributions.md)
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

### 全局颜色模式（`REQ-THEME-0001`）

Theme 采用“基础 palette → Weline 语义 Token → Bootstrap adapter”三层。`data-theme-preference` 保存 `system|light|dark`，而 `data-theme`、`data-bs-theme` 与 `color-scheme` 始终是已解析的 `light|dark`。设计主题只能覆盖 palette Token；不能用同路径 `assets/css/theme.css` 或 `assets/js/theme.js` 重新实现组件和主题运行时，否则会遮蔽 Weline_Theme 的全局 adapter。

通知能力的正式入口是 `Weline.Toast` 与 `Weline.BackendToast`。为兼容历史主题，运行时只会在全局名称尚未提供可用 `success()` 方法时，分别补充 `window.Toast` 与 `window.AdminToast` 别名；业务新代码不得依赖这两个旧名称。

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

### 4. 下拉浮层基座

Theme.js 前后端入口统一发布 `window.WelineSmartDropdown`，作为 Taglib、主题组件和页头选择器的浮层定位基座。业务控件只负责触发、选项、搜索与回填，并调用 `place()` 或 `mount()`；不得各自复制视口计算算法。

基座统一处理 `visualViewport`、四边 8px 安全边距、窄屏宽度夹取、上下方向选择和剩余高度约束。需要脱离裁剪容器时使用 `mount()` 的默认 body portal；必须保留父子 CSS/hover 关系时使用 `place()` 或 `portal: false`。Taglib 选择器浮层不得依赖本基座承载标签专属交互；标签侧使用 `FloatingDropdownEmitter` / `WelineTaglibFloatingDropdown` 自洽输出。

### 5. 严格边界

不要改：

- `generated/`
- `view/tpl/`
- 编译后的模板输出

不要再按旧文档去创建：

- `etc/theme.xml`
- `design/frontend/default/layout.html`
- 旧 `{block}` / `{include}` 模板结构

### 6. I18n 单向依赖

Theme 明确 `requires Weline_I18n`，依赖方向只能是：

`Weline_Theme -> Weline_I18n\Api -> Weline_Framework`

Theme 的词典、locale 列表、翻译收集和文案解析只允许使用：

- `Weline\I18n\Api\Translation\DictionaryRepositoryInterface`
- `Weline\I18n\Api\Translation\TranslationCollectorInterface`
- `Weline\I18n\Api\Translation\TranslationResolverInterface`
- `Weline\I18n\Api\Localization\LocaleCatalogInterface`

禁止引用 `Weline\I18n\Model`、`Service`、`Helper`，禁止再用 `Weline_I18n::query` 事件完成 PHP 内部调用。
`weline.modules.js` 的主题读取能力由
`Weline\Theme\Api\I18n\ThemeJavascriptModuleConfigProvider` 实现 I18n 公共 Provider 契约并通过编译注册表发布；
I18n 不反向感知 Theme。新增 I18n 集成时必须沿用这个方向，不得重新形成循环。

### 7. 跨模块边界

Theme 启动和运行时直接使用 `Framework`、`Backend`、`I18n`、`Meta`、
`SystemConfig` 和 `Widget` 的公开契约，因此它们是必需依赖。AI、CDN、EAV、
FileManager、ModuleRouter、SEO、Server 和 Websites 只在对应能力存在时启用，
统一由 `etc/module.php` 的 `optional` 声明和公开 `Api`/Provider 边界管理。

当前 Theme 的具体边界如下：后台外观只调用
`BackendThemeConfigInterface`；Widget 参数定义、表单和运行时模板只调用
`Widget\Api\Param\*` / `Widget\Api\Rendering\*`；布局选择批次只调用
`ScopedConfigRepositoryInterface` 并读取 `ScopedConfigData`；Worker 路由预热和编辑器
EAV 选项分别通过可选 `RouterRulesReaderInterface`、`EavOptionsQueryInterface` 解析。
这些边界均由编译 Provider 注册，Theme 不引用对方 Block、Controller、Model、Service、
Config 实现，也不在请求渲染循环使用 ObjectManager 查找跨模块实现。

Theme 的 AI 主题生成和虚拟主题预览只依赖 `Weline\Ai\Api\*`：场景 Agent 目录与执行通过
`AiRuntimeInterface`，Skill/Style 目录与样式快照通过 `StyleRuntimeInterface`，供应商会话通过
`ProviderRuntimeInterface`。Theme 只接收公开 `AiModel` 快照和 `AgentResult` 结果，不引用 Ai 的
`Model`/`Service`/`Agent`/`ProviderFactory` 内部实现。Ai 模块缺失时该可选能力必须明确不可用，
不使用字符串 ObjectManager 定位或内部类别名绕过边界。

站点品牌图片路径只调用 `Weline\FileManager\Api\Image`；历史
`FileManager\Helper\Image` 命名空间只是 FileManager 内部的一版兼容桥，
Theme 不再引用它。主题发布通知只发布 `Weline_Theme::notification`；
消息系统需要投递时由消费模块可选监听，Theme 不反向调用消息模块。

后台“外观与 Logo”未配置 `logo_dark`、`logo_light` 或 `logo_sm` 时，后台顶栏与登录页统一回退到
`Weline_Theme/view/theme/backend/assets/images/theme/logo.png`（W 字母黄色丝带标识）；小 Logo 不再回退到站点 favicon。
前台未配置 `logo_light` / `logo_dark` 时同样回退到
`Weline_Theme/view/theme/frontend/assets/images/theme/logo.png`（同一套 W 字母黄色丝带标识），不走错误的 `view/statics` 静态映射。

### 生产静态资源兜底

`Weline_Theme/view/theme` 是核心默认主题源码目录，不是生产静态资源的公开命名空间。
无论它来自自动安装写入的绝对 `app/code` 路径，还是运行时模块默认主题，`ThemeStaticNamespaceService`
都必须把它归一化到框架默认设计主题 `Weline/default`。因此核心主题资源 URL 应为
`/static/Weline/default/Weline/Theme/view/theme/...`；禁止生成不存在的 `/static/Weline/Theme/view/theme/...`，
否则颜色变量、暗色调色板和其他主题资源会返回 404。自定义 `app/design` 主题的命名空间保持不变。

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
- Worker 视图预热贡献：[`worker-view-warmup-contributions.md`](./worker-view-warmup-contributions.md)
- 运行时缓存失效与 IPC deadline：[`runtime-cache-invalidation.md`](./runtime-cache-invalidation.md)
- 默认主题目录规范：[`../view/theme/README.md`](../view/theme/README.md)

## 对外能力

### `w:theme:template`

用于按主题配置动态加载 partial/template，详细见：

- [`Partials配置系统使用指南.md`](./Partials配置系统使用指南.md)

### Theme QueryProvider

Theme 对外提供 `w_query('theme', 'copyTargetLayoutData', ...)`，供 CMS 等模块复制 Theme-owned 布局数据。调用方只传契约参数，不得直接写 Theme 布局表。

### 布局路径 API

跨模块解析主题布局路径只能调用 `Weline\Theme\Api\View\LayoutPathResolver`。历史
`Weline\Theme\Helper\LayoutPathResolver` 是 Theme 内部实现，其他模块不得直接引用。

### 静态资源发布 API

可选模块按请求路径发布开发主题覆盖资源时，只调用
`Weline\Theme\Api\Asset\StaticAssetPublisherInterface`，不得引用 Theme Service。

### 布局工作区 API

跨模块需要维护 Theme-owned 布局时，只调用
`Weline\Theme\Api\Layout\LayoutWorkspaceInterface`。调用方使用不可变
`LayoutIdentity`、`LayoutStatus` 与 `LayoutCopyResult` 交换纯数据；Theme 内部的
`ThemeLayout`、`WelineTheme`、版本 Model 和 Service 不得越过模块边界。

该契约覆盖激活主题 ID、版本初始化、布局替换、复制、发布、存在性检查和删除。
`LayoutIdentity::targetId` 接受 `0`，在 website target 下它明确表示系统默认站点，
不能被归一化为“未选择目标”。具体实现由模块清单的编译 Provider
`Weline\Theme\Service\LayoutWorkspace` 提供。

### 预览请求数据 API

跨模块组装 Theme 预览请求时，使用 immutable
`Weline\Theme\Api\Preview\PreviewContext::frontend()` 获取 `previewMode/shell/editorArea`
纯标量。调用方不得引用 `PreviewContextService`；布局草稿/发布状态使用
`Weline\Theme\Api\Layout\LayoutStatus`，不得引用 `ThemeLayout` Model 常量。

### 布局 Scope / store_mode（TASK-P1C-005-THEME）

`ThemeLayoutScopeNormalizer` 将布局 identity 的 `scope` 升格为规范三段存储串，并把非
`normal` 的 `store_mode` 编码为 `scope~{mode}`，使 normal/test 草稿行互不可见。
`PreviewContextService` 保留独立 `store_mode` 字段与三段 `scope`；写入布局时再编码。

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

## 前台只读契约

`PreviewThemeModeResolverInterface` 将预览 Session、主题选择和色系加载封装在 Theme 内；
`ComponentMetaReaderInterface` 只返回组件文件的标量数组 Meta。外部模块不得直接访问
`PreviewContextService`、`LayoutScanner` 或 `ComponentMetaParser`。
