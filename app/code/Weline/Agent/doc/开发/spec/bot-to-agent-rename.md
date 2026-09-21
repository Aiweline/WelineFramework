---
status: validated
work_kind: feature
feature_slug: bot-to-agent-rename
module: Weline_Agent
updated: 2026-09-21
---

# 智能体模块重命名为智能体（Agent）

## 澄清记录

| # | 问题 | 答案 | 时间 |
|---|------|------|------|
| 1 | 产品名 | **智能体模块** | 2026-09-21 |
| 2 | 代码模块名 | **Weline_Agent**（替换 Weline_Agent） | 2026-09-21 |
| 3 | 是否保留 Bot 兼容层 | **否**：定义与代码全面替换 | 2026-09-21 |
| 4 | 数据表 | `weline_agent_*` → `weline_agent_*`，升级时 RENAME 保数据 | 2026-09-21 |

## 目标 / 非目标

**目标**

- 模块目录、命名空间、类名、表名、菜单、ACL、路由、场景适配器 code、Hook、扩展点、i18n 源串中的 Bot/机器人产品语义统一为 Agent/智能体。
- 已有业务数据经 Upgrade 迁移后仍可用。

**非目标**

- 不改 Agent 执行语义（工具循环、权限沙盒、记忆/调度能力本身）。
- 不改 `Weline_Ai` 基础设施（模型/供应商/MCP）。
- 不保留 `Weline_Agent` 运行时别名。

## EARS

- When 管理员打开后台菜单，the system shall 显示「智能体」而非「智能体」。
- When 系统升级检测到旧表 `weline_agent_*`，the system shall RENAME 为 `weline_agent_*` 并更新场景适配器/技能类名引用。
- When 业务代码引用原 `Weline\Agent\*`，the system shall 仅存在 `Weline\Agent\*`（无旧命名空间）。

## 用例

### UC1 升级后使用智能体

1. 运维执行模块升级。
2. 系统迁移表与注册信息。
3. 管理员进入「智能体」菜单，角色/会话/调度可用。

### UC2 新环境安装

1. 全新安装仅注册 `Weline_Agent`。
2. 创建 `weline_agent_*` 表与默认角色（scenario=`agent`）。

## 验收

- UT：`Weline_Agent` 原 Bot 单测改名后通过。
- 后台菜单源为 `Weline_Agent::*`，标题为中文「智能体」。
- 代码树无 `app/code/Weline/Bot`；检索无 `Weline_Bot` / `Weline\Bot` / `weline_bot_` 业务引用（规格/开发日志中的迁移说明除外）。
