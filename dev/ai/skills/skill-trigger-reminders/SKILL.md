---
name: skill-trigger-reminders
description: |
  场景→技能映射。在以下场景必须主动读取并执行对应技能，提高命中率。
  
  触发词：
  - 修复错误后、做完了、完成了
  - 修改了进程、改了 Server、WLS、Worker、static、StateManager
  - 更新技能、规则
  - **计划状态、进入测试、测试阶段、测试中、项目完成、计划完成、状态更新、完成度** ⭐
  - **测试、test、单元测试、phpunit、测一下、验证、怎么测、写测试** ⭐
  - **CSS、样式、style、颜色、color、背景、border、shadow、暗色、dark、亮色、light**
  - **JS、JavaScript、组件、component、widget、闭包、closure、IIFE、全局**
  - **前端、frontend、.phtml、模板、template、视图、view**
  - **Widget、部件、widget.php、<w:widget>、widget:refresh**
  - **提示、通知、消息、弹窗、确认、toast、alert、confirm、dialog、notification** ⭐⭐
  - **分页、pagination、pageSize、翻页、列表、getItems、getPagination、limit、offset** ⭐
  - **菜单、menu、menu.xml、ACL、权限、#[Acl]、parent_source、菜单消失、菜单层级** ⭐
globs: []
alwaysApply: false
---

# 场景→技能触发提醒

在以下场景**必须**读取并执行对应技能（规则会强制要求，本技能便于命中与检索）。

## 场景 → 技能映射

| 场景 | 必须读取的技能 | 执行内容 |
|------|----------------|----------|
| **修复错误/bug 后** | `error-learning`、`error-tracking` | 验证修复 → 更新 ERROR_LOG.md、COMMON_ERRORS.md、相关技能 Q&A；遵循 `.cursor/rules/auto-update-skills-on-error.mdc` |
| **完成任务/计划后**（都处理了、做完了、搞定了、完成了） | `post-plan-completion-check` + `create-plan` | 执行校验清单；**更新计划状态**（🔵测试中/🟢已完成）和完成度 |
| **创建/写计划**（plan、任务拆分、更新进度、plan.md、task.md） | `create-plan` | 先指定路径；**必须标注状态**（🔴未开始/🟡进行中/🔵测试中/🟢已完成）；遵循 `.cursor/rules/create-plan.mdc` |
| **进入测试阶段**（测试中、开始测试、测试阶段） | `create-plan` | 更新计划状态为 🔵 测试中（status: testing），更新完成度 |
| **计划完成**（测试通过、项目完成、计划完成） | `create-plan` + `post-plan-completion-check` | 更新状态为 🟢 已完成，记录完成时间，执行完成检查清单 |
| **修改 Server/Worker/进程代码后** | `process-management` | 遵循进程管理规范；如有架构变更更新技能 |
| **新增 static 或修改 WLS/Runtime/State** | `weline-server`（状态管理章节） | 评估是否需注册 StateManager 重置；禁止请求级 static 不注册 |
| **用户说「更新技能/架构图/记录更改」** | 相关 SKILL.md | 更新涉及到的技能文件 |
| **新增/修改规则或技能**（添加规则、新增技能、编辑 .cursor） | `cursor-as-reference` | 在 `dev/ai/rules`、`dev/ai/skills` 下操作；禁止在 .cursor 下新增实质内容 |
| **测试功能** ⭐（测试、test、单元测试、phpunit、测一下、怎么测、写测试） | `php-unit-testing` | 优先写 PHPUnit 单元测试；测试代码放模块 `test/` 目录；禁止单独创建测试脚本 |
| **写 CSS/样式** ⭐ | `theme-development` | 使用主题变量 `--backend-color-*`；禁止硬编码颜色；CSS 类名加组件前缀 |
| **写 JS/JavaScript** ⭐ | `theme-development` | 使用 IIFE 闭包；禁止全局变量/函数；显式暴露 API |
| **写组件/widget** ⭐ | `theme-development` | CSS 独立作用域；JS 独立作用域；暗色模式兼容 |
| **写前端/模板** ⭐ | `theme-development` | 模板标签语法；禁止 .phtml 内定义全局函数；使用闭包 |
| **创建 Widget 部件** ⭐ | `generate-component` | widget.php 注册、参数定义、`<w:widget>` 标签、widget:refresh |
| **创建 Block 类** | `block-development` | 继承 Block、`$_template` 格式、`__init()` 调用 parent、数据操作方法 |
| **创建自定义标签** | `taglib-development` | 实现 TaglibInterface、标签收集命令、callback 返回 |
| **用户提示/通知/确认** ⭐⭐ | `friendly-notifications` | 禁止 alert/confirm/prompt！使用 BackendToast/BackendConfirm |
| **创建 Service 类** | `service-development` | 业务逻辑层、依赖注入、接口定义、事务处理 |
| **使用缓存** | `cache-usage` | CacheFactory 继承、缓存键规范、TTL 设置、清理命令 |
| **读写配置** | `config-management` | Env::get/set、module_env、SystemConfig 动态配置 |
| **依赖注入/ObjectManager** | `object-manager` | getInstance、单例/非单例、Factory、__init() |
| **创建数据表格** | `datatable-component` | `<w:d-table>`、`<w:t-header>`、`<w:t-filter>`、CRUD |
| **创建控制器/路由** | `weline-routing` | HTTP 方法前缀、URL 生成、getUrl、@backend-url |
| **创建 Hook 扩展点** | `create-hook` | hook.php、`<w:hook>`、优先级、hooks 目录 |
| **模块间查询/通信** ⭐ | `unified-query-provider` | 使用 `w_query()` 或 `FrameworkQueryService`；注册 QueryProvider；禁止为查询创建独立事件 |
| **分页查询/列表查询** ⭐ | `database-model-standards` | **必须使用** `pagination()` 方法；禁止手动 count + limit/offset；使用 `getItems()` 和 `getPagination()` |

