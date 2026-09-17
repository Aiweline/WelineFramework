# Weline_SiteSetupAssistant

建站助手：按 `website_id`（含默认站 `0`）展示上线/迁站任务进度，深链到各模块配置。

## 挂载

- 经 `Weline_Theme::backend::layouts::base::body-end` hook 注入；模板 `view/templates/backend/widgets/site-setup-assistant-float.phtml`。

## 任务模型（0.2.0）

- **权威源**：各业务模块 Extends `SetupTaskProviderInterface`（推荐继承 `AbstractSetupTaskProvider`）。
- **收集**：`SetupTaskCollector::collect()`；浮层**禁止**硬编码业务 tips。
- **指令**：`dev/ai-command/sitesetup/建站.md` — 只指导为涉及模块建 Provider，禁止指令直接建任务。
- 规格：`doc/开发/spec/site-setup-extends-task-providers.md`

## 状态

- `0.2.1`：原浮层硬编码任务全部迁入各业务模块 Provider。
- `0.2.0`：抽象 Provider + Collector 全量收集；删除硬编码 tips 与旧 Status 覆盖半套。
- `0.1.2`：旧 Status Extends（已删除）。
- `0.1.1`：后台通用 `base::body-end` 挂载。
