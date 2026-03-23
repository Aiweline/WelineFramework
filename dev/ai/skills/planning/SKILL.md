---
name: planning
description: 计划全流程。创建前 pre-plan 分析→create-plan 写 plan.md/task.md→完成后 post-plan 校验→plan-code-auditor 审计。状态标记、决策自审、Codex 任务工作区计划落盘。
globs:
  - "**/plan.md"
  - "**/task.md"
  - "**/*.plan.md"
alwaysApply: false
---

# planning（极简版：计划前 + 创建 + 完成后审计）

## 何时使用

- 创建计划、写计划、任务拆分、plan.md、task.md、都做完了、审计、对比计划与代码
- 需要区分跨任务的大计划和单任务执行计划

## 1) 计划前分析（pre-plan）

- 用户要创建计划时先执行：分析范围、依赖、缺口、完成标准，再进入 create-plan

## 2) 创建计划（create-plan）

- 状态：🔴未开始 🟡进行中 🔵测试中 🟢已完成
- 跨任务或长期方案写 `dev/ai/plans/*.plan.md`、模块 `doc/开发/plan.md`
- Codex 单任务执行计划优先写在当前任务工作目录的 `plan.md`
- 决策自审 7 问；列出命中技能；开发中实时更新 `plan.md` 和 `progress.md`

## 3) 完成后校验（post-plan-completion-check）

- 用户说做完了时，按命中技能清单检查；schema 是否 `#[Col]` + `setup:upgrade`；输出已符合/待修复

## 4) 计划审计（plan-code-auditor）

- 对比 plan + task 与代码；完成✅ / 部分⚠ / 未实现❌；每项给修复方案

## 禁止

- 不经自审直接给方案
- 计划完成不校验
- 只列完成不列缺口
