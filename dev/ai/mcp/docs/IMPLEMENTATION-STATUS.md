# Implementation status

本文件把蓝图目标拆成当前可运行能力与明确延期项，避免把设计愿景误认为已上线功能。

## Complete

- 无 Composer 依赖的 PHP STDIO MCP Server；默认只向 AI 公开 5 个紧凑工具，全部 Project Intelligence/Learning 细粒度工具保留为显式 full-profile 兼容层。
- 通用技能投影目录：`knowledge.learning_skills.output_directory` / `LEARNING_MCP_SKILL_OUTPUT_DIR` 可把项目级技能输出到任意配置目录；留空保持 Weline 项目与模块投影。仓库内配置目录继续参与项目索引，仓库外目录交给宿主技能发现。
- `start.sh` 与 `start.bat` 可自动检查/安装 PHP、必需扩展与 Git，创建缺失配置后直接启动 STDIO MCP；安装日志与协议 stdout 隔离。
- Codex 生命周期 Hook Collector。
- 个人 Codex 插件启动层：自动注册 PHP MCP 与 7 个 Hook，并使用 Codex 持久化 Hook Hash trust，无需手编 TOML/Hook JSON。
- `SessionStart` 自动解析当前 Git 项目、注入有界路由元数据，并在响应后后台首建/增量刷新该项目独立索引。
- 每个成功或失败的 MCP `tools/call` 都返回服务端 `_weline_mcp` 使用回执，并要求本轮真实调用后的用户汇报以 `Weline：` 开头；仅启动/初始化不冒充使用。
- SQLite/WAL/FTS5 Schema、事务 Migration 和同步 Trigger。
- Project/Session/Event/Evidence/Experience/Version/Feedback/Contradiction/Proposal/Job/Audit 数据链。
- 确定性纠正/失败/成功信号分析。
- 默认 `session-learning.v1` 隔离 Codex 提取：可从用户纠正或已成功验证的 AI 做法生成有界候选，失败自动降级且不阻断 Stop。
- Experience + Project SQLite 双层知识判断：语义重复合并、已索引知识跳过、相关增量标记 enrichment、反向规则记录开放冲突并隔离。
- 强用户意图或 test/build/lint/browser/runtime/user-confirmation/CI 成功证据的非冲突候选，可经 PHP 状态门禁自动进入 `validated`。
- 可选 OpenAI Responses API 双阶段 Structured Outputs 分析。
- Secret Redaction、Injection Quarantine、Project Isolation、Evidence Resolver。
- Candidate/Validated/Promotion-eligible 状态门禁。
- 幂等 Event、增量 Session Checkpoint、Job、Feedback 和 Proposal。
- Stop 后异步短 worker，以及无 Stop 活动会话的空闲增量分析。
- `learningd once/drain/run` 和显式 macOS LaunchAgent 管理。
- CLI 审核、Evidence 录入/挂接、Outcome、删除、Proposal 列表与 Doctor。
- 中文无空格文本的受限本地召回回退。
- 每项目独立 SQLite/WAL Code/Doc/Skill Index，Git file list 发现、Hash 增量、删除清理和定点刷新。
- 自动 PHP/Unix-socket Index Sidecar：无额外运行时，按项目合并 Hook 刷新、复用 SQLite 连接、15 分钟空闲退出，并保留 one-shot fallback。
- 与 Chunk/向量同库、同事务更新的 gzip 完整文本库，以及 `get_edit_bundle` 一次接收任务/路径/符号、返回去重精确区域的紧凑读取路径。
- Unicode61 + trigram FTS、确定性 CJK/代码词项稀疏 Feature Hash，以及 exact/module/path/symbol 混合排序。
- PHP/PHTML `token_get_all` 符号与保守 extends/implements/use/new/static/method/static/function-call 关系覆盖层、Markdown Heading Chunk、普通文本有界 Chunk。
- `get_edit_bundle` Token Budget Context Pack，已知路径单 SQL 物化、批量三级上游影响、阶段计时/cache 命中，并同时返回 `structuredContent` 权威结构与文本 `content` 的同 bundle 兼容镜像；只转发内容块的 deferred-tool 包装也不会丢失批量正文。
- Module docs/Skill 路由、deterministic drift facts、`doc/ai/INDEX.json` 与 marker-owned locator Skill Draft。
- Evidence-gated `sync_learning_skills`：隔离 Codex 只对已验证 Experience ID 分组；PHP 确定性生成项目级技能，并按 `scope.paths` 把相关经验子集投影到各模块 `doc/ai/skills`。
- 项目 `_index.md` 的模块索引之索引、模块 `_index.md`/Manifest、项目锁、原子写入/回滚、手写冲突拒绝、投影指纹自愈与精确路径重索引。
- `get_edit_bundle` 从全部已知路径推断模块并在共享 Token Budget 内一次返回最多 4 个命中技能正文；`UserPromptSubmit` 继续注入索引命中内容，无需任务重启或请求期目录扫描。
- `edit-plan.v1`、`apply_compact_edit` 单调用本地 Prepare/Apply/Validate/Reindex/失败回滚、一次性 Token、HEAD/revision/read-set、路径/Hash/预算门禁、Journal/atomic rename/rollback guard。
- 固定 PHP/JSON/diff validation profile，验证优先的单次最终状态重索引，以及可恢复 `index_pending` 状态/阶段计时。
- 按请求使用的 `codex exec` read-only/ephemeral `doc-sync.v1` Planner；Codex 子进程可用，但文档自动同步默认禁用。

