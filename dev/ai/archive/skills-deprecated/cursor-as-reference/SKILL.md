---
name: cursor-as-reference
description: .cursor 仅做引用。规则/技能/计划主仓在 dev/ai。新增/修改必须在 dev/ai 下操作。
globs: []
alwaysApply: false
---

# cursor-as-reference（极简版）

## 何时使用

- 新增规则、新增技能、修改规则、修改技能
- 编辑 .cursor、迁移规则、dev/ai

## 必做

- 规则主仓：`dev/ai/rules/`
- 技能主仓：`dev/ai/skills/`
- 计划主仓：`dev/ai/plans/` 或模块 `doc/开发/`
- 编辑、新增、删除一律在 dev/ai 对应目录操作
- .cursor 下仅保留引用（联结、README）

## 最小示例

- 新增规则 → `dev/ai/rules/xxx.mdc`
- 新增技能 → `dev/ai/skills/xxx/SKILL.md`

## 禁止

- 在 .cursor 下新建实质内容文件
- 在 .cursor/rules 或 .cursor/skills 下创建新文件（应去 dev/ai）
