# Architecture

## Components

```text
                    Codex + personal plugin + SessionStart Hook
                                    |
                                    v
                         learning-mcp (stdio gateway)
                           /                    \
                          v                      v
     per-project intelligence SQLite       learning SQLite
 exact/FTS/trigram/sparse/graph/content   evidence/maturity/FTS
             ^                                  ^
             | targeted refresh                 | lease/result
       Git file catalogue                  analysis_jobs <---- learningd
             ^                                  ^
             |                                  |
       sealed local edits                 Hooks / idle scanner /
                                          Stop child / LaunchAgent
```

- `learningctl`：轻量 Hook 接收器和人工管理 CLI。Hook 错误默认写 stderr 并降级为非阻断。
- `learningd`：Job Worker，负责空闲会话扫描、lease、分析、重试、死信和审计；支持 `once`、`drain`、`run`。
- `learning-mcp`：本地 STDIO MCP Server，不监听网络端口。
- Personal plugin：Codex Host 启动层，自动发现 `learning-mcp` 和生命周期 Hooks；MCP 本身不能在未被 Host 启动前自我注册。
- `ProjectAutoContext`：从 Session 的 `cwd/worktree` 解析 canonical Git root，生成最多 4,000 字符的确定性路由 Context，并在 Hook stdout/数据库句柄关闭后 fork 增量索引子进程。
- `Scheduler`：显式管理 macOS LaunchAgent；不在安装/启动 MCP 时隐式改动系统。
- Learning SQLite：会话事件、证据、经验和审计的唯一真相源。
- Project SQLite：代码/文档/Skill 索引、gzip 完整文本库和编辑事务的可重建覆盖层；每项目独立 WAL。

模块是无 Composer 依赖的 PHP 实现。`src/bootstrap.php` 以固定顺序加载类，`bin/` 中三个脚本直接使用当前 PHP CLI。

## Project intelligence flow

1. 已启用插件的 Codex 新任务先启动 STDIO MCP，然后由 `SessionStart` 注入 canonical repository/index 位置和 `resolve_task_context` 路由契约。
2. `ProjectResolver` 以 canonical Git root、remote/root fingerprint 和 HEAD 绑定项目；不同 Checkout 使用不同项目库。
3. `ProjectAutoContext` 返回 Hook 响应后异步调用 `index_project incremental`；索引锁避免同项目并发重建。
4. `ProjectIndexer` 从 Git file list 发现文件，按配置排除第三方、生成产物、密钥、测试和大文件。
5. 只有 size/mtime/hash 变化的文件进入 Parser；删除项从 SQLite cascade 清理。
6. PHP/PHTML 由 `token_get_all` 解析结构；Markdown 由 Heading 分块；其他文本按有界行块处理。
7. 同一写事务更新 `indexed_files/indexed_file_contents/chunks/symbols/relations/skills`、unicode FTS、trigram FTS 和稀疏 Feature Hash。
8. `ProjectRetriever` 合并 exact、BM25、trigram、sparse-dot、relation proximity、freshness 和脱敏反馈，按 Token Budget 组装路径 Context；路径确定后，`get_indexed_files` 用一条 join 查询批量物化最多 50 个完整文件内容。
9. `EditService::prepare` 把模型 Draft 解析成 sealed plan；`apply` 复核 HEAD/revision/read-set，写 Journal 并原子替换。
10. Apply/Rollback 后同步 `indexPaths(changed_paths)`，因此下一次查询立即看到变化；外部改动由 `PostToolUse` 后台刷新，并在 freshness interval 后兜底校验。
11. `KnowledgeService` 根据 module code/docs digest 和确定性符号事实报告漂移，并生成 `doc/ai` 派生文件的 Edit Plan。
12. `CodexInvoker` 只在显式启用时，以 ephemeral/read-only 子进程返回 `doc-sync.v1`；所有写入仍回到外层 EditService。

完整工具、Edit Plan 和 module skill 契约见 [PROJECT-INTELLIGENCE.md](PROJECT-INTELLIGENCE.md)。

## Data flow

1. Hook 通过 stdin 提交事件。
2. Collector 解析有界 JSON，识别项目，递归脱敏，计算内容 Hash/Dedup Key，写入不可变 Event。
3. `Stop` 关闭 Session，以事件检查点构造 `analyze_session:<session>:php-v1:<checkpoint>` 幂等键并入队；默认 fork 短 worker。
4. `learningd` 每轮也扫描超过 `session_idle_after` 未活动的 active Session。它不强行关闭会话，之后出现新事件时用新检查点增量分析。
5. 分析器检测用户纠正、Agent 撤回、命令/测试/浏览器/运行时结果，先创建 Evidence，再生成 `candidate` Experience。
6. 相同项目和指纹会合并来源、证据与版本，而不是复制规则。
7. 人工通过 `mark_experience` 或 CLI 审核；状态门禁检查置信度、证据、结果验证和开放冲突。
8. MCP 只把成熟、未过期、作用域匹配且无开放冲突的经验包装为 Context Pack。
9. `record_outcome` 追加使用反馈；负面结果进入复审队列。
10. `request_promotion` 只创建 Proposal。目标文件写入、审批、发布和回滚在当前版本外部完成。

