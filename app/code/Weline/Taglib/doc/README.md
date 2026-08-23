# Weline Taglib 自定义标签模块

## 当前定位

Taglib 是模板语义扩展层，不是“所有前端能力都往里塞”的万能入口。

优先判断：

- 页面骨架：用 layout
- 局部片段：用 partial
- 基础 UI 原语：用 component
- 可视化编辑器部件：用 widget
- 只有当模板语法本身需要扩展时，才创建 Taglib

如果任务与主题开发相关，先完成 `prepare_project`，再调用一次 `resolve_task_context`，同时将 `Weline_Taglib` 与 `Weline_Theme` 作为任务范围。随后按命中结果阅读：

1. [`../../Theme/doc/theme-inheritance-and-file-conventions.md`](../../Theme/doc/theme-inheritance-and-file-conventions.md)
2. [`../../Theme/doc/开发/Theme开发总指南.md`](../../Theme/doc/开发/Theme开发总指南.md)
3. [`../../Theme/doc/部件开发指南.md`](../../Theme/doc/部件开发指南.md)

## 当前推荐阅读顺序

1. 调用 `resolve_task_context` 获取当前任务命中的最小文档集合
2. **场景映射（强制）**：本 README 的“场景映射”章节
3. 新增/变更标签：`app/code/Weline/Taglib/doc/如何自定义Tag.md`
4. `app/code/Weline/Framework/View/doc/README.md`
5. `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
6. 如果是主题标签，再读取 MCP 命中的 Theme 专题文档

## Taglib 适合做什么

- 扩展 `<w:...>` 模板语义
- 统一编译复杂模板语法
- 让模板具备更稳定的声明式能力
- 承担一些 layout / widget / hook 无法自然表达的模板级协议

## Taglib 不适合做什么

- 替代浏览器请求层
- 直接承载复杂业务编排
- 代替 widget/component 的注册体系
- 通过生成产物修补页面问题

## 开发边界

### 严禁

- 直接改 `view/tpl`
- 直接改 `generated/`
- 在 `<w:*>` 属性里塞 `<?= ?>` 或 `<?php ?>`
- 禁止在模板里新增 raw `fetch` / `$.ajax` / `XMLHttpRequest`

### 必须

- 用户可见文本走 i18n
- 模板只改源文件
- 浏览器业务请求走 `Weline.Api.*`
- 先确认这件事真的该由 Taglib 解决

## 与 Theme 的关系

主题开发里最常见的 Taglib 包括：

- `w:slot`
- `w:hook`
- `w:block`
- `w:widget`
- `w:theme:template`
- `w:meta`

这些标签大多属于“主题运行时和模板协议的一部分”，开发时不要只盯 `Taglib` 模块 README，还要同步读 Theme 文档。

## 模块目录边界

Taglib 后台列表和标签同步只通过 `Weline\ModuleManager\Api\ModuleCatalogInterface` 获取模块
ID 与 data-only metadata。Taglib Model/Controller 不得引用 ModuleManager Model 或 Query Builder。
后台列表仍保持原 LEFT JOIN 可见字段、模块名模糊搜索、分页和更新时间排序语义；模块目录仅做
只读映射，不改变 Taglib 保存事务。

## 最小决策表

### 需求是“做一个页面挂载点”

优先：`<w:slot>` 或 layout

### 需求是“做一个可运营配置的内容块”

优先：widget

### 需求是“做一个通用按钮/卡片/输入框”

优先：component

### 需求是“需要一个新的模板声明语义”

再考虑 Taglib

## 场景映射（选择器用官方标签）

模板需要站点、店铺、语言、文件、编辑器、ACL、DataTable 等能力时，先查：

- 本 README 的场景映射与示例
- `resolve_task_context` 返回的当前 Taglib 源码、接口和专题文档

例如：站点选择用 `<w:websites:website:select .../>`，语言配置用 `<w:i18n:language:select .../>`，界面切换用 `<w:i18n:switcher .../>`（旧名 `language:switcher` 为别名）。禁止手写裸 select 拼领域选项。

浮层定位与 hover 保活用 `FloatingDropdownEmitter` / `WelineTaglibFloatingDropdown` 在标签输出内自洽，禁止往 Theme.js 塞标签交互。

新增标签后必须在同一任务内更新本 README 的场景映射与 `如何自定义Tag.md`；MCP 自动刷新索引。

## 快速示例

最小自定义 Taglib 仍然可以按 `TaglibInterface` 方式实现，但具体接口用法、编译流程与缓存细节请直接看：

- `app/code/Weline/Taglib/doc/README.md`
- `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
- `app/code/Weline/Framework/View/doc/Taglib/架构设计.md`

## 相关文档

- `app/code/Weline/Taglib/doc/README.md`
- `app/code/Weline/Taglib/doc/如何自定义Tag.md`
- `app/code/Weline/Framework/View/doc/README.md`
- `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
- `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`
- `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
- `app/code/Weline/Theme/doc/部件开发指南.md`
- `app/code/Weline/Theme/doc/widget-slot-attributes.md`
