# AI 硬规则索引

> 任何 Weline 开发任务的**第一层路由**。`prepare_project` ready 后必须先遵守 MCP 下发的 `hard-constraints.v1`，再按本表定位必读文档。规范正文以链接文件为准；MCP `workflow_contract.v1` 为机器可读摘要。

## MCP 编译（权威落点）

| 产物 | 位置 | 职责 |
|------|------|------|
| `hard-constraints.v1` | `prepare_project.agent_guidance.hard_constraints` + MCP `instructions` preamble | 全局硬约束（由本索引与交付流程编译） |
| `session_startup_notices` | 同上 `agent_guidance` | **只指路**，不复制本表细则 |
| `workflow_contract.v1` surfaces | `resolve_task_context` | 按任务下发 Taglib/Theme/Hook 等细则 |
| 宿主 `AGENTS.md` / ensure | 仓库根 / 脚本 | 只负责接通 MCP，不写框架法 |

实现类：`app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php`。改本索引或硬约束摘要后，须跑 `php app/code/Weline/Ai/Mcp/tests/guidance-workflow-contract.php`。

## 使用方式

1. 从任务描述提取**关键词**（下表第一列）。
2. 阅读对应**必须先读**文档（不得跳过）。
3. 遵守**禁止**列；完成后跑**验证**命令（如有）。
4. 写代码前完成 [扩展点选型](../Framework/doc/3-开发/扩展点选型.md)。

## 工作区不可丢弃规则

MCP 的自愈、宿主重载、插件代次刷新、密封编辑、验证回滚和崩溃恢复必须保留任务开始前已经存在的 tracked、staged、untracked 与 ignored 脏改。MCP 子进程只允许只读 Git 检查，禁止 `git reset`、`git restore`、`git checkout`、`git switch`、`git clean`、`git stash` 等全部 Git 写操作，禁止 config/helper/pager 命令注入及 force/discard 变体。分支切换只能由工作区所有者显式执行。密封事务发现目标 Hash 漂移时必须保留现场并 fail-closed，不得拿 HEAD、索引或旧快照强盖当前文件。

## 任务路由表

