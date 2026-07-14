# Operations

## Process model

- `bin/learningctl hook`：由 Codex 短进程调用，默认非阻断。
- `bin/learning-mcp`：由 Codex Host 按需启动和关闭的 STDIO 进程。
- `bin/learningd drain`：扫描空闲 Session 并清空当前可领取队列后退出，适合周期调度。
- `bin/learningd run`：持续轮询的前台 worker，适合进程管理器。
- Stop Hook：默认 fork 一个短生命周期 PHP worker；`pcntl` 不可用时仍会保留 Job，等待后续 worker。

不需要 WLS、HTTP 端口、Go、Node.js、Composer、Redis、PostgreSQL 或外部消息队列。

Project Intelligence 初次索引和普通查询也不需要 WLS。只有显式启用 Nested Codex 文档 Planner 时才需要可用的 `codex` CLI；MCP 会依次检查配置绝对路径、`CODEX_CLI_PATH`、PATH 和 ChatGPT.app bundle。

## Automatic Codex startup

当前工作站的 `weline-project-intelligence@personal` 插件已安装、启用并信任 Hook Hash。新建 Codex 任务时会自动：

1. 启动 PHP STDIO MCP；
2. 用 Hook stdin 的 `cwd` 解析 canonical Git root 和独立 project ID/index DB；
3. 注入不含代码正文的索引元数据与 `resolve_task_context` 路由契约；
4. Hook 响应返回后异步运行 `index_project incremental`；空索引会自然完成首建；
5. 采集后续 Prompt/Tool/Compact/Stop 事件；`PostToolUse` 非阻断安排项目增量索引，Stop 默认排队并异步分析。

无需复制 YAML、编辑 TOML 或手动合并 Hook JSON。已运行任务不动态重载新插件；安装或更新插件后新建任务。

只读核验：

```bash
codex plugin list
codex mcp list
```

`plugin list` 应显示 `weline-project-intelligence@personal  installed, enabled`，`mcp list` 应显示 `weline-project-intelligence  enabled`。如果 Hook 源内容更改，Codex 会将新 Hash 标记为待审核；只能用 `/hooks` 审查并持久化信任，不应使用 `--dangerously-bypass-hook-trust`。

## Health check

```bash
./bin/learningctl doctor --config ~/.learning-mcp/config.yaml
```

输出包括 PHP/扩展检查、SQLite Migration/FTS 能力和数据量、Project Intelligence/Edit/Codex Planner 能力、Experience/Job 状态、开放冲突、分析器元数据、LaunchAgent 状态，以及自动晋升关闭状态。

MCP 客户端也可调用 `health`；该工具不会返回 API Key。

## Project index operations

建立或完整重建一个项目的派生索引：

```bash
./bin/learningctl intelligence index_project \
  --repository /absolute/repository \
  --input '{"mode":"full"}' \
  --config ~/.learning-mcp/config.yaml
```

增量刷新或定点刷新：

```bash
./bin/learningctl intelligence index_project \
  --repository /absolute/repository \
  --input '{"mode":"incremental","paths":["app/code/Weline/Foo/Service.php"]}' \
  --config ~/.learning-mcp/config.yaml
```

状态和真实数据库位置：

```bash
./bin/learningctl intelligence project_index_status \
  --repository /absolute/repository \
  --config ~/.learning-mcp/config.yaml
```

索引新鲜度不是只看 Git commit：dirty worktree 的文件 Hash 同样参与覆盖层。每次 `SessionStart` 会后台增量校验当前项目；MCP 自己应用的变化会同步定点重索引；会话中的外部变化由 `PostToolUse` 安排后台增量刷新，并在 `index.refresh_interval` 后由下一次上下文请求再次兜底校验。

索引查询示例：

```bash
./bin/learningctl intelligence resolve_task_context \
  --repository /absolute/repository \
  --input '{"task":"定位模块配置读取及影响文档","token_budget":3000}' \
  --config ~/.learning-mcp/config.yaml
```

CLI 的 `--input -` 从 stdin 读取 JSON，适合大型 Edit Plan；内容仍经过与 MCP 完全相同的 Schema、路径和事务门禁。

### 一次决定路径、一次批量读取

AI 不应对候选文件逐个发起读取。先调用一次 `resolve_task_context` 决定本次所有必读路径，再把完整数组交给一次 `get_indexed_files`：

