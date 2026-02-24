---
name: cursor-as-reference
description: |
  .cursor 仅做引用，规则与技能主仓在 dev/ai。新增/修改规则、技能、计划时必须在 dev/ai 下操作。
  Use when: 新增规则、新增技能、修改规则、修改技能、编辑 .cursor、迁移规则、dev/ai、cursor 引用
globs: []
alwaysApply: false
---

# .cursor 仅做引用

本技能约束：**规则与技能的主存储位置是 `dev/ai`，`.cursor` 下仅保留引用（目录联结）**。

## 一、主仓与引用关系

| 内容     | 主仓（编辑位置）   | .cursor 下的表现       |
|----------|--------------------|------------------------|
| 规则     | `dev/ai/rules/`     | `.cursor/rules` → 联结 |
| 技能     | `dev/ai/skills/`   | `.cursor/skills` → 联结 |
| 大计划   | `dev/ai/plans/`    | `.cursor/plans` → 联结 |

编辑、新增、删除规则/技能/计划时，**一律在 `dev/ai` 对应目录操作**；不要在 `.cursor` 下新建实质文件（仅允许 README、联结等引用说明）。

## 二、何时必须遵循

- 用户说「添加规则」「新增技能」「写一条规则」等 → 在 `dev/ai/rules` 或 `dev/ai/skills` 下创建/编辑。
- 用户说「修改 .cursor」「迁移规则」「cursor 引用」等 → 确保变更落在 `dev/ai`，`.cursor` 仅引用。
- 任何在 `.cursor/rules`、`.cursor/skills`、`.cursor/plans` 下的编辑 → 等价于编辑 `dev/ai` 下对应路径（因联结），无需再复制一份到 .cursor。

## 三、路径书写约定

- 在规则、技能正文中，可继续写 `.cursor/plans/`、`.cursor/skills/xxx` 等路径（与 `dev/ai/plans/`、`dev/ai/skills/xxx` 等价）。
- 若需明确写出主仓路径，使用 `dev/ai/rules`、`dev/ai/skills`、`dev/ai/plans`。

## 四、对应规则

强制约束见：`dev/ai/rules/cursor-as-reference.mdc`（或 `.cursor/rules/cursor-as-reference.mdc`）。
