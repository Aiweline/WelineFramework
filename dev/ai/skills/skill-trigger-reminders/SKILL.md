---
name: skill-trigger-reminders
description: 场景→技能映射。计划→planning；测试→testing；事件/Hook/Extends→extension-points；进程/WLS→runtime-and-process；提示→friendly-notifications；上下文压缩→context-compression（必加载）；SSE/流式/EventSource→sse-streaming。
globs: []
alwaysApply: false
---

# skill-trigger-reminders（极简版）

## 何时使用

- 快速查找场景对应的技能
- 确保关键场景命中正确技能

## 核心映射（必记）

| 场景 | 技能 |
|------|------|
| 创建计划前/写计划/都做完了/审计 | planning |
| 修改进程/WLS/static/State | runtime-and-process |
| 测试/单元测试/e2e/http:req | testing |
| 事件/Hook/Extends 定义与实现 | extension-points |
| 写 CSS/JS/前端 | theme-development |
| 提示/确认/弹窗 | friendly-notifications |
| 分页/列表 | database-model-standards |
| 模块间查询 | unified-query-provider |
| 菜单/ACL | acl-permission-system |
| Block/Taglib/Widget/DataTable | frontend-components |
| 配置/ env/扩展 | config-and-env |
| SSE/流式/EventSource/text/event-stream | sse-streaming |
| 新增/修改规则技能 | cursor-as-reference |

## 禁止

- 场景触发时未读取对应技能
- 创建计划不先执行 planning（pre-plan 分析）
