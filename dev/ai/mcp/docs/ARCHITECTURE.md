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
                                                   |
                                                   v
                                      evidence-gated skill projector
                                      Codex classify -> PHP render
                                      -> dev/ai/skills -> reindex
```

- `learningctl`：轻量 Hook 接收器和人工管理 CLI。Hook 错误默认写 stderr 并降级为非阻断。
- `learningd`：Job Worker，负责空闲会话扫描、lease、分析、重试、死信和审计；支持 `once`、`drain`、`run`。
- `learning-mcp`：本地 STDIO MCP Server，不监听网络端口。
- Personal plugin：Codex Host 启动层，自动发现 `learning-mcp` 和生命周期 Hooks；MCP 本身不能在未被 Host 启动前自我注册。
- `ProjectAutoContext`：从 Session 的 `cwd/worktree` 解析 canonical Git root，生成最多 4,000 字符的确定性路由 Context，并在 Hook stdout/数据库句柄关闭后把刷新请求交给 sidecar，不可用时再 fork 一次性子进程。
- `IndexSidecar`：用私有 Unix socket 复用 PHP/SQLite 热状态，按项目合并 100ms 内的刷新；整仓请求覆盖定点请求，15 分钟空闲后自动退出。
- `Scheduler`：显式管理 macOS LaunchAgent；不在安装/启动 MCP 时隐式改动系统。
- `LearningSkillService`：从已验证项目经验构造受控分类输入，调用隔离 Codex 对 ID 分组；PHP 再按经验路径确定模块归属，在项目/模块 marker-owned 边界内渲染技能、索引之索引、事务化写入和定点重索引。
- Learning SQLite：会话事件、证据、经验和审计的唯一真相源。
- Project SQLite：代码/文档/Skill 索引、gzip 完整文本库和编辑事务的可重建覆盖层；每项目独立 WAL。

模块是无 Composer 运行时依赖的 PHP 实现。`src/bootstrap.php` 以固定顺序加载类，`bin/learning-mcp`、`bin/learningctl`、`bin/learningd` 三个核心入口直接使用当前 PHP CLI。独立发行额外提供 `composer.json`、`bin/weline-mcp-install` 和无依赖的 `bin/weline-mcp.js`；它们只负责安装、注册或把 STDIO/参数/信号转交给同一 PHP Server，不形成第二套协议实现。

## Project intelligence flow

1. 已启用插件的 Codex 新任务先启动 STDIO MCP，然后由 `SessionStart` 注入 canonical repository/index 位置和 `get_edit_bundle → apply_compact_edit` 精简路由契约。
2. `ProjectResolver` 以 canonical Git root、remote/root fingerprint 和 HEAD 绑定项目；不同 Checkout 使用不同项目库。
3. `ProjectAutoContext` 返回 Hook 响应后向 `IndexSidecar` 投递刷新；Collector 会展开 Codex 的沙箱 Node 编排工具：纯 Browser/只读嵌套调用不刷新，`apply_patch` 走精确路径，动态 Shell/终端输入/未知嵌套工具保守退化为整仓 incremental。项目锁保护最终 SQLite 事务。
4. `ProjectIndexer` 从 Git file list 发现文件，按配置排除第三方、生成产物、密钥、测试和大文件。
5. 只有 size/mtime/hash 变化的文件进入 Parser；删除项从 SQLite cascade 清理。
6. PHP/PHTML 由 `token_get_all` 解析结构；Markdown 由 Heading 分块；其他文本按有界行块处理。
7. 同一写事务更新 `indexed_files/indexed_file_contents/chunks/symbols/relations/skills`、unicode FTS、trigram FTS 和稀疏 Feature Hash。
8. `get_edit_bundle` 对全部显式 `paths[]` 先做内容 Hash 定点刷新，再由 `ProjectRetriever` 执行一次 scoped chunk SQL 并在本地排序；无路径时才合并 exact/BM25/trigram/sparse-dot。多符号上游影响以 definitions + 最多三次合并 relation SQL 计算，不再 N 次 `inspectSymbol`。
9. `apply_compact_edit` 先解析全部 project-relative target path，按字典序获取项目内的跨进程文件锁；相同文件的其他会话进入 `flock` 等待队列，多文件计划因固定顺序不会互相死锁。锁内先定点刷新所有目标并记录 submitted/locked/refreshed revision，再让 `EditService::prepare` 封存 Draft，由 `apply` 复核 HEAD/read-set、写 Journal 并原子替换；一次性 Token 不返回模型。
10. 文件整体 Hash 已变化时，只允许唯一文本锚点或带 digest 的 symbol/section/range 安全重定位；目标漂移则再次定点刷新索引，把 `metadata.task` 与旧锚点用于检索最新版区域，并返回 `EDIT_REPLAN_REQUIRED + latest_regions + original_task + retry_contract`。固定验证、必要回滚、最终态定点索引和知识协调都在文件锁内完成，所有成功/失败路径随后立即释放；进程异常退出时内核自动释放 `flock`。
11. `KnowledgeService` 根据 module code/docs digest 和确定性符号事实报告漂移，并生成 `doc/ai` 派生文件的 Edit Plan。
12. `CodexInvoker` 只在显式启用时，以 ephemeral/read-only 子进程返回 `doc-sync.v1`；所有写入仍回到外层 EditService。

完整工具、Edit Plan 和 module skill 契约见 [PROJECT-INTELLIGENCE.md](PROJECT-INTELLIGENCE.md)。

## Data flow

1. Hook 通过 stdin 提交事件。
2. Collector 解析有界 JSON，识别项目，递归脱敏，计算内容 Hash/Dedup Key，写入不可变 Event。
3. `Stop` 关闭 Session，以事件检查点构造 `analyze_session:<session>:php-v1:<checkpoint>` 幂等键并入队；默认 fork 短 worker。
4. `learningd` 每轮也扫描超过 `session_idle_after` 未活动的 active Session。它不强行关闭会话，之后出现新事件时用新检查点增量分析。
5. 分析器检测用户纠正、Agent 撤回和测试/构建/静态检查/浏览器/运行时结果，先创建 Evidence；有明确纠正或成功高信号结果时，隔离 Codex 以 `session-learning.v1` 提取窄范围候选，PHP 再复核 Evidence ID、类型和支持度。
6. `LearningNoveltyService` 同时比较同项目 Experience 与 Project SQLite 的代码、文档、规则、配置和技能：重复合并、已知知识跳过、相关新增标记 enrichment、反向规则记录 Contradiction，其余标记 new。
7. 强用户意图或成功非模型结果满足置信度和状态门禁、且无冲突时可自动转为 `validated`；弱技术主张仍为 Candidate，冲突为 Contested。人工 `mark_experience` 仍用于复核和例外处理。
8. Worker 在分析完成后按经验版本和投影指纹排入 `sync_learning_skills`；隔离 Codex 只分组 ID，PHP 渲染项目级 `MCP学习-*`，并把路径命中的经验子集投影到各模块 `doc/ai/skills`。
9. 项目索引之索引、模块索引/Manifest 与技能定点进入 Project SQLite；`get_edit_bundle` 从已知路径推断模块并批量取回有界正文，`UserPromptSubmit` 通过同一语义路由注入当前 Prompt 的命中技能。
10. MCP 只把成熟、未过期、作用域匹配且无开放冲突的经验包装为 Context Pack。
11. `record_outcome` 追加使用反馈；负面结果进入复审队列。
12. `request_promotion` 只创建 Proposal。目标文件写入、审批、发布和回滚在当前版本外部完成。

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

`promoted` 不能由 MCP/CLI 设置。用户对自身意图、偏好和验收标准的纠正权重高，可在无冲突时自动验证；用户对技术事实的主张仍需测试、构建、静态检查、浏览器或运行时结果验证。自动验证只改变 Experience 成熟度，不写全局政策。

## Retrieval

- 硬过滤：`project_id`、成熟度、有效期、路径/语言/分支/版本作用域、开放冲突。
- 主检索：SQLite FTS5 `unicode61`。
- 中文或无空格文本：FTS 无匹配时，在同项目成熟条目上执行 Unicode bigram 回退，并要求最小相关度。
- 输出预算：按近似 4 字符/token 截断，优先返回更相关、更高置信度条目。
- 详细错误路径和证据只由 `explain_experience` 按需展开。

Project Intelligence 使用另一套混合排序：exact identifier/path → FTS5 BM25/trigram → deterministic sparse vector → symbol relation → freshness/feedback。稀疏向量不依赖网络或模型，也不冒充 Neural Embedding；精确符号不会被语义相似度取代。检索层只负责一次确定路径，内容层再以一次数据库调用返回这批文件，避免 N 个文件产生 N 次工具往返。

## Model paths

默认 `analysis.provider: codex`：隔离的本机 Codex CLI 只接收脱敏、限长的 Session/Event/Evidence Bundle，以 `session-learning.v1` 返回候选；它不能读仓库、运行命令、调用 MCP 或写文件。PHP 验证所有 Evidence ID，并按证据类型确定支持度，随后由知识判重和状态门禁作最终裁决。

`analysis.provider: none` 保留纯 PHP 的用户纠正/撤回分析，不会从“AI 找到并验证的成功做法”中提取新规则。`analysis.provider: openai` 则继续使用 Responses API 双阶段 Structured Outputs；无论哪条模型路径，模型都不能直接验证、晋升或写策略文件。

Prompt 位于 `prompts/`，JSON 契约位于 `schemas/`。学习技能分类是独立的第二条 Codex 路径：它只发送脱敏的已验证 Experience 摘要，并要求 `learning-skills.v1` 分组输出；规则内容不由模型生成，模块归属也由 PHP 从可信作用域路径计算。完全离线时设置 `analysis.provider: none`，并同时关闭 `knowledge.learning_skills.enabled` 与 `knowledge.codex.enabled`。

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