## Automatic, but bounded

- Stop 后可自动处理已入队 Session。
- 调度器可自动扫描空闲 Session，但必须由用户先显式执行 `scheduler install`，或自行运行 `learningd run`。
- 自动分析会先创建/合并 Evidence 与 Experience；只有强证据、明确项目作用域且无冲突时才自动转为 `validated`。弱证据仍为 Candidate，冲突为 Contested。
- `SessionStart`、`UserPromptSubmit` 和 worker 自动排队已验证经验快照；只有通过状态/置信度/Evidence/有效期/冲突门禁的项目经验才会写入 MCP 自有项目/模块技能投影。
- 分类在后台 worker 调用隔离 Codex；模块归属由 PHP 从经验路径确定。Prompt Hook 与 `get_edit_bundle` 只读 Project SQLite 并批量注入已有技能，失败非阻断。
- 负面 Outcome 只能创建复审 Job，不能自动降级或改写规则。
- MCP 写入后立即定点重索引；Codex `PostToolUse` 对直接或沙箱 Node 编排内的 `apply_patch` 提取精确路径，跳过纯 Browser、严格只读和自索引工具，动态命令/终端输入/未知写入退化为整仓增量。`index.refresh_interval` 默认 60 秒，仅作为外部编辑兜底。
- 每次 Codex `SessionStart` 后台增量校验当前 canonical Git root；项目索引使用 remote/root fingerprint 自动分库。`pcntl` 不可用时明确退化为首次索引读的同步刷新。
- 显式 `sync_module_knowledge mode=apply confirm=true` 可自动生成/更新 marker-owned module locator skill，但仍是一次受审批的 destructive transaction，不是后台静默写入。

## Intentionally not automatic

- MCP 进程在未被 Host 发现前自我注册；这个启动悖论由已安装的 Codex 插件解决。
- 绕过 Codex Hook trust review；Hook 定义变更后必须重新信任新 Hash。
- 写入或修改 `AGENTS.md`、Prompt、手写/用户/系统 Skill、`.agents/skills`、`.codex/skills`、测试、CI 或安全策略。唯一例外是上述严格 marker-owned 的项目学习投影。
- 覆盖手写模块 Skill 或手写 `doc/ai/skills/_index.md`；自动生成只处理各自 Marker 管理的派生文件。
- 把 Candidate 当作可执行规则。
- 依据单次模型输出、普通命令成功或未验证用户技术主张来验证技术结论。
- 直接设置 `promoted`。
- 跨项目传播规则。
- 在未执行 `scheduler install` 时写 LaunchAgent。
- 修改模型权重或自动微调。

## Deferred

- Streamable HTTP、OAuth、多租户与远程数据库。
- Neural Embedding/ANN、完整跨语言 AST/LSP 图、外部 GitNexus 常驻图服务和完整 Decision Graph。当前已实现依赖无关的 Sparse Vector、PHP Relation Overlay 和自有索引刷新 sidecar。
- 通用 Artifact Blob Store、Diff/Test/CI Attestation 解析器。符合索引规则的代码、文档和 Skill 完整文本已进入项目内容库；任意会话 Artifact Blob 仍未实现。
- Learning Event/审计数据的通用自动保留期清理、Portable Export/Import 和数据加密。Project Intelligence 查询日志已只存 Prompt 指纹，并按 `privacy.raw_retention` 自动级联清理。
- Promotion 审批 UI；Promotion Proposal 仍不会自动进入仓库。Project Edit Plan/Apply/Rollback 已实现，但不等价于政策晋升。
- Dashboard、Evaluation Corpus、离线质量指标和 A/B 召回评估。
- 跨 Agent/跨项目策略合并、组织级规则与冲突仲裁。
- 模型微调或从经验库生成训练数据。
- Linux systemd、Windows Task Scheduler 的内置安装器。

这些延期项需要独立威胁模型、Migration、兼容策略和验收，不属于当前版本的隐含能力。
