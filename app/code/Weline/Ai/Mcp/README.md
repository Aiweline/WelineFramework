# Weline Project Intelligence MCP

这是 `Weline_Ai` 内置、依赖无关的本地项目智能 MCP。它以 PHP 8.2+ 和 SQLite 运行，通过 STDIO 服务 Codex、Cursor 及其他 AI 客户端；不依赖 WLS、Weline DI、业务数据库或网络服务。

## 定位（2026-09-14）

MCP 提供技能索引、代码地图与**领域硬规则下发**：

- 技能文档索引（`resolve_skill` / `get_skill`）
- 代码地图与项目知识检索（`resolve_task_context` / `search_project_knowledge` / `get_indexed_document`）
- 领域硬规则下发（`prepare_project.agent_guidance.hard_constraints`）
- 冷启动 alwaysApply 门禁（ensure → `.cursor/rules/weline-mcp-coldstart.mdc`）

**编码与改文件使用宿主原生编辑工具**（MCP 无写仓工具）。  
**工程任务在 MCP 已挂载/可挂载时必须** `prepare_project` 并遵守 `hard_constraints`；挂不上则宿主 Read `AI硬规则索引.md`。

## 唯一知识模型

- 长期知识只来自 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 与 `app/code/*/*/doc/`。
- 每个知识单元必须包含 `doc/README.md`、`doc/需求.md`、`doc/开发日志.md`；专题文档按需增加。
- 派生文件清单、全文内容、符号、关系和 Hash 只存于项目隔离的 SQLite。

## 工程会话流程

1. 运行 `ensure-project-guidance.php`（挂载 MCP + 写出冷启动 `.mdc`）。
2. **必须**以仓库根目录和 `client_session_id` 调用 `prepare_project`，刷新索引与 `hard_constraints` 并遵守。
3. 按需调用 `resolve_task_context` / `search_project_knowledge` / `get_skill` 取文档与技能。
4. **写码**：宿主原生编辑；遵守 `hard_constraints`（Theme / Taglib / Payment / e2e 等）。

## 入口与工具

```bash
php bin/learningctl doctor
php bin/learning-mcp
php tests/run.php --quick
```

常用检索工具（索引面 9 个）：

- `prepare_project` / `repair_project_docs` / `project_index_status`
- `resolve_task_context`
- `search_project_knowledge` / `get_indexed_document`
- `resolve_skill` / `get_skill`
- `health`

详细契约见 [PROJECT-INTELLIGENCE.md](docs/PROJECT-INTELLIGENCE.md)，部署与维护见 [OPERATIONS.md](docs/OPERATIONS.md)，安全边界见 [SECURITY.md](docs/SECURITY.md)。

## 维护关系

`app/code/Weline/Ai/Mcp` 是唯一代码源。子目录继续使用 Apache-2.0 许可。
