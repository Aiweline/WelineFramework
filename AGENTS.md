# Weline AI 统一入口

本仓库受 `Weline_Ai` 内置项目智能 MCP 管理。任何 AI 客户端在读取知识、分析代码、制定计划、编辑或验收前，都必须完成以下流程：

0. **引导自检（第一步，自动）**：运行 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php`。它会检查 Git 分支、本地 MCP 注册/批准、STDIO 进程，并在可能时自动修复（重写 `.cursor/mcp.json` 与 `~/.cursor/mcp.json`、`cursor-agent mcp enable`、触发宿主重载）。只有返回 `project-guidance-bootstrap.v1.status=ready` 才进入下一步；`host_repair_needed` 表示已自动修复但当前对话可能仍需新开 Agent 回合；`blocked` 时按 `blocker` 处理（如在 `master` 上则 AI 自行 `git switch dev` 后重跑自检）。**禁止**把“去 Settings 自己配 MCP”当作首要方案。
1. 启动并调用项目配置注册的 `weline_project_intelligence` STDIO MCP（本地 STDIO，无 OAuth）。**禁止**调用 Cursor `mcp_auth`（只会弹出宿主授权，对本 MCP 无效）。若当前会话没有其工具或持续返回 `Transport closed`：先完成步骤 0 的自动修复并至少重试一次。若仍不可调用，则记录 `HOST_MCP_NOT_ATTACHED` 与自检、修复、重试证据，进入下述“受限原生回退”，无需新开 Agent 回合。若 MCP 已附加但在有界上下文批次后仍明确无法物化本次精确目标，则记录 `MCP_TARGET_UNAVAILABLE` 后同样允许回退。普通业务错误、一次性超时或可重试的校验失败不属于不可用。
2. 以仓库根目录和本次客户端会话标识调用 `prepare_project`。
3. 仅当返回 `project-readiness.v1.status=ready` 时继续，并在后续工具调用中携带返回的 `readiness_id`。框架仓必须在 `dev` 分支上开发；在 `master` 或其他分支上 `prepare_project` 会返回 `blocked`（`GIT_BRANCH_FORBIDDEN`），需先 `git switch dev`。
4. `prepare_project` 发现缺失模块文档时会自动执行确定性修复并继续；仅 `blocked` 时停止开发并报告原因。
5. 开始任务前调用 `resolve_task_context`，只使用返回的 `guidance-bundle.v1`、命中文档和源码证据；临时用户决定通过 `set_session_directives` 保存，不得自动写入长期规范。
6. **引导即告知（硬规则）**：`prepare_project` / `workflow_contract.v1.session_startup_notices` 要求——每个功能完成后对照归属模块 `doc/` 与实现是否一致，有差异必须改文档或代码；Web/UI 设计阶段就要考虑平板与 PC 响应式（≈768 / ≥1024，兼顾 375），验收收集多断点证据。

### 受限原生回退

- 仅在步骤 1 已确认 `HOST_MCP_NOT_ATTACHED` 或 `MCP_TARGET_UNAVAILABLE` 时启用；`ensure-project-guidance.php` / `prepare_project` 返回 `blocked`、Git 分支不合规或业务校验失败时禁止借此绕过门禁。
- 只允许使用精确已知路径的读取或 `rg`、`apply_patch` 编辑，以及与改动表面直接相关的定向 lint、测试、命令、HTTP、WLS 或 Browser 验证。必须保留用户已有改动，并在进度与最终报告中注明回退原因和未验证面。
- 禁止用 GitNexus、CodeGraphContext、Repomix、ctags 或其他仓库级索引重建替代 Weline MCP；禁止宽泛递归扫描、直接修改 `generated/`、跳过模块文档对齐、跳过 Web/UI 多断点证据，或用静态/单元证据冒充真实运行验收。
- MCP 在同一任务中恢复可用后，后续知识读取与编辑重新回到 readiness、`resolve_task_context`、`get_edit_bundle`、`apply_compact_edit` 流程。原生回退不扩大部署、生产数据、破坏性操作、提交或推送权限。

规范正文只维护在 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 和各模块 `doc/`。派生索引只存在于 MCP SQLite，不维护静态 Skill、客户端规则镜像或模块级静态 AI 索引文件。
