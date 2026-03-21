# Task: relocate ai site agent plan

- Started: 2026-03-21 14:59
- Status: completed
- Owner: Codex
- Source: 用户纠正“AI建站智能体的计划是计划中的一个，不是统筹智能体的笔记本；需要把这个计划探讨后写入 codex/plans 内”

## Goal

纠正上一轮误解，把 AI建站智能体从原始计划说明整理为一份独立的 Codex 计划文档，写入 `dev/ai/codex/plans/`，同时清理对原计划文件的误改。

## Context

- `dev/ai/plans/AI建站智能体的计划` 是项目中的一个计划项，不应承担 Codex 统筹入口职责。
- `dev/ai/codex/tasks/` 适合记录单任务执行过程，但还没有 `plans/` 用来存放结构化计划文档。
- 用户希望 Codex 将“探讨后的计划”沉淀到 `dev/ai/codex/plans/`。

## Progress

- 14:59 复查现状，确认上一轮把 Codex 统筹说明写进原计划文件是误解。
- 15:01 移除原计划文件中误加的 Codex 协作说明。
- 15:03 在 `dev/ai/codex/README.md` 中补充 `plans/` 目录定位。
- 15:05 新建 `dev/ai/codex/plans/ai-site-agent.plan.md`，将原始设想整理成结构化计划。
- 15:07 更新 `ACTIVE.md` 与任务日志，并标记上一条错误任务为 superseded。

## Files

- dev/ai/plans/AI建站智能体的计划
- dev/ai/codex/README.md
- dev/ai/codex/ACTIVE.md
- dev/ai/codex/plans/ai-site-agent.plan.md
- dev/ai/codex/tasks/2026-03-21/2026-03-21-1454-sync-codex-plan-to-ai-site-plan.md
- dev/ai/codex/tasks/2026-03-21/2026-03-21-1459-relocate-ai-site-agent-plan.md

## Risks / Blockers

- 原始计划仍是自由文本，后续如果继续更新，和结构化计划之间需要人工保持同步。
- 当前结构化计划属于第一版整理，后续还需要结合代码现状继续收敛实现边界。

## Next

- 如果继续推进该方向，优先按 `Suggested Task Breakdown` 逐项拆成可执行任务。

## Result

- 已把误加到原计划文件里的 Codex 统筹说明移除。
- 已新增 `dev/ai/codex/plans/ai-site-agent.plan.md` 作为 AI建站智能体的结构化 Codex 计划。
- 已把 Codex 目录区分为“计划文档”和“任务日志”两层。

## Verification

- 已检查原计划文件仅保留计划内容本身。
- 已检查 `dev/ai/codex/plans/ai-site-agent.plan.md` 创建成功。
- 未运行测试：本次为文档与规划整理，无代码执行逻辑变更。
