---
name: skill-trigger-reminders
description: |
  场景→技能映射。在以下场景必须主动读取并执行对应技能，提高命中率。
  触发词：修复错误后、做完了、完成了、修改了进程、改了 Server、WLS、Worker、static、StateManager、更新技能
globs: []
alwaysApply: false
---

# 场景→技能触发提醒

在以下场景**必须**读取并执行对应技能（规则会强制要求，本技能便于命中与检索）。

## 场景 → 技能映射

| 场景 | 必须读取的技能 | 执行内容 |
|------|----------------|----------|
| **修复错误/bug 后** | `error-learning`、`error-tracking` | 验证修复 → 更新 ERROR_LOG.md、COMMON_ERRORS.md、相关技能 Q&A；遵循 `.cursor/rules/auto-update-skills-on-error.mdc` |
| **完成任务/计划后**（都处理了、做完了、搞定了、完成了） | `post-plan-completion-check` | 执行校验清单（技能冲突、模块依赖、页面一致性等） |
| **创建/写计划**（plan、任务拆分、更新进度、plan.md、task.md） | `create-plan` | 先指定路径（大计划→.cursor/plans/，模块→模块/doc/开发/plan.md）；遵循 `.cursor/rules/create-plan.mdc` |
| **修改 Server/Worker/进程代码后** | `process-management` | 遵循进程管理规范；如有架构变更更新技能 |
| **新增 static 或修改 WLS/Runtime/State** | `weline-server`（状态管理章节） | 评估是否需注册 StateManager 重置；禁止请求级 static 不注册 |
| **用户说「更新技能/架构图/记录更改」** | 相关 SKILL.md | 更新涉及到的技能文件 |
| **新增/修改规则或技能**（添加规则、新增技能、编辑 .cursor） | `cursor-as-reference` | 在 `dev/ai/rules`、`dev/ai/skills` 下操作；禁止在 .cursor 下新增实质内容 |

## 快速参考

- 错误相关 → **error-learning** + **error-tracking** + rule `auto-update-skills-on-error.mdc`
- 完成相关 → **post-plan-completion-check**
- 进程/WLS → **process-management**、**weline-server**
- 规则/技能/引用 → **cursor-as-reference**（在 `dev/ai` 下编辑，.cursor 仅做引用）