```bash
./bin/learningctl intelligence get_indexed_files \
  --repository /absolute/repository \
  --input '{"paths":["app/code/Weline/Foo/A.php","app/code/Weline/Foo/B.php","app/code/Weline/Foo/doc/README.md"],"max_chars_per_file":50000,"max_total_chars":150000}' \
  --config ~/.learning-mcp/config.yaml
```

单次最多 50 个精确相对路径。响应保持输入顺序，包含每个文件的 Hash、内容、截断状态、内容存储编码和索引修订，并证明读取阶段没有扫描目录或逐个读取工作区。若列表超过总预算，`budget_omitted_paths` 明确列出未返回路径，调用方应缩小任务上下文，而不是隐式循环 N 次。

### 本仓库容量与延迟基线

2026-07-14 的验收记录：11,378 个 Git 可见文件中有 8,833 个进入索引，完整构建约 93.8 秒；捕获时索引为 78,834 Chunks、37,808 Symbols、274,811 Relations，SQLite 文件约 822 MiB。Schema v2 内容迁移对既有索引剩余 6,266 个文件的压缩正文补齐约 7.44 秒；7 个文件（99,128 字符）的批量读取只执行 1 条 SQLite 查询。无变化增量刷新内部约 0.49 秒、CLI 墙钟约 0.72 秒；同一 MCP 进程中的热上下文查询约 0.71 秒。

这些是本机实测值，不是 SLA。常规使用应让 Codex Host 复用 STDIO MCP 进程和连接缓存；反复调用 `learningctl intelligence` 会重复承担 PHP、SQLite 和冷页缓存启动成本。大仓库空间主要受 Chunk 数量影响；默认每 Chunk 只保留 24 个稀疏词项，trigram 只索引 doc/rule/skill。

## Edit recovery

- `prepare_edit` 只写私有数据库/Journals metadata，不写仓库。
- `apply_edit` 获得项目锁后才重新检查 HEAD/revision/read-set；Token 已使用或过期会被拒绝。
- 失败事务会尝试逆序恢复并保留 redacted error/status。
- `get_edit_status` 查看事务和 validation runs。
- `rollback_edit` 只有 current hash 等于封存 postimage hash 时才恢复；返回 `ROLLBACK_STALE` 时必须先人工检查，不能强制覆盖。
- Apply/Rollback 后索引失败不会伪装成完全成功；状态会指出 index pending/stale，并允许定点重试 `index_project`。

验证只接受 `php_lint`、`json`、`diff_check`、`weline_safe` 等固定 Profile。不要向 MCP 提交 shell command。

## Module knowledge operations

只读检查：

```bash
./bin/learningctl intelligence check_document_drift \
  --repository /absolute/repository \
  --input '{"module":"Weline_Foo"}' \
  --config ~/.learning-mcp/config.yaml
```

默认先预览：

```bash
./bin/learningctl intelligence sync_module_knowledge \
  --repository /absolute/repository \
  --input '{"module":"Weline_Foo","mode":"preview","include_skill":true}' \
  --config ~/.learning-mcp/config.yaml
```

`mode=apply` 还要求 `confirm=true`，并走 EditService 的 sealed transaction。生成器只覆盖 Marker-owned `doc/ai` 文件。Nested Codex 还要求 `knowledge.codex.enabled=true` 和请求 `use_codex=true`；默认不产生模型费用。

成功 Apply 会把当前代码、文档与生成 Skill digest 封存为 `fresh` baseline。再次运行相同同步应返回 `applied=false`、`already_current=true` 和零 operations；若仍重复改写，应检查生成内容是否包含时间、revision 等自引用易变字段。

## Periodic worker on macOS

只读检查：

```bash
./bin/learningctl scheduler print --config ~/.learning-mcp/config.yaml
./bin/learningctl scheduler status --config ~/.learning-mcp/config.yaml
```

显式安装并立即触发：

```bash
./bin/learningctl scheduler install --config ~/.learning-mcp/config.yaml
./bin/learningctl scheduler kickstart --config ~/.learning-mcp/config.yaml
```

手工执行一次相同的 drain 逻辑：

```bash
./bin/learningctl scheduler run-now --config ~/.learning-mcp/config.yaml
```

卸载：

```bash
./bin/learningctl scheduler uninstall --config ~/.learning-mcp/config.yaml
```

LaunchAgent 标签根据数据目录派生，因此不同数据目录不会互相覆盖。plist 只保存 PHP、worker、配置与数据目录路径，不保存 API Key。`StartInterval` 最小为 60 秒。

## Queue behavior