## 快速参考

- 错误相关 → **error-learning** + **error-tracking** + rule `auto-update-skills-on-error.mdc`
- 完成相关 → **post-plan-completion-check** + **create-plan**（更新状态和完成度）
- **计划状态** ⭐ → **create-plan**（🔴未开始/🟡进行中/🔵测试中/🟢已完成/⚫已取消）
- 进程/WLS → **process-management**、**weline-server**
- 规则/技能/引用 → **cursor-as-reference**（在 `dev/ai` 下编辑，.cursor 仅做引用）
- **测试** ⭐ → **php-unit-testing**（单元测试为主，http:req 辅助，测试代码放 test/ 目录）
- **前端/CSS/JS/组件** → **theme-development**（主题变量、IIFE 闭包、CSS 命名空间、暗色模式）
- **Widget 部件开发** → **generate-component**（widget.php、参数定义、`<w:widget>`、widget:refresh）
- **Block 类开发** → **block-development**（继承 Block、模板路径、__init、数据操作）
- **标签库开发** → **taglib-development**（TaglibInterface、标签收集、callback）
- **Service 层开发** → **service-development**（业务逻辑、依赖注入、接口）
- **缓存使用** → **cache-usage**（CacheFactory、缓存键、TTL、清理）
- **配置管理** → **config-management**（Env、module_env、SystemConfig）
- **依赖注入** → **object-manager**（ObjectManager、getInstance、Factory、__init）
- **数据表格** → **datatable-component**（d-table、t-header、t-filter、CRUD）
- **路由/URL** → **weline-routing**（HTTP 前缀、getUrl、@backend-url）
- **后台菜单/ACL** ⭐ → **acl-permission-system**（menu.xml、#[Acl]、parent_source、type=menus/pc、菜单消失）
- **Hook 扩展** → **create-hook**（hook.php、`<w:hook>`、优先级）
- **模块间查询/通信** ⭐ → **unified-query-provider**（`w_query()`、`FrameworkQueryService`、QueryProviderInterface、extends/Query/）
- **分页/列表查询** ⭐ → **database-model-standards**（`pagination()`、`getItems()`、`getPagination()`；禁止手动 count + limit）
- **用户提示/通知/确认** ⭐⭐ → **friendly-notifications**（禁止 alert/confirm/prompt！使用 BackendToast/BackendConfirm）

