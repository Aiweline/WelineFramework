# Theme Editor → Weline UI 2.0 能力保留矩阵

> 目标版本：Weline_Theme `2.0.0`  
> 需求：`REQ-THEME-0003`  
> 基线日期：2026-08-21

## 迁移原则

- 权威业务基线是 `view/templates/backend/ThemeEditor/index.phtml` 与 `view/statics/js/theme-editor.js`，不是精简的演示页、旧编译模板或 `view/ui/js/pages/theme-editor.js` 薄壳。
- 迁移只替换 Bootstrap、Remixicon、字体图标和旧通用 UI 外壳；不删除、合并、降级或隐藏业务能力。
- 拖拽、排序、富文本或其他重写成本接近独立项目的功能引擎可以保留；Weline UI 负责其外壳、状态呈现和主题适配，引擎必须经模块 adapter 路由级隔离且不得携带全局 UI 皮肤。
- 每个能力必须保留原数据身份、API 契约、成功/空/错误/锁定状态和用户路径；仅 class、图标和 UI 挂载方式转为 Weline UI。
- 源模板编译后必须仍是本矩阵中的完整编辑器；任何薄壳编译产物都是阻断缺陷。

## 能力矩阵

| ID | 产品能力 | 迁移前源码证据 | Weline UI 2.0 等价目标 | 不可退化的验收点 |
|---|---|---|---|---|
| `TE-CAP-001` | 编辑身份选择 | 模板 `themeSelect/pageTypeSelect/layoutOptionSelect/editorAreaSelect/editorLangSwitcher`；JS `setCurrentLayoutSelection()`、`navigateEditorShell()` | Weline Select/Combobox + 布局身份状态 | 主题、layout type/option、frontend/backend、locale、scope/target 切换后身份和 URL 一致 |
| `TE-CAP-002` | 布局锁定与虚拟布局 | `parseLayoutLock()`、`enforceLayoutLock()`、`load/saveLockedVirtualLayoutSource()`、`publishLatestLockedVirtualLayoutVersion()` | Weline Alert/Badge/disabled 状态，保留虚拟布局 API | 锁定时不能突破身份；草稿加载、源码保存、版本发布可用 |
| `TE-CAP-003` | 响应式工作区 | `initSidePanels()`、`setSidePanelOpen()`、`toggleEditorFullscreen()` | Weline Drawer/Toolbar，原生 Fullscreen | 左配置、中预览、右部件库在 375/768/1024/1440 可达；面板偏好与全屏还原 |
| `TE-CAP-004` | 实时预览与结构视图 | `switchPreviewView()`、`kickoffLayoutPreview()`/`loadLayoutPreview()`、`resetStructureViewToEmptySlots()`；模板早期 `modulepreload` + 主脚本先于 async `widget-param` | Weline Tabs + 路由懒加载预览 | 实时/结构视图双向切换，loading/error/empty 状态不丢；backend 已注入预览 URL 时不二次整页导航 |
| `TE-CAP-005` | 草稿、已发布与真实前端预览 | `switchPreviewStatus()`、`openPreview()`、`openFrontendPreview()`、`openPublishedPreview()` | Weline Menu/Button/Badge | draft/published 状态明确；后台 iframe 与真实前端预览都可达 |
| `TE-CAP-006` | iframe 通信和链接拦截 | `handleIframeMessage()`、`setupIframeLinkInterception()`、`initCmsContextBridge()` | 保留同源预览 bridge，UI 事件纳入 `Weline.Theme.Editor` | slot/widget 选择、局部更新、CMS 上下文和嵌入式保存消息不丢 |
| `TE-CAP-007` | Slot 发现与诊断 | `fetchLayoutSlots()`、`collectDomSlotsFromDocument()`、`renderSlotsInfo()`、`renderMissingSlotWarnings()` | Weline Tree/Alert/Empty State | 合并 catalog/DOM slot，过滤 synthetic container，缺失警告和定位可用 |
| `TE-CAP-008` | 布局配置 | `loadLayoutConfig()`、`saveLayoutConfig()`、`saveLayoutSelection()`、`refreshLayoutOptions()` | Weline Form/Field/Disclosure + 自动保存状态 | layout option 与配置加载/保存/自动保存、locale 和预览刷新一致 |
| `TE-CAP-009` | 部件库加载 | `scheduleSecondaryEditorBootstrap()` → `deferWidgetLibraryLoad()`、`load/reloadWidgetLibrary()`、`initWidgetInfiniteScroll()` | Weline Card/Skeleton/Tabs/Search | 次于预览 kickoff；分页/无限滚动、服务端搜索、加载/空/错误状态完整 |
| `TE-CAP-010` | 部件库分类与推荐 | `setWidgetLibraryTab()`、`applyWidgetLibraryTabVisibility()`、`applySlotWidgetFilter()`、`highlightAcceptableWidgets()` | Weline Tabs/Badge/Toolbar | general/basic/applications 分类、slot 筛选、接受/拒绝规则、计数与推荐滚动不丢 |
| `TE-CAP-011` | 默认注入应用 | `loadDefaultInjectionLibrary()`、`refreshDefaultInjectionApplications()`、`applyDefaultInjection()` | Weline Card/Dialog/Progress | 当前身份/全部身份作用域、强推荐、已应用状态与确认流不丢 |
| `TE-CAP-012` | 点击或拖拽添加部件 | `addWidgetFromLibraryItem()`、`handleDragStart/Over/Drop()`、`saveWidget()`、`addWidgetToSlot()` | 保留当前可靠拖拽能力；Weline 只统一插入指示器、状态和主题外壳 | 区域、真实 slot、sort order、exclusive 和 page-layout 兼容校验不得绕过 |
| `TE-CAP-013` | 嵌套、选中与透视层级 | `resolveSelectedWidgetInnerSlot()`、`getWidgetStackAtPoint()`、`setShowActionsByNest()`、`handlePreviewSlotClicked()` | Weline Tree/Breadcrumb/Toolbar | 父子 slot、重叠部件逐层选中、内层添加和父 slot 回退不丢 |
| `TE-CAP-014` | 移动、排序、替换与删除 | `handleWidgetMoveUp/Down()`、`swapWidgetOrder()`、`initWidgetSortable()`、`persistSlotSortOrder()`、`handleWidgetReplace/Delete()` | 保留排序业务引擎 + Weline Toolbar/状态外壳 | 同 slot 移动、跨 slot 放置、替换、删除后选中恢复和失败回滚不丢 |
| `TE-CAP-015` | 部件配置表单 | `generateWidgetConfigForm()`、`generateWidgetConfigFormFallback()`、`renderConfigFormWithBackend()` | Weline Form/Field/Disclosure/Dialog | 后端渲染与受控 fallback 两路保留；分组、搜索、必填、提示和禁用状态不丢 |
| `TE-CAP-016` | 完整参数类型 | `renderField()`、`renderFormField()`、`initWidgetParamPickers()`、`bindArrayItemEvents()` | Weline Input/Select/Checkbox/Radio/Switch/File/Icon/Combobox | text/number/textarea/select/boolean/color/range/code/url/media/icon/array 及复合 item 增删改排序完整 |
| `TE-CAP-017` | 配置实时保存与预览 | `saveWidgetConfig*()`、`scheduleWidgetConfigAutoSave()`、`updateWidgetPreviewInIframe()` | Weline 字段状态/Toast/Spinner | 400ms 合并保存、静默/显式反馈、服务端归一化配置和局部预览更新不丢 |
| `TE-CAP-018` | 部件独立多尺寸预览 | `openComponentPreviewModal()` 与 `componentPreview*` 面板 | Weline Dialog/Tabs/Range | PC 1200、iPad 768、Mobile 375 和 320–1200 响应式拖动宽度完整 |
| `TE-CAP-019` | 多语言配置 | `fetchInstalledLocales()`、`setActiveConfigLocale()`、`reloadWidgetConfigWithLocale()`、`saveWidgetConfigWithLocale()` | Weline Language Select + Form | 已安装 locale、国旗/标签、主配置和指定 locale 读写不丢 |
| `TE-CAP-020` | 字段 i18n 与 AI 翻译 | `loadI18nValues()`（优先 scoped i18n 草稿，再 field-i18n/widget-config）、`saveI18nValues()`、`translateI18nValues()` | Weline Disclosure/Dialog/Progress | 打开面板自动回填已存译文；原文、各 locale 值、AI 批量翻译、保存和错误保留不丢 |
| `TE-CAP-021` | 部件 AI 动作 | `loadVirtualThemeAiCatalog()`、`openVirtualThemeAiDialog()`、`handleWidgetAiAction()` | Weline Dialog/Checkbox/Progress | skill/style 选择、作用目标、上下文、执行反馈和预览刷新不丢 |
| `TE-CAP-022` | AI 部件供应与放置上下文 | `getThemeWidgetAiContext()`、`registerThemeWidgetAiContextProvider()`、`placeWidgetFromProvider()` | `Weline.Theme.Editor` 公开业务命名空间 | 当前 theme/layout/slot、CSS 变量、已有值和供应部件放置契约不丢 |
| `TE-CAP-023` | 版本管理 | `load/renderVersionPanel()`、`preview/switch/deleteVersion()`、`saveLayout()`、`showPromptDialog()` | Weline Menu/Dialog/Badge/Empty State | 当前/已发布标记、列表、命名保存、预览、切换、重命名、受限删除不丢 |
| `TE-CAP-024` | 恢复原始布局 | `handleRestoreLayout()` 与 `apiRestoreOriginal` | Weline Confirm Dialog/Alert | 恢复前自动备份、结构视图清理、版本重载和失败无破坏 |
| `TE-CAP-025` | 保存、发布与嵌入式保存关闭 | `saveLayout()`、`publishTheme()`、`publishEmbeddedLayout()`、`postDashboardSaveCloseResult()` | Weline Dialog/Toast/Progress | 普通、layout-lock、dashboard embed 三条路径，draft→published、缓存刷新和父窗口结果不丢 |
| `TE-CAP-026` | 多人编辑锁与接管 | `initializeEditorLock()`、`refreshEditorLockActivity()`、`request/force takeover` 端点、`renderEditorLockOverlay()` | Weline Dialog/Alert/Overlay | 获取、心跳、释放、离页、请求接管、轮询和强制接管状态完整 |
| `TE-CAP-027` | 主题级外观盘 | `theme-disk-appearance.js`；`themeTokens/diskSave/diskSaveAs/diskSelect/diskDelete` | Weline Dialog/Form/Tabs/Token 表格 | panel/disk 选择、inherit token、实时预览、保存/另存/应用/删除和恢复不丢 |
| `TE-CAP-028` | 安全预览内容 | `sanitizeUrlForAttribute()`、`sanitizeHtmlForEditorPreview()`、`isSafeEditorPreviewCss()` | 可信 Node/Element 与明确 sanitizer 边界 | 不得因 UI 迁移放宽 URL/HTML/CSS 信任边界，不接收任意外部 HTML |
| `TE-CAP-029` | 可恢复状态与用户反馈 | `showToast()`、`showCustomConfirm()`、`showPromptDialog()` 及各 loading/empty/error 分支 | `Weline.UI.toast/dialog/progress/empty-state` | 全部结果可见、可键盘操作；关闭、焦点恢复、减少动画和移动端边界统一 |

