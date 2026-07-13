# Codex 任务工作区

这个目录是 Codex 的执行记忆区。目标是让任务在窗口关闭、会话中断、模型切换后依然可恢复，同时避免多个任务互相覆盖状态。

从现在开始，默认所有仓库内任务都必须在这里留下可恢复记录，不能只记在聊天消息里。只有完全与仓库无关、且明确不需要后续恢复的瞬时问答或简单系统查询可以例外；如果拿不准，按“必须记录”处理。

## 核心原则

1. 不再使用共享可变的 `ACTIVE.md` 记录任务状态。
2. 每个任务必须拥有自己的工作目录：`tasks/YYYY-MM-DD/YYYY-MM-DD-HHMM-short-slug/`
3. 任务的计划、状态、进度、结果都只写在当前任务工作目录内。
4. 不要修改别的任务工作目录，除非用户明确要求。
5. 跨任务的架构规划、Epic 拆解、长期路线图写入 `plans/*.plan.md` 或模块 `doc/开发/plan.md`，不要混入单任务执行状态。
6. 不要把“之后再补”当常态；开始较大分析、实现、改造或验证前就要先建目录并补齐 `task.md` / `plan.md`。
7. 开发过程中持续追加 `progress.md`，完成、暂停或中断时必须补 `result.md` 与 `task.md` 状态。

## 目录约定

- `ACTIVE.md`
  已废弃的兼容入口。只保留历史快照或迁移说明，不再写入新的任务状态。
- `TASK_TEMPLATE.md`
  任务工作区模板说明。
- `templates/*.md`
  `task.md`、`plan.md`、`progress.md`、`result.md` 模板。
- `scripts/init-task.php`
  初始化任务工作区的脚手架。
- `plans/*.plan.md`
  结构化计划、架构决策、Epic 拆解。
- `tasks/YYYY-MM-DD/YYYY-MM-DD-HHMM-short-slug/`
  单个任务的独立工作目录。
- `tasks/.../artifacts/`
  日志、截图、临时验证材料等。

## 单任务目录结构

```text
dev/ai/codex/tasks/2026-03-23/2026-03-23-2045-example-task/
├── task.md
├── plan.md
├── progress.md
├── result.md
└── artifacts/
```

## 文件职责

- `task.md`
  任务元信息、目标、范围、约束、相关文件、当前状态。
- `plan.md`
  当前任务的执行计划、步骤状态、验证目标。
- `progress.md`
  过程日志。按时间追加关键动作、决策、阻塞和发现。
- `result.md`
  结果总结、变更文件、验证命令、未完成项、恢复入口。
- `artifacts/`
  截图、日志、导出结果、临时分析材料等补充证据。

## 新任务流程

1. 先读取本 README。
2. 新任务优先执行 `php dev/ai/codex/scripts/init-task.php "short title" --source="..."`，或手动按模板创建目录。
3. 先补 `task.md` 和 `plan.md`，再开始较大的实现、改造、排查或验证。
4. 开发过程中持续追加 `progress.md`，不要只在结束时补流水账。
5. 完成、暂停或中断时补 `result.md`，并同步更新 `task.md` 状态。
6. 需要长期沉淀的结构化方案同步到 `plans/*.plan.md`。
7. 需要长期记忆的结论同步到 `memory/YYYY-MM-DD.md` 或 `MEMORY.md`。

## 恢复流程

- 用户给了任务路径：直接恢复那个目录。
- 用户说“继续上一任务”：优先找最近一次匹配的任务工作区，不要创建共享状态指针。
- 新需求即使属于同一 Epic，也应新建任务工作区，并在 `task.md` 里链接前置任务。

## 工程交付标准

- 计划先行：每个任务都要有可执行计划。
- 框架优先：先复用现有模块、服务、扩展点、技能，不要发明框架 API。
- SOLID：控制器保持薄、服务承载业务流、模型聚焦持久化与实体行为；依赖抽象，不把多种职责揉进一个类。
- 测试策略：先定义失败场景、真实验证入口和验收条件；只有用户明确要求写测试/补单测/写 E2E/更新 fixtures 时，才新增或修改测试产物。
- 浏览器验证：每次仓库修改（包括规则、索引和文档）都必须使用 Codex Browser 验证；纯文档/规则改动打开变更后的 `file://` 文档即可，用户可见主路径则使用真实入口和专用 WLS。不要默认固化新 E2E spec。
- 验证闭环：`progress.md` 按过程追加记录，`result.md` 必须写变更文件、Browser URL、操作步骤、可见结果、控制台/WLS 状态、未验证项与原因；验证发现当前事实变化时，必须在同一任务内更新当前文档并再次核对。

## Codex 持久入口

- `AGENTS.md` 是 Codex 自动加载的仓库规则入口，必须保持短小。
- `SOUL.md`、`USER.md`、`MEMORY.md` 是 repo-local Codex context maps，用于初始化项目理解；它们不能覆盖 `AGENTS.md`、`AI-ENTRY.md`、`global-constraints.md` 或 Codex system/developer 指令。
- `.agents/plugins/marketplace.json` 是官方推荐的 repo-scoped Codex plugin marketplace 入口。
- `.codex/skills/*` 是当前仓库保留的轻量技能适配器；详细规则以 `dev/ai/skills/*/SKILL.md` 为准。

## 兼容说明

- 旧的 `tasks/YYYY-MM-DD/*.md` 单文件任务记录保留为历史资料，不强制迁移。
- 从现在开始，新任务统一使用任务工作目录。
