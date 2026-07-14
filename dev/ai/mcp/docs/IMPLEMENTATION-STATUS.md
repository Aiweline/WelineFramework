# Implementation status

本文件把蓝图目标拆成当前可运行能力与明确延期项，避免把设计愿景误认为已上线功能。

## Complete

- 无 Composer 依赖的 PHP STDIO MCP Server，17 个 Project Intelligence 工具和 8 个 Learning 工具。
- Codex 生命周期 Hook Collector。
- 个人 Codex 插件启动层：自动注册 PHP MCP 与 7 个 Hook，并使用 Codex 持久化 Hook Hash trust，无需手编 TOML/Hook JSON。
- `SessionStart` 自动解析当前 Git 项目、注入有界路由元数据，并在响应后后台首建/增量刷新该项目独立索引。
- SQLite/WAL/FTS5 Schema、事务 Migration 和同步 Trigger。
- Project/Session/Event/Evidence/Experience/Version/Feedback/Contradiction/Proposal/Job/Audit 数据链。
- 确定性纠正/失败/成功信号分析。
- 可选 OpenAI Responses API 双阶段 Structured Outputs 分析。
- Secret Redaction、Injection Quarantine、Project Isolation、Evidence Resolver。
- Candidate/Validated/Promotion-eligible 状态门禁。
- 幂等 Event、增量 Session Checkpoint、Job、Feedback 和 Proposal。
- Stop 后异步短 worker，以及无 Stop 活动会话的空闲增量分析。
- `learningd once/drain/run` 和显式 macOS LaunchAgent 管理。
- CLI 审核、Evidence 录入/挂接、Outcome、删除、Proposal 列表与 Doctor。
- 中文无空格文本的受限本地召回回退。
- 每项目独立 SQLite/WAL Code/Doc/Skill Index，Git file list 发现、Hash 增量、删除清理和定点刷新。
- 与 Chunk/向量同库、同事务更新的 gzip 完整文本库，以及 `get_indexed_files` 最多 50 路径、单 SQLite 查询、保持输入顺序的批量内容读取。
- Unicode61 + trigram FTS、确定性 CJK/代码词项稀疏 Feature Hash，以及 exact/module/path/symbol 混合排序。
- PHP/PHTML `token_get_all` 符号与保守 extends/implements/use/new/static/method/static/function-call 关系覆盖层、Markdown Heading Chunk、普通文本有界 Chunk。
- `resolve_task_context` Token Budget Context Pack，返回 index DB/revision/freshness/path/hash/line 和 validated skill/learning。
- Module docs/Skill 路由、deterministic drift facts、`doc/ai/INDEX.json` 与 marker-owned locator Skill Draft。
- `edit-plan.v1`、Prepare/Apply 一次性 Token、HEAD/revision/read-set、路径/Hash/预算门禁、Journal/atomic rename/rollback guard。
- 固定 PHP/JSON/diff validation profile，以及 Apply/Rollback 后的同步路径重索引。
- 可选 `codex exec` read-only/ephemeral `doc-sync.v1` Planner；默认禁用。

## Automatic, but bounded

- Stop 后可自动处理已入队 Session。
- 调度器可自动扫描空闲 Session，但必须由用户先显式执行 `scheduler install`，或自行运行 `learningd run`。
- 自动分析只能创建/合并 `candidate` 和 Evidence，不能把经验变成可执行政策。
- 负面 Outcome 只能创建复审 Job，不能自动降级或改写规则。
- 查询超过 `index.refresh_interval` 时自动执行增量 freshness refresh；MCP 写入后立即定点重索引；Codex `PostToolUse` 对其他工具产生的文件变化安排非阻断增量刷新。
- 每次 Codex `SessionStart` 后台增量校验当前 canonical Git root；项目索引使用 remote/root fingerprint 自动分库。`pcntl` 不可用时明确退化为首次索引读的同步刷新。
- 显式 `sync_module_knowledge mode=apply confirm=true` 可自动生成/更新 marker-owned module locator skill，但仍是一次受审批的 destructive transaction，不是后台静默写入。

## Intentionally not automatic

- MCP 进程在未被 Host 发现前自我注册；这个启动悖论由已安装的 Codex 插件解决。
- 绕过 Codex Hook trust review；Hook 定义变更后必须重新信任新 Hash。
- 写入或修改 `AGENTS.md`、Prompt、全局 Skill、测试、CI 或安全策略。
- 覆盖手写模块 Skill；自动生成只处理 `doc/ai` marker-owned 派生文件。
- 把 Candidate 当作可执行规则。
- 依据单次模型输出验证技术结论。
- 直接设置 `promoted`。
- 跨项目传播规则。
- 在未执行 `scheduler install` 时写 LaunchAgent。
- 修改模型权重或自动微调。

## Deferred

- Streamable HTTP、OAuth、多租户与远程数据库。
- Neural Embedding/ANN、完整跨语言 AST/LSP 图、持久 GitNexus sidecar 和完整 Decision Graph。当前已实现依赖无关的 Sparse Vector 与 PHP Relation Overlay。
- 通用 Artifact Blob Store、Diff/Test/CI Attestation 解析器。符合索引规则的代码、文档和 Skill 完整文本已进入项目内容库；任意会话 Artifact Blob 仍未实现。
- Learning Event/审计数据的通用自动保留期清理、Portable Export/Import 和数据加密。Project Intelligence 查询日志已只存 Prompt 指纹，并按 `privacy.raw_retention` 自动级联清理。
- Promotion 审批 UI；Promotion Proposal 仍不会自动进入仓库。Project Edit Plan/Apply/Rollback 已实现，但不等价于政策晋升。
- Dashboard、Evaluation Corpus、离线质量指标和 A/B 召回评估。
- 跨 Agent/跨项目策略合并、组织级规则与冲突仲裁。
- 模型微调或从经验库生成训练数据。
- Linux systemd、Windows Task Scheduler 的内置安装器。

这些延期项需要独立威胁模型、Migration、兼容策略和验收，不属于当前版本的隐含能力。
