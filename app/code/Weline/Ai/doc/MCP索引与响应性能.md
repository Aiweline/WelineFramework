# MCP 索引与响应性能（参考）

> **过时说明（2026-09-13）**：本文仅保留索引/检索性能要点。MCP 是可选知识面（索引、代码地图、技能、领域规则）；编码用宿主原生编辑。

## 仍可参考

1. **符号片段预算**：可容纳时返回完整符号；不足时标明 `content_complete=false` / `truncated` 与完整范围。
2. **索引默认上限**：约 1 MiB；按内容 Hash 校验新鲜度。
3. **指导响应预算**：按最终 tools/call JSON 估算（Unicode 字符数÷4），默认只传任务匹配规范，完整规则在准备阶段下发。
4. **定向刷新**：已准备会话对显式文件/目录做内容 Hash 刷新；全量发现留给准备/周期任务。
5. **宿主工具面**：插件 `enabled_tools` 与 ensure 对齐索引面 9 工具；按实际运行宿主识别。

## 定向验收

- `context-response-budget.php`、`host-detection.php`、`readiness-incremental-scope.php`、`index-directory-scope.php`
- `php app/code/Weline/Ai/Mcp/tests/run.php --quick`

交付地址：N/A（本地 MCP / CLI，无 Web 页面）。