- Job 使用事件检查点派生的唯一 `idempotency_key`。
- Claim 使用 SQLite 写锁并设置 lease；进程崩溃后过期 Job 可被重新领取。
- 失败按指数退避重试，达到 `scheduler.max_attempts` 后进入 `dead_letter`。
- `review_feedback` 是复审信号；当前版本不会自动改写 Experience。
- 空闲 Session 保持 `active`，新事件到来后可再次按新检查点分析。

## Backup

受控备份前停止前台 `learningd` 和 MCP Client：

```bash
sqlite3 ~/.learning-mcp/learning.db ".backup '/secure/path/learning-backup.db'"
```

项目索引是缓存，通常无需备份；若要保留查询/编辑审计，可分别备份 `~/.learning-mcp/indexes/{project-hash}/project.sqlite` 和 `~/.learning-mcp/edit-journal/**`。Journal 可能包含源码 preimage，应按源代码同等敏感度保护。

备份包含项目经验和脱敏后的会话片段，权限与保留策略应至少和原数据库相同。

## Recovery

1. 停止 `learningd`，必要时卸载或 bootout LaunchAgent。
2. 保存当前数据库、`-wal` 和 `-shm` 文件用于取证。
3. 用受信任备份恢复 `learning.db`。
4. 运行 `learningctl doctor`。
5. 运行 `learningd once` 或 `learningd drain`，确认队列可领取。
6. 通过 MCP `health` 和 `search_experiences` 验证协议层。
7. 对目标仓库调用 `project_index_status`；项目索引损坏时移走对应 project-hash 目录并执行 full index，不要删除 `learning.db`。

## Hook troubleshooting

- Hook 采集失败默认写 stderr 并返回成功，避免影响正常编码；调试时加 `--strict`。
- `SessionStart --inject-project-context` 只允许用于 SessionStart；它返回 `hookSpecificOutput.additionalContext` 并在 stdout 完成后 fork 后台索引。
- 后台刷新失败会脱敏写入 `~/.learning-mcp/auto-index.log`；不会污染 MCP/Hook stdout。
- `--json` 输出入库结果，包括脱敏次数、隔离标志和 Stop Job ID。
- Hook 命令必须使用 CLI 与配置的绝对路径。
- 重复 Event 应返回 `inserted: false`，而不是复制数据。
- `do_not_learn: true` 会返回 skipped，不写事件。

手工验证：

```bash
printf '%s\n' '{"session_id":"manual-1","cwd":"/repo","hook_event_name":"SessionStart"}' \
  | ./bin/learningctl hook session-start --inject-project-context --inject-project-rules --config ~/.learning-mcp/config.yaml
```

## MCP troubleshooting

- stdout 只允许 JSON-RPC；诊断写 stderr。
- Codex 配置使用 `bin/learning-mcp` 绝对路径，不需要 `serve --stdio` 参数。
- `get_relevant_guidance` 无结果时，用 `search_experiences` 检查 Project ID、状态、路径和成熟度。
- Candidate 必须经 `list_candidates`/`explain_experience` 审核，不能通过降低 `minimum_status` 强制注入。
- `resolve_task_context` 返回 stale 时先调用 `index_project`；不要在明知索引过期时继续使用旧行号做写入。
- 已经拿到多个精确路径时只调用一次 `get_indexed_files`；检查响应中的 `database_round_trips=1` 和 `filesystem_content_read=false`，不要改回逐文件读取。
- 中文召回差时检查 health 中 `fts5_trigram`；即使 trigram 不可用，unicode FTS + CJK sparse terms 仍可降级工作。
- `prepare_edit` 的 `HASH_MISMATCH`/`INDEX_STALE` 是并发保护，不应通过删除 expected hash 绕过；重新获取上下文和 Draft。

协议烟测：

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | ./bin/learning-mcp --config ~/.learning-mcp/config.yaml
```

## Upgrade

1. 备份数据库。
2. 停止前台 worker；若已安装 LaunchAgent，先卸载。
3. 更新本目录 PHP/SQL/Prompt/Schema 文件。
4. 运行所有 PHP 文件的 `php -l`。
5. 运行 `learningctl doctor`；启动时自动执行前向 Migration。
6. 执行 Hook → worker → MCP stdio smoke。
7. 如果变更了个人插件的 `.mcp.json` 或 Hooks，按 plugin cachebuster/reinstall 流程重新安装，审查新 Hook Hash，并新建 Codex 任务验证；只修改本目录 PHP 时插件的绝对命令会直接使用新代码。
8. 重新安装 LaunchAgent 或启动 `learningd run`。

Migration 只前进，不自动降级。回滚代码前必须确认旧版本理解当前 Schema。