## 当前恢复证据（2026-08-22）

- `TE-CAP-012/013/014` 的 iframe 执行入口已恢复为 Theme 模块完整 `editor-mode.js`，并由 `weline-ui-assets.json` 在 Weline preview adapter 之前编译；薄适配器不再覆盖完整引擎。
- 部件库拖拽通过同源 `drag-state` bridge 传入 iframe；所有真实 `data-wslot`（包括嵌套 slot）统一执行 accept/reject、exclusive、multiple、max 与 page-layout 支持判断，synthetic `container:<layout_id>` 不作为可放置目标。
- 视觉状态覆盖块内、块前、块后和拒绝，并同时使用绿色边框/插入线与文字状态，不只依赖颜色；跨 slot、取消、drop 与 pagehide 均清理状态，`prefers-reduced-motion` 下禁用动画。
- Chromium 合成页面已验证 `375/768/1024/1440`、超宽预览内容的 viewport 边界，以及 before=`sort_order 0`、after=`sort_order 2` 的父子窗口消息；真实 WLS 登录后落库与刷新回归仍因管理员会话前置失败保持 pending。

## API 与数据契约保留

编辑器根节点当前公布的下列能力组不得在 UI 迁移中删除：

- 部件：`save-widget`、`update-config`、`remove-widget`、`widgets`、`render-widget`、`widget-preview`、`paramrender/form`。
- 布局：`layout-options`、`layout-config`、`save-layout-selection`、`save-layout-config`、`compile-layout`、`save-compiled-layout`。
- 注入：`default-injections`、`apply-default-injection`。
- 预览/发布：`preview`、`layout-preview`、frontend preview、`publish`、`start-preview`、`exit-preview`、`publish-and-exit`。
- 版本：`versions`、`save-version`、`switch-version`、`restore-original`、`publish-version`、`delete-version`、`rename-version`。
- AI/虚拟主题：`ai-translate-config`、`ai-catalog`、`create-draft`、`block-action`、`source`、`save-source`、`publish-version`。
- 协作：`check-lock`、`release-lock`、`update-activity`、`request-takeover`、`check-takeover-request`、`force-takeover`。
- 外观盘：`theme-tokens`、`disk-save`、`disk-save-as`、`disk-select`、`disk-delete`。

UI 公共操作最终只通过 `Weline.UI`；Theme Editor 业务扩展面收敛为 `Weline.Theme.Editor`。移除 `window.ThemeEditor`、`window.switchToVersion` 等旧全局前，必须先将当前内部消费者全部改到新命名空间，不保留运行时别名。

## 验收证据要求

1. 对上表每个 `TE-CAP-*` 记录新源码入口、WebUI 操作、可见结果和失败/恢复分支。
2. 在 375、768、1024、1440 宽度、light/dark/system 下检查三栏、Dialog、Drawer、Menu、Tooltip 和部件预览。
3. 键盘验收覆盖 Tab/Shift+Tab、Enter/Space、Escape、方向键、焦点陷阱与恢复。
4. 源码、控制台和网络中不得出现 Bootstrap、Remixicon、jQuery 或旧 UI 全局对象。
5. 只有能力矩阵逐项通过、且生成模板仍为完整编辑器时，Theme Editor 迁移才能验收。
6. 每个被保留的专用功能引擎记录 owner、adapter、加载路由、网络证据和迁移前后能力用例；是否保留由重写成本与风险判断，不以“第三方”三个字机械删除。
