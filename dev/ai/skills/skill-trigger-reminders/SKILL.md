---
name: skill-trigger-reminders
description: 开发技能映射入口。根据任务关键词匹配 `dev/ai/skills` 中的目标技能；命中后只读取对应技能的 `SKILL.md` 或必要 references。完整路由表见 `weline-framework-skill-router` 或 `_index.md`。
globs: []
alwaysApply: false
---

# skill-trigger-reminders（映射入口）

## 何时使用

- 需要先判断应该读取哪个开发技能
- 想压缩上下文，只在命中后再读取目标技能正文
- 处理 `dev/ai/codex`、任务状态、进度、resume

## 核心原则

1. 从任务提取关键词
2. 查询 `weline-framework-skill-router` 或 `_index.md` 路由表
3. 只读取命中的技能正文
4. 同时命中多个场景只保留最相关的 1~3 个

## 补充规则

- 通用模块开发优先命中 `module-development`，再按需要叠加更具体技能
- 若任务涉及"修 bug、回归、稳定性"且出现兜底倾向，强制叠加 `code-generation-standards`
- 详细映射统一维护在 `_index.md`，不要把整张映射表重复写进多个技能

## 禁止

- 批量读取 `dev/ai/skills/*/SKILL.md`
- 场景已命中却不读取目标技能正文
- 无关技能一起加载进上下文
