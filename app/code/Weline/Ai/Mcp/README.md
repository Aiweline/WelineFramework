# Weline Project Intelligence MCP 0.13.0

这是 `Weline_Ai` 内置、依赖无关的本地项目智能 MCP。它以 PHP 8.2+ 和 SQLite 运行，通过 STDIO 同时服务 Codex、Cursor 及其他遵守 readiness 协议的 AI 客户端；不依赖 WLS、Weline DI、业务数据库或网络服务。

## 唯一知识模型

- 长期知识只来自 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 与 `app/code/*/*/doc/`。
- 每个知识单元必须包含 `doc/README.md`、`doc/需求.md`、`doc/开发日志.md`；专题文档按需增加。
- 派生文件清单、全文内容、符号、关系和 Hash 只存于项目隔离的 SQLite。
- 仓库技能投影已退役。`resolve_skill`、`get_skill` 只是 `resolve_task_context` 的动态兼容别名。

## 强制调用顺序

1. 客户端启动 `bin/learning-mcp`。
2. 以仓库根目录和唯一 `client_session_id` 调用 `prepare_project`。
3. 只有 `project-readiness.v1.status=ready` 才能继续。
4. `needs_repair` 只返回确定性、create-only 的修复 Bundle；用户明确授权后调用 `repair_project_docs`。
5. 开发前调用 `resolve_task_context`，后续受保护工具都携带同一会话的 `readiness_id`。
6. 临时用户决定写入 `set_session_directives`，仅在当前 MCP 进程内存中生效。

readiness 绑定项目身份、Git revision、模块清单、必需文档 Hash 和客户端会话。外部编辑会在下一次受保护调用前触发 freshness 检查；失效 readiness 必须重新准备。

## 入口与工具

```bash
php bin/learningctl doctor
php bin/learning-mcp
php tests/run.php --quick
php tests/project-readiness.php
```

核心公共工具：

- `prepare_project` / `repair_project_docs`
- `set_session_directives`
- `resolve_task_context`
- `resolve_deploy_plan`（只读调用 `Weline_Deploy` 公开 CLI）
- `search_project_knowledge` / `get_indexed_document`
- `get_edit_bundle` / `apply_compact_edit`
- `get_edit_status` / `rollback_edit`
- `resolve_skill` / `get_skill`（动态兼容别名）

详细契约见 [PROJECT-INTELLIGENCE.md](docs/PROJECT-INTELLIGENCE.md)，部署与维护见 [OPERATIONS.md](docs/OPERATIONS.md)，安全边界见 [SECURITY.md](docs/SECURITY.md)。

## 维护关系

`app/code/Weline/Ai/Mcp` 是 0.13.0 起的唯一代码源。独立发行仓只由本目录生成一致快照，并在 0.13.0 后冻结维护。子目录继续使用 Apache-2.0 许可。