| 任务关键词 | 必须先读 | 禁止 | 验证 |
|-----------|---------|------|------|
| `.phtml`、模板、Taglib、`<w:` | [Taglib/doc/README.md](../Taglib/doc/README.md)、[场景映射表.md](../Taglib/doc/场景映射表.md)、[如何自定义Tag.md](../Taglib/doc/如何自定义Tag.md) | 手写领域 select/input；`w:*` 属性内 `<?=` / `<?php`；**Taglib callback 返回 HTML 里写裸 `@static(...)`** | — |
| `@static`、静态资源 404、`/@static(` | [Framework/doc/static-resource-versioning.md](../Framework/doc/static-resource-versioning.md)、[09-static标签](../Framework/doc/4-内置标签/09-static-template-js-css标签使用指南.md) | 在 Taglib callback 字符串里用 `@static`（不会二次编译） | 浏览器 Network 无 `.../@static(` 字面量 |
| 写 HTML 标签、页面控件 | [标签全量索引.md](../Taglib/doc/标签全量索引.md)、[Framework/doc/4-内置标签/README.md](../Framework/doc/4-内置标签/README.md) | 能用 `<w:*>` / `<lang>` / `<w:hook>` 时用裸 HTML | — |
| 主题、layout、widget、partial | [Theme/doc/AI-INDEX.md](../Theme/doc/AI-INDEX.md)、[Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) | 改 `generated/`、`view/tpl`；layout 内嵌非 `Weline_Theme` 部件 | `php bin/w frontend:check-theme-layout-widgets` |
| 前台部件 JS、`Weline.declare`、`data-weline-load`、`weline.modules.js` | [前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md)、[Theme.js使用指南.md](../Theme/doc/Theme.js使用指南.md)、[Theme开发总指南.md §2.6](../Theme/doc/开发/Theme开发总指南.md) | 部件/布局 `<script src="@static(...js)">` / 裸 `<js>` 直拉模块；不注册 modules 就加载 | 属性可见 + Network 由加载器拉取；无双轨 script |
| 前端 UI、Weline UI、CSS 变量、地址表单、结账样式 | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md)、[场景映射表.md](../Taglib/doc/场景映射表.md) | 第三方 UI（Bootstrap/Element/Ant）；硬编码色值/间距；手写国家/省/市 input 替代 `<w:theme:address>` | 对照主题组件类名与 Token；Browser 多断点 |
| section、`weline-code` | [frontend-section-weline-code.md](../Theme/doc/frontend-section-weline-code.md) | 缺/空 `weline-code`；无语义名如 `section1`；同文件重复 code | `php bin/w frontend:check-section-code` |
| 前台文案、翻译、i18n | [Theme开发总指南.md §i18n](../Theme/doc/开发/Theme开发总指南.md)、[01-lang标签](../Framework/doc/4-内置标签/01-lang标签使用指南.md) | `.phtml` HTML 正文/属性内 `<?= __('...') ?>` | — |
| i18n CSV、中英翻译、collect | [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md) | 只改 CSV 不 `i18n:collect`；缺 en_US 或前后台词条不对齐 | `php bin/w i18n:collect Weline_Module` |
| 新建 Hook、`view/hooks` | [Hook创建规范.md](../Hook/doc/Hook创建规范.md)、[Hook使用指南.md](../Theme/doc/Hook使用指南.md) | 只有 `.phtml` 无 `hook.php` + `doc/hook/*.md` | `php bin/w setup:upgrade --route` |
| 新建 Event、Observer、`event.xml` | [事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md)、[event/README.md](../Framework/doc/event/README.md) | 发明未文档化事件名；跨模块直调 Service | 检索 `doc/event/` 与 dispatch 一致 |
| 跨模块读/写 | [扩展点选型.md](../Framework/doc/3-开发/扩展点选型.md) | 跨模块 `new` 对方 Service/Model | — |
| 浏览器 AJAX、表单提交 | [Weline.Api使用指南.md](../Frontend/doc/Weline.Api使用指南.md) | raw `fetch` / `axios` / `$.ajax` | — |
| 后台页面、Toast、Confirm | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) | JS `alert` / `confirm` / `prompt` | — |
| ORM、Model、Schema | [模块版本与升级门禁.md](../Framework/doc/3-开发/模块版本与升级门禁.md)、[模块开发完整指南.md](../Framework/doc/3-开发/模块开发完整指南.md) | 改 Model/Controller 不 bump `etc/module.php` version；密封编辑缺 bump → `EDIT_MODULE_VERSION_REQUIRED` | `php bin/w setup:upgrade -m Weline_Module` |
| 新建 Controller、路由、后台链接 | 同上 §控制器；**URL 动作为 `edit`/`add`/`save`，禁止写成 `getEdit`/`getAdd`/`postSave`** | 把 `get*`/`post*` 方法前缀拼进 URL | `php bin/w setup:upgrade --route`；对照 [03-自定义控制器.md §HTTP方法](../Framework/doc/2-快速开始/03-自定义控制器.md) |
| 交付、验收 URL、Browser 自测 | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)、[AI工程交付流程.md](./AI工程交付流程.md) §6–§7 | 单测/curl 冒充 UI 完成；省略「交付地址」；臆造路由；写死某一 IDE Browser | 宿主可用真实 Browser 跑用例 + curl 探活 |
| 多 todo 计划收口、进度汇报 | [AI工程交付流程.md](./AI工程交付流程.md) §7；MCP `plan_todo_evidence_closeout` | 计划未逐项举证就宣称「已完成」；Cursor todo 无证据标 completed；隐瞒未清库/未删代码/未跑 Factory Reset | 对每个 todo 给出路径/DB/命令/Browser 证据；部分完成须列「未完成清单」并写入 `doc/开发日志.md` |
| 密封编辑、写码前计划、`PLAN_REQUIRED`、每条用户需求完整工作流 | [AI工程交付流程.md](./AI工程交付流程.md) §1–§4；MCP `user_requirement_full_workflow` | 用户提出需求后不立即 `submit_task_plan`；缺 `requirements`/验收就写码；把 `PLAN_REQUIRED` 当完成 | `php app/code/Weline/Ai/Mcp/tests/task-plan-gate.php` |

## 写 HTML / 模板前决策流（硬规则）

