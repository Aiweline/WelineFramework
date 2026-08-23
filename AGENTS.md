# Weline AI 统一入口

本仓库受 `Weline_Ai` 内置项目智能 MCP 管理。任何 AI 客户端在读取知识、分析代码、制定计划、编辑或验收前，都必须完成以下流程：

1. 启动项目配置注册的 `weline_project_intelligence` STDIO MCP；启动失败时停止开发。
2. 以仓库根目录和本次客户端会话标识调用 `prepare_project`。
3. 仅当返回 `project-readiness.v1.status=ready` 时继续，并在后续工具调用中携带返回的 `readiness_id`。
4. `needs_repair` 时只展示确定性修复 Bundle，取得用户明确授权后才能调用 `repair_project_docs`；`blocked` 时停止开发并报告原因。
5. 开始任务前调用 `resolve_task_context`，只使用返回的 `guidance-bundle.v1`、命中文档和源码证据；临时用户决定通过 `set_session_directives` 保存，不得自动写入长期规范。

规范正文只维护在 `app/code/Weline/Ai/doc/`、`app/code/Weline/Framework/doc/` 和各模块 `doc/`。派生索引只存在于 MCP SQLite，不维护静态 Skill、客户端规则镜像或模块级静态 AI 索引文件。
