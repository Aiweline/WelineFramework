# Weline AI 统一入口

本仓库的**编码/工程任务**必须使用 Weline 项目智能 MCP：`weline_project_intelligence`（源码 `app/code/Weline/Ai/Mcp`，本地 STDIO，无 OAuth）。

## 调用范围（先判再调）

| 任务类型 | 是否调用 MCP |
|----------|--------------|
| **非编码**：闲聊、身份/概念问答、与本仓实现无关的说明、纯口头建议 | **禁止** ensure / `prepare_project` / `submit_task_plan` 等 MCP 调用（浪费） |
| **编码/工程**：改代码或模块文档、诊断/评审本仓、部署规划、项目知识检索、功能验收收口 | **必须**走下方初始化 → `prepare_project` → 既有工作流 |

权威细则：`app/code/Weline/Ai/doc/AI硬规则索引.md`（`mcp_call_scope`）与 MCP `hard-constraints.v1`。

## 会话初始化（仅编码/工程任务）

Agent **自行**确认 MCP 是否正确，不要让用户去 Settings 手写配置，不要调用 Cursor `mcp_auth`。

```bash
php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php
```

读 JSON 里的 **`mcp_init_check`**（机器判定）与 **`agent_next_action`**（下一步）：

| `mcp_init_check.verdict` | 含义 | Agent 做什么 |
|--------------------------|------|--------------|
| `ready` | MCP 初始化正确 | 确认本回合工具列表含 `prepare_project` → 调用它 |
| `host_install_needed` | 宿主未挂上 MCP | 执行 `host_mcp_install` steps → 重跑 ensure |
| `host_repair_needed` | 代次/工具目录过期 | 按 `agent_next_action` 修复 → **新开 Agent 回合** → 重跑 ensure |
| `blocked` | 不可继续 | 按 `blocker` 处理（如在 `master` 则 `git switch dev`） |

**初始化完成标准**（全部满足才算 MCP 正确）：

1. `mcp_init_check.correct === true`（即 `status=ready`）
2. `mcp_init_check.checks.stdio_probe === true`（STDIO 能列出完整工具）
3. `mcp_init_check.checks.host_attached === true`（当前宿主已附着）
4. **本回合**工具列表可见 `prepare_project`（不可见则新开回合，非 MCP 配置问题）

通过后：`prepare_project`（仓库根 + 稳定 `client_session_id`）→ 读 `hard_constraints` → `resolve_task_context`。

框架硬约束不在此复制。权威正文：`app/code/Weline/Ai/doc/AI硬规则索引.md`。

## MCP 不可用时的受限原生回退

- 条件：ensure 重试后仍 `HOST_MCP_NOT_ATTACHED`，或密封编辑 `MCP_TARGET_UNAVAILABLE`
- 仅精确已知路径的读取、`rg`、原生编辑与定向验证；禁止改 `generated/`、跳过文档对齐与真实验收
- MCP 恢复后立即回到 ensure → `prepare_project` → `resolve_task_context`

规范正文只维护在 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 和各模块 `doc/`。

## Git 策略

**智能编辑器协议只提交本文件 `AGENTS.md`。** 不要提交各编辑器私有协议或 MCP 注册文件（如 `.cursor/`、`.cursorrules`、`.cursorignore`、`CLAUDE.md`、`.mcp.json`、`.codex/`、`.vscode/mcp.json`、`.github/copilot-instructions.md` 等）。MCP 挂载由 Agent 在本机按 ensure 指引完成。