## 前端开发触发词

以下关键词出现时**必须**读取 `theme-development` 技能：

```
CSS, 样式, style, 颜色, color, background, 背景, border, 边框, shadow, 阴影,
暗色, dark, 亮色, light, 模式, mode, CSS变量, var(--, 
JavaScript, JS, 闭包, closure, IIFE, 作用域, scope, 全局, global,
组件, component, widget, 部件, 前端, frontend, 后台, backend,
.phtml, .css, .js, 模板, template, 视图, view,
card, table, form, input, button, modal, offcanvas, toast
```

## Widget 部件开发触发词

以下关键词出现时**必须**读取 `generate-component` 技能：

```
Widget, 部件, widget.php, <w:widget>, widget:refresh, WidgetRegistry, WidgetScanner,
部件注册, 部件类型, header widget, footer widget, carousel, banner, product widget,
PageBuilder, 可视化编辑器, 部件参数, widget params, param_schema.php,
热门商品, 轮播, 导航, 购物车, mini-cart, 侧边栏, sidebar widget
```

## 测试触发词

以下关键词出现时**必须**读取 `php-unit-testing` 技能：

```
测试, test, 单元测试, unit test, phpunit, 测一下, 验证, verify,
怎么测, 如何测试, 测试方法, 测试用例, 跑测试, 运行测试, 写测试, 加测试,
Test.php, /test/, assert, 断言, mock, 模拟, 
HTTP 测试, 接口测试, API 测试, 路由测试, http:req,
功能测试, 集成测试, integration test, e2e, 前端测试
```

**原则**：测试优先写 PHPUnit 单元测试，http:req 作辅助，禁止单独创建测试脚本文件！

## Block/Taglib/Service 触发词

以下关键词出现时读取对应技能：

```
Block 开发 → block-development:
  Block, 区块, 视图, $_template, assign, getData, setData, __init, render, 
  <block, @block, 模板路径, Weline_Module::

标签库开发 → taglib-development:
  Taglib, 标签, 标签库, tag, 自定义标签, TaglibInterface, <w:xxx>, @tag,
  taglib:collect, callback, 标签解析

Service 开发 → service-development:
  Service, 服务, 业务逻辑, ServiceInterface, Api 接口, Factory, Manager,
  Registry, 依赖注入, ObjectManager

缓存使用 → cache-usage:
  Cache, 缓存, CacheFactory, CacheInterface, cache:clear, TTL, 缓存过期,
  持久化缓存, permanently, Redis, File cache

配置管理 → config-management:
  Config, 配置, Env, env.php, SystemConfig, 系统配置, 模块配置,
  getConfig, setConfig, module_env

依赖注入 → object-manager:
  ObjectManager, 依赖注入, DI, Dependency Injection, getInstance, Factory,
  工厂, 单例, singleton, 非单例, __init, 初始化方法, FactoryObjectInterface

数据表格 → datatable-component:
  DataTable, 数据表格, d-table, t-header, t-filter, d-form, field,
  表格, table, 列表页, listing, CRUD, 排序, sortable, 筛选, filter

数据库模型/分页 → database-model-standards ⭐:
  (中文) 分页, 翻页, 页码, 每页, 分页查询, 列表查询, 数据列表, 获取列表, 分页列表,
  总数, 总页数, 第几页, 上一页, 下一页, 首页, 末页, 条目, 记录数,
  ORM, 模型, Model, 查询, select, fetch, fetchArray, 链式查询,
  (English) pagination, paging, page, pageSize, paginated, paged query, paged list,
  limit, offset, total, totalSize, lastPage, getItems, getPagination, items,
  per page, records, count, page number, page count

路由/URL → weline-routing:
  Controller, 控制器, 路由, route, routing, URL, getUrl, getBackendUrl,
  @url, @backend-url, @api, HTTP 方法, GET, POST, PUT, DELETE,
  getList, postSave, 404, 405, 路由错误,
  语言, 货币, language, currency, locale, WELINE_USER_LANG, WELINE_USER_CURRENCY,
  Url::parser, URL结构, URL解析, 语言不稳定, 货币不稳定, backend, frontend, area

Hook 扩展 → create-hook:
  Hook, 钩子, 扩展点, hook.php, <w:hook>, view/hooks, 优先级, priority,
  sort-order, solo, 模板扩展, 视图扩展

模块间查询/通信 → unified-query-provider ⭐:
  (中文) 查询器, 模块间查询, 跨模块查询, 模块间通信, 模块间数据, 模块间交互,
  跨模块获取, 跨模块调用, 跨模块读取, 跨模块操作, 统一查询, 获取数据, 提供数据,
  从其他模块获取, 从另一个模块, 调用其他模块, 请求其他模块, 访问其他模块,
  模块A查模块B, 模块数据交换, 模块协作, 注册查询器, 注册操作,
  (English) QueryProvider, FrameworkQueryService, QueryProviderInterface, w_query,
  inter-module, cross-module, module query, module communication, call another module,
  get data from module, query from module, access other module, module interface,
  introspect, provider, operation, extends/Query, execute(provider)
```

