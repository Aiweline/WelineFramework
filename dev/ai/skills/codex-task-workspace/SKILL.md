---
name: codex-task-workspace
description: Codex 任务执行工作区规范。处理 `dev/ai/codex`、`ACTIVE.md`、任务状态/进度/结果记录、任务恢复、以及需要把计划、SOLID、TDD、E2E 绑定到单任务交付闭环时使用。
globs: []
alwaysApply: false
---

# codex-task-workspace（任务工作区）

## 何时使用

- 处理 `dev/ai/codex` 目录下的规范、模板、脚手架或任务记录
- 用户提到 `ACTIVE.md`、任务状态、任务进度、任务恢复、工作目录、resume、result.md
- 开始一个需要完整计划、进度、验证闭环的 Codex 开发任务

## 必做

- 先读 `dev/ai/codex/README.md`
- 新任务优先执行 `php dev/ai/codex/scripts/init-task.php "short title" --source="..."` 创建任务工作区
- 所有可变状态只写当前任务目录，不写共享状态文件
- 开始前补 `task.md` 和 `plan.md`
- 过程中持续写 `progress.md`
- 完成或中断时补 `result.md`
- 如果任务还涉及具体代码实现，按需再读取 `planning`、`code-generation-standards`、`testing`

## 交付门禁

- 计划存在且可执行
- SOLID 约束已明确
- TDD 验证口已定义
- 关键链路 e2e 已补齐，或在 `result.md` 明确缺口
- 验证命令、结果、遗留风险已落盘

## 禁止

- 继续把 `dev/ai/codex/ACTIVE.md` 当作共享任务状态入口
- 把多个任务状态混写到同一个共享文档
- 只有结果没有过程记录