```text
1. 用户可见文案？     → <lang> / @lang()；禁止 HTML 内 __()
   · 源文含逗号？     → 必须 <lang>…</lang> 或加引号 @lang('a, b')；禁止 @lang{a, b}（逗号当参数分隔，编译 ParseError）
2. 领域控件？         → 查 Taglib/场景映射表；禁止裸 <select>/<input>
3. 页面插槽/可运营块？ → Hook 或 Widget
4. 布局骨架？         → layout/partial/component/widget 分层，读 Theme 总指南
5. 以上都不满足？     → 才写原生 HTML，TaskContract 说明原因
```

## 会话反复纠正清单

| 纠正点 | 权威文档 |
|--------|----------|
| Hook 只有 phtml、无规约 | [Hook创建规范.md](../Hook/doc/Hook创建规范.md) |
| Event 名未文档化就 dispatch | [事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md) |
| 缺 `weline-code` | [frontend-section-weline-code.md](../Theme/doc/frontend-section-weline-code.md) |
| 手写 select 代替 Taglib | [场景映射表.md](../Taglib/doc/场景映射表.md) |
| 前端不用自研主题 / 硬编码视觉 / 手写地址级联 | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md) |
| 写 HTML 不查 Taglib | 本文 + [标签全量索引.md](../Taglib/doc/标签全量索引.md) |
| 前台 phtml 用 `__()` | [Theme开发总指南.md §i18n](../Theme/doc/开发/Theme开发总指南.md) |
| `@lang{含,逗号}` 编译 ParseError | [01-lang标签使用指南.md](../Framework/doc/4-内置标签/01-lang标签使用指南.md)：逗号为参数分隔；改 `<lang>` 或加引号 |
| Theme layout 内嵌他模块 widget | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) |
| 部件直接 `@static` / `<script src>` 拉 JS 模块 | [前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md)（`data-weline-load` / `Weline.declare`） |
| `w:*` 属性写 PHP | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) |
| raw fetch/ajax | [Weline.Api使用指南.md](../Frontend/doc/Weline.Api使用指南.md) |
| alert/confirm | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) |
| routes.xml / 改 generated | [AI-ENTRY.md](../../../AI-ENTRY.md) |
| 交付 URL 格式错误 / 省略交付地址 | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md) |
| 未 Browser 自测就宣称完成 | 同上；只能报「代码已改，WebUI 验收未完成」 |
| 多 todo 计划未逐项举证就说「已完成」 | [AI工程交付流程.md](./AI工程交付流程.md) §7（`plan_todo_evidence_closeout`）；须报「部分完成」+ 未完成清单 | |
| 让用户手写 MCP Settings | [AGENTS.md](../../../AGENTS.md) |
| 改 Model 字段不 bump 模块 version | [模块版本与升级门禁.md](../Framework/doc/3-开发/模块版本与升级门禁.md)（MCP 密封编辑强制 `EDIT_MODULE_VERSION_REQUIRED`） |
| 无会话计划就密封编辑 | [AI工程交付流程.md](./AI工程交付流程.md)（`submit_task_plan` → `task-plan.v1` 含 `requirements`；否则 `PLAN_REQUIRED`） |
| 用户提出需求后不建完整工作流 | 同上 §1（`user_requirement_full_workflow`：需求分析→验收） |
| 新建 Controller 不 upgrade/route | 同上 |
| URL 写成 `/getEdit`、`/getAdd`、`/postSave` | [03-自定义控制器.md §HTTP方法](../Framework/doc/2-快速开始/03-自定义控制器.md)：`getEdit()`→路径 `/edit`，`postSave()`→`/save`；前缀只约束请求方法 |
| 改 CSV 不 collect / en_US 未译 | [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md) |
| 前台加词后台 CSV 不补 | 同上 |
| Taglib callback 里写 `@static(...)` 导致 404 | [如何自定义Tag.md §静态资源](../Taglib/doc/如何自定义Tag.md)、[I18n/Taglib/Local.php](../I18n/Taglib/Local.php) `resolveModuleStaticUrl` |
| 控制台 `.../@static(Weline_*.css)` 404 | 同上；查 `<w:*>` 标签 callback 是否裸输出 `@static` |

## MCP 检索

```text
resolve_task_context(task="...", kinds=["doc","rule"])
get_indexed_document(path="app/code/Weline/Ai/doc/AI硬规则索引.md")
```

固定工作流摘要：`guidance-bundle.v1.workflow_contract.v1` 与 `pinned_fragments`。

## 相关

- [文档索引.md](./文档索引.md)
- [AI工程交付流程.md](./AI工程交付流程.md)
