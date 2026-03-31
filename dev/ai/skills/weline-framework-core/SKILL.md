---
name: weline-framework-core
description: WelineFramework 核心开发技能。用于模块开发、控制器、模型、env/config、i18n、setup:upgrade、路由注册、http:request 验证。触发词：WelineFramework、Weline 模块、`php bin/w`、Backend/Frontend 控制器、`#[Col]`、menu.xml、env.php。
globs:
  - "app/code/**/*.php"
  - "**/register.php"
  - "**/etc/**/*.xml"
  - "**/etc/env.php"
alwaysApply: false
---

# Weline Framework Core

本技能用于 `E:\WelineFramework\DEV-workspace`。

## 快速流程

1. 先读 [`references/guardrails.md`](references/guardrails.md) 了解硬约束
2. 通用开发读 [`references/dev-workflow.md`](references/dev-workflow.md)
3. 领域特定任务按需读对应技能（见路由表）

## 核心约束（不可违反）

- 禁止编辑 `generated/`
- 禁止使用 `alert()` / `confirm()` / `prompt()`
- 禁止硬编码用户可见文本
- 禁止在 Upgrade.php 做字段 CRUD
- 禁止使用 `routes.xml`
- Schema 变更走 `#[Col]`/`#[Index]` + `setup:upgrade`

## 验证

- 代码变更后优先用 `php bin/w http:request ...` 验证路由/功能
- 前端/后台 UI 变更在可行时进行可视化验证

## 相关技能

- 混合任务路由：`weline-framework-skill-router`
- WLS/进程：`runtime-and-process`
- 模块开发：`module-development`
- 完整路由表见 `weline-framework-skill-router`
