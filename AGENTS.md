# Weline AI 统一入口

本仓库受 `Weline_Ai` 内置项目智能 MCP（`weline_project_intelligence`）管理。

**宿主引导只负责接通 MCP**；框架硬约束不在本文展开。权威正文：`app/code/Weline/Ai/doc/AI硬规则索引.md`。MCP 编译为 `hard-constraints.v1`，经 `prepare_project.agent_guidance.hard_constraints` 与 MCP `instructions` 前置下发。

0. **引导自检（第一步）**：运行 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php`。仅当 `project-guidance-bootstrap.v1.status=ready` 继续；`host_repair_needed` 时可能需新开 Agent 回合；`blocked` 按 `blocker` 处理（如在 `master` 则 `git switch dev` 后重跑）。禁止把「去 Settings 手写 MCP」当作首要方案；禁止调用 Cursor `mcp_auth`（本地 STDIO，无 OAuth）。
1. 调用 MCP `prepare_project`（仓库根 + 稳定 `client_session_id`）。若工具缺失或持续 `Transport closed`：完成步骤 0 并至少重试一次后仍不可用 → 记录 `HOST_MCP_NOT_ATTACHED`，进入下方受限原生回退。
2. 仅当 `project-readiness.v1.status=ready`（框架仓必须在 `dev`）继续，后续工具携带 `readiness_id`。
3. **立即阅读**返回的 `agent_guidance.hard_constraints`（硬约束正文）。`session_startup_notices` 仅作指路。交付 URL 机器契约见 `feature_delivery_urls` / `closeout_delivery_reminder`。
4. `prepare_project` 会自动修复缺失模块文档；仅 `blocked` 时停止。
5. 任务开始前调用 `resolve_task_context`，只使用返回的 `guidance-bundle.v1` / `workflow_contract.v1` 与命中证据；临时决定用 `set_session_directives`，不写入长期规范。

### 受限原生回退

- 启用条件：已确认 `HOST_MCP_NOT_ATTACHED`，或 `MCP_TARGET_UNAVAILABLE`（含密封编辑容量/物化失败）。细则见 MCP `hard_constraints.mcp_operational`。`blocked` / 非 `dev` / 业务校验失败不可借此绕过。
- 仅精确已知路径的读取、`rg`、原生编辑与定向验证；禁止仓库级索引替代品、宽泛扫描、改 `generated/`、跳过文档对齐与真实验收。
- MCP 恢复后立即回到 readiness → `resolve_task_context` → 密封编辑。回退不扩大部署/生产/提交权限。

规范正文只维护在 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 和各模块 `doc/`。派生索引只存在于 MCP SQLite。