## 通知/提示/输入触发词 ⭐⭐

以下关键词出现时**必须**读取 `friendly-notifications` 技能：

```
提示, 通知, 消息, 弹窗, 弹出, 对话框, 确认, 警告, 错误提示, 成功提示, 失败提示,
toast, notification, message, alert, confirm, prompt, dialog, modal, popup,
popover, snackbar, feedback, warning, error message, success message, info,
用户提示, 操作确认, 删除确认, 提交确认, 保存成功, 保存失败, 操作成功, 操作失败,
BackendToast, BackendConfirm, AdminToast, AdminConfirm, FrontendToast,
show message, display message, notify, 提醒用户, 告知用户, 询问用户,
确定删除, 确认操作, 确认提交, 是否继续, 是否删除, 是否保存,
用户输入, 输入框, 输入弹窗, 让用户输入, 请输入, input dialog, showInput, 输入对话框,
请用户填写, 让用户填写, 填入, 获取用户输入, 请输入新的
```

**硬性禁止**：❌ `alert()` / `confirm()` / `prompt()` / `window.alert` / `window.confirm` / `window.prompt`

**必须使用**：
- ✅ 消息/提示 → `BackendToast.success/error/warning/info()`
- ✅ 确认操作 → `BackendConfirm.show()`
- ✅ 用户输入 → `BackendConfirm.showInput()`

## 计划状态触发词 ⭐

以下关键词出现时**必须**读取 `create-plan` 技能并更新计划状态：

```
计划状态, 状态更新, 更新状态, 进度, 完成度, 完成百分比,
进入测试, 测试阶段, 测试中, 开始测试, 进行测试,
项目完成, 计划完成, 测试通过, 测试完成, 全部完成,
做完了, 搞定了, 处理完了, 都处理了, 都搞了, 都改了,
status, testing, completed, in_progress, pending,
🔴, 🟡, 🔵, 🟢, ⚫, 未开始, 进行中, 已完成, 已取消
```

**状态更新规则**：
- 用户说「进入测试/测试阶段」→ 更新为 🔵 测试中
- 用户说「完成/搞定/做完」→ 检查任务，更新完成度，评估是否可标记完成
- 用户说「测试通过/计划完成」→ 执行完成检查清单，更新为 🟢 已完成

**总计划必须包含**：
- 状态头（状态、当前阶段、完成度、最后更新）
- 子计划进度汇总表（包含状态和完成度列）
