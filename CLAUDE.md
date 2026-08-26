# Claude / Claude Code adapter

使用项目配置中的 `weline_project_intelligence` MCP。任何任务第一步运行 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php` 自检并自动修复宿主环境，再严格执行 [AGENTS.md](AGENTS.md) 的 `prepare_project` 与 readiness 门禁。
