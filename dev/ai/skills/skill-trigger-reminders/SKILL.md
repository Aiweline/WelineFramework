---
name: skill-trigger-reminders
description: 开发技能映射入口。根据任务关键词匹配 `dev/ai/skills` 中的目标技能；命中后只读取对应技能的 `SKILL.md` 或必要 references，不批量读取全部技能。适用于计划、测试、事件、WLS、Session、前端、ACL、查询、配置、路由、SSE、PageBuilder 等开发场景。
globs: []
alwaysApply: false
---

# skill-trigger-reminders（映射入口）

## 何时使用

- 需要先判断应该读取哪个开发技能
- 想压缩上下文，只在命中后再读取目标技能正文
- 需要统一维护 `dev/ai/skills` 的开发技能映射

## 使用流程

1. 从任务里提取关键词、目标文件、修改类型。
2. 先查 `references/development-skill-map.md`，找到最匹配的技能。
3. 只读取命中的 `dev/ai/skills/<skill>/SKILL.md`。
4. 如果目标技能正文再引用其它 skill 或 reference，再继续按需读取。
5. 同时命中多个场景时，只保留最相关的 1~3 个技能进入上下文。

## 读取原则

- `context-compression` 是常驻压缩技能，不需要因为映射再重复全量读取。
- 修改规则、技能、计划仓时，额外读取 `cursor-as-reference`。
- 通用模块开发优先命中 `module-development`，再按需要叠加更具体技能。
- 详细映射统一维护在 `references/development-skill-map.md`，不要把整张映射表重复写进多个技能。

## 禁止

- 为了找答案批量读取 `dev/ai/skills/*/SKILL.md`
- 场景已经命中却不读取目标技能正文
- 无关技能一起加载进上下文