## Periodic analysis

```text
Stop Hook ──enqueue──> one-shot PHP child ──drain──┐
                                                   ├─> analysis_jobs
LaunchAgent ──interval──> learningd drain ──idle───┘
learningd run ──poll_interval──> idle scan + claim
```

- Stop 路径降低正常结束会话的学习延迟。
- 空闲扫描覆盖未触发 Stop、长时间挂起或客户端异常退出的会话。
- Job 唯一键、SQLite `BEGIN IMMEDIATE` claim 和 lease 让多个 worker 安全竞争。
- 无新事件的重复扫描命中同一唯一键，不会重复生成经验。

## Project identity

项目 ID 由归一化 Git remote 与本机 repository root 指纹共同派生，以避免同名目录串库，并默认隔离不同 Checkout。远程 URL 的用户信息、查询参数和 Fragment 不入库；无 remote 时仍以本地 Root Fingerprint 工作。

v1 不进行跨项目检索。`allow_cross_project` 虽保留在配置契约中，但安全校验禁止启用。

## Evidence and maturity

```text
candidate -> corroborated -> validated -> promotion_eligible -> external approval
     |             |            |                 |
     +-> rejected  +-> contested+-> revised       +-> validated
                       |            |
                       +-> deprecated+-> validated
```

`promoted` 不能由 MCP/CLI 设置。用户对自身意图、偏好和验收标准的纠正权重高；用户对技术事实的主张仍需代码、测试或运行时证据验证。

## Retrieval

- 硬过滤：`project_id`、成熟度、有效期、路径/语言/分支/版本作用域、开放冲突。
- 主检索：SQLite FTS5 `unicode61`。
- 中文或无空格文本：FTS 无匹配时，在同项目成熟条目上执行 Unicode bigram 回退，并要求最小相关度。
- 输出预算：按近似 4 字符/token 截断，优先返回更相关、更高置信度条目。
- 详细错误路径和证据只由 `explain_experience` 按需展开。

Project Intelligence 使用另一套混合排序：exact identifier/path → FTS5 BM25/trigram → deterministic sparse vector → symbol relation → freshness/feedback。稀疏向量不依赖网络或模型，也不冒充 Neural Embedding；精确符号不会被语义相似度取代。检索层只负责一次确定路径，内容层再以一次数据库调用返回这批文件，避免 N 个文件产生 N 次工具往返。

## Optional model path

当 `analysis.provider: openai`：

1. Extractor 接收脱敏、限长的 Episode Bundle，通过 Responses API Structured Outputs 返回严格 JSON。
2. 服务端检查所有 Evidence ID 必须来自本地 Evidence Index。
3. 独立 Verifier 再次判断支持度、范围和反证。
4. PHP 状态门禁仍是最终裁决者；模型不能直接验证、晋升或写策略文件。

Prompt 位于 `prompts/`，JSON 契约位于 `schemas/`。默认 provider 为 `none`，完全离线。

## Storage

`migrations/001_initial.sql` 创建：

- `projects`, `sessions`, `events`, `artifacts`, `evidence`；
- `experiences`, `experience_versions`, `experience_sources`, `experience_evidence`；
- `contradictions`, `feedback`, `proposals`；
- `analysis_jobs`, `audit_log`；
- `experiences_fts` 及 insert/update/delete 同步触发器。

PHP 启动时从只读 SQL 文件执行前向事务迁移；不编辑生成目录，也不需要构建时嵌入步骤。

`index-migrations/001_project_intelligence.sql` 为每个项目创建：

- `metadata`, `indexed_files`, `chunks`, `symbols`, `relations`, `skills`；
- `chunk_fts`, `chunk_trigram`, `chunk_vector_terms`；
- `knowledge_state`, `query_log`, `query_results`, `query_feedback`；
- `edit_transactions`, `validation_runs`。

`index-migrations/002_indexed_file_contents.sql` 创建 `indexed_file_contents`。它以 `file_id` 和 `indexed_files` 一一对应，保存 gzip/raw 正文、原始/存储字节数、内容 Hash、revision 和 indexed_at；文件、正文、Chunk、向量和符号在同一事务中更新。

项目索引是 Source Code/Module Docs 的派生缓存，可删除后完整重建；Learning 数据库不是缓存，不会随代码索引重建而删除。
