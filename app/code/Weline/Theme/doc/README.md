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

### 5. I18n 单向依赖

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

### 6. 跨模块边界

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
