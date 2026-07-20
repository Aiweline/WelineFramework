# Project Intelligence MCP

## 目标

Project Intelligence MCP 把代码库扫描、符号解析、知识检索、文档定位和机械编辑移到本地 PHP 进程中。Codex 负责理解意图、判断方案和生成小型结构化变更；MCP 负责把意图解析为精确位置、执行文件事务、验证结果并更新索引。

这能减少两类重复 Token：

- 不再把大批候选文件或完整文件发送给模型，由 `get_edit_bundle` 在本地排序并只返回可编辑的命中区域。
- 不再让模型输出完整文件或重复机械替换，由 `edit-plan.v1` 表达“修改哪个符号/章节以及替换内容”。

它不会绕过 Codex。MCP Server 本身不能禁用 Codex 自带的 Shell/Search 工具；个人插件因此在 `UserPromptSubmit` 前移强路由上下文，并由 `PreToolUse` 对已被 Hook 截获的直接读取执行审计和 `permissionDecision: deny`。官方仍说明 `unified_exec` 等路径拦截不完整，所以该层是“支持路径 Host deny + 其余路径模型级强制”，不是完整安全沙箱；索引过期、不可用或上下文不足时仍必须明确报告后才能有界回退。

## 架构

```mermaid
flowchart LR
    C["Codex"] -->|"1. get_edit_bundle(task, paths, symbols)"| G["PHP MCP gateway"]
    H["SessionStart Hook"] -->|"canonical repo + index metadata"| C
    H -->|"enqueue refresh"| S["PHP index sidecar"]
    S -->|"coalesced targeted/incremental"| G
    G --> E["Exact path/symbol index"]
    G --> F["FTS5 unicode/trigram"]
    G --> V["Local sparse vectors"]
    G --> R["Symbol relation overlay"]
    G --> K["Docs / skills / learning"]
    G --> B["Compressed indexed content store"]
    E & F & V & R & K & B --> A["Exact regions + hashes + impact + skill paths"]
    A -->|"bounded structuredContent"| C
    C -->|"2. apply_compact_edit(edit-plan.v1)"| P["Local seal + atomic write"]
    P --> W["Fixed validation"]
    W --> X["Index final state once / safe rollback"]
    X --> D["Drift check / skill routing"]
```

每个项目拥有独立数据库：

```text
~/.learning-mcp/
├── learning.db
├── index-sidecar.sock / index-sidecar.lock / index-sidecar-state.json
├── indexes/{project-hash}/project.sqlite
└── edit-journal/{project-hash}/{edit-id}/
```

`learning.db` 保存会话证据和经验；`project.sqlite` 保存可重建的代码/知识索引。两者分开，避免大型索引 WAL、清理和重建影响学习证据。

## 索引生命周期

首次索引仍然必须读取代码库。区别在于扫描只由本地 MCP 完成一次，后续 AI 请求查询持久数据库：

1. Codex 个人插件自动启动 MCP 与 `SessionStart` Hook。Hook 根据 `cwd` 找到 canonical Git root，立即返回索引 DB/revision/freshness/counts 和路由契约，不返回代码正文。
2. Hook stdout 完成且关闭 Learning/Project SQLite 句柄后，请求投递到自动 PHP sidecar。sidecar 保持 SQLite 热连接，按项目合并 100ms 窗口内的刷新；不可用时退回一次性 fork。
3. 使用 `git ls-files -co --exclude-standard -z` 获取受 Git 管理或未忽略的文件清单，不做请求期目录递归。
4. 应用扩展名、大小、测试、第三方、生成目录、密钥和二进制排除规则。
5. 用 size/mtime/content hash 判断变化，只解析新增或变化文件。
6. PHP/PHTML 使用 `token_get_all` 提取 namespace、class/interface/trait/enum、method/function，以及 extends/implements/use/new/static/method/static/function-call 等保守词法关系。
7. Markdown 按 Heading 切分；普通文本按有界行块切分。
8. 同一事务更新文件、gzip 压缩的完整文本、Chunk、FTS、稀疏向量、符号、关系、技能和知识状态。每个 Chunk 默认只保留权重最高的 24 个稀疏词项；空间成本较高的 trigram 只覆盖 doc/rule/skill。
9. MCP 自己应用代码后立刻定点重索引；`PostToolUse` 跳过只读 Shell/MCP，从 `apply_patch` 提取 Add/Update/Delete/Move 路径，未知写入保守退化为整仓 incremental。默认 60 秒 freshness 校验仅兜底绕过 Hook 的外部编辑。

默认不索引 `.git`、`.gitnexus`、`.codex/code-intelligence`、`vendor`、`node_modules`、`generated`、`var`、静态产物、`view/tpl`、minified/source map、密钥文件和测试目录。测试只有在用户明确要求测试任务并调整配置后才进入索引。

调用方明确传入的 `paths[]` 是有界授权范围：`get_edit_bundle` 每次都对全部显式路径读取当前内容并核对 Hash，在返回区域前立即刷新新增、修改或删除状态，不等待 60 秒兜底周期。若合规文本文件因 `.gitignore` 未被常规 Git 清单发现，MCP 只索引该精确路径、不扫描其父目录，并在 Project SQLite 登记；后续整仓刷新继续保留仍存在且仍通过 `pathAllowed` 的登记路径，文件删除、转为 Git 可见或策略不再允许时自动移除。密钥、二进制、测试、生成目录等门禁仍然生效。

## Codex 路由门禁

- `UserPromptSubmit`：索引为 `current` 时注入一次性 bundle/write 契约，避免 SessionStart 上下文在长任务中被弱化。
- `PreToolUse`：识别 `Bash`、`functions.exec`、`apply_patch` 和文件读取工具中的目录枚举、`rg/grep/find/ls`、`cat/sed/head/tail`、直接编辑等路径；已知 Weline MCP 工具与验证命令直接放行，命中的受支持调用返回 `permissionDecision: deny`。
- 传输门禁：deferred-tool 包装必须转发 `result.structuredContent` 或镜像后的完整 `result.content`，不能只输出短回执并以原生单文件 Read 补偿。
- 合法例外：强制规则/Skill 全文、用户显式文件、未索引或排除材料、验证、MCP 明确 stale/unavailable/insufficient。Shell 使用 `WELINE_MCP_DIRECT_READ_REASON` 声明原因，审计只保存摘要 Hash。
- 模式：`WELINE_MCP_ROUTING_GUARD=enforce|audit|off`，默认 `enforce`。
- Host 边界：Codex 当前允许 `PreToolUse.permissionDecision: deny` 阻断已截获的 Bash、`apply_patch` 和 MCP 调用；官方同时说明 `unified_exec` 拦截仍不完整，WebSearch 和其他非 Shell、非 MCP 工具也不在此边界内。个人插件用 Prompt 强约束覆盖这些剩余路径；管理员 managed requirements 仍是不可关闭策略所需的更强配置层。

## 混合检索

单一向量检索不适合类名、路径和精确符号。候选排序按以下信号组合：

1. 完全相同的 path、FQCN、`Class::method`、模块名和 Skill ID。
2. FTS5 BM25；trigram 为中文子串、专有名词和无空格文本补召回。
3. 依赖无关的 Feature Hash 稀疏向量点积。它是本地词项向量，不是神经 Embedding。
4. extends/implements/import/new/static/method/static/function-call 等词法关系邻近度；动态方法调用以低置信度返回，不冒充类型解析结果。
5. 文件/模块/状态/新鲜度加权。
6. 只基于既存 `query_id + result_id` 的脱敏命中反馈；不保存原始 Prompt，也不把反馈变成政策。

`get_edit_bundle` 返回 `request_id/query_id`、project/index revision、freshness、任务摘要/摘要 Hash、精确 `regions[]`、文件/内容 Hash、行号、符号影响摘要和最多 3 个匹配 Skill 位置。已知 `paths[]` 时先完成 `explicit_paths_refresh`，再一次物化 scoped chunks 并跳过全局 FTS/trigram/sparse；`on_demand_index` 返回本次核对路径、实际变化、耗时和刷新后 revision。多符号影响批量查 definitions/relation graph；`performance` 只返回紧凑阶段计时、cache 命中和 SQL 往返数。

推荐调用协议是“一次精确读 + 一次本地写”：AI 把任务、全部已知路径和符号一次传给 `get_edit_bundle`，直接收到所需区域；模型只返回 Replacement 操作，再调用一次 `apply_compact_edit`。后者保留 Seal/Token/HEAD/Hash 边界，先 Apply 和固定验证，再对最终状态只索引一次；失败默认回滚。兼容工具仍可通过 `WELINE_MCP_TOOL_PROFILE=full` 独立诊断，但不应退化为逐文件读取或多阶段 AI 往返。

## 自动学习技能投影

Learning SQLite 是经验真相源，Project SQLite 是路由、内容和当前项目知识的派生层。Stop/idle 提取先给候选标记 `global_rule`、`project_rule`、`skill_knowledge` 或 `operational_observation`；不应学习的内容返回 `discard`/`no_learning`。全局规则永不自动验证或落盘到全局配置；项目规则只留在项目经验层；技能知识进入 Skill；操作观察必须携带产品 surface 与环境约束。单一 Browser/OS/安全策略现象不能直接成为全局规则。

每条候选还必须具有一个具体正向示例和一个不同的负向示例。PHP 在状态转换前确定性校验分类、surface、环境、作用域、证据和双示例；不完整候选可用于审核，但不得自动验证或投影为 Skill。`LearningNoveltyService` 随后一次读取同项目 Experience，并在 Project SQLite 中有界查询代码、文档、规则、配置和技能；语义重复只合并 Evidence，已索引规则返回 `known_project_knowledge`，新增范围记为 enrichment，反向规则记录开放 Contradiction。

当一个项目中双示例完整的 `skill_knowledge`/`operational_observation` Experience 达到 `validated`/`promotion_eligible`/`promoted`、置信度达标、拥有证据、未过期且无开放冲突时，`sync_learning_skills` 执行：

1. PHP 固化项目、经验版本、知识类别、surface、环境、正反示例和当前投影指纹，生成幂等 Job。
2. 隔离的 `codex exec --ephemeral --sandbox read-only --ignore-user-config` 只对 Experience ID 分组，并返回与来源完全一致的 `knowledge_types`/`surfaces`；它不读仓库、不重写规则、不写文件。
3. PHP 从本地验证记录渲染含 Knowledge Type、Surface、Environment、Positive Example 和 Negative Example 的技能。`knowledge.learning_skills.output_directory` 留空时保持项目级 `dev/ai/skills/MCP学习-*/SKILL.md` 与 Weline 模块投影；配置后把项目级技能、索引与 Manifest 输出到配置目录，并关闭 Weline 专用模块投影。
4. 同一项目锁事务原子更新项目 Manifest、项目 `_index.md` 的模块“索引之索引”，以及每个命中模块的 `doc/ai/skills/_index.md` 与 `MCP-LEARNING-INDEX.json`；只对新旧精确路径执行 `index_project incremental`。类别、surface 或任一示例变化都会改变投影指纹并触发更新。
5. 模块投影默认最多 64 个“技能 × 模块”组合，可通过 `knowledge.learning_skills.max_module_skill_projections` 在 0–256 范围内调整，避免一次学习造成无界文件扇出。

Codex 的初始 Skill 名单在任务开始时有限加载；运行时不依赖重启。`get_edit_bundle` 从 AI 一次提交的全部已知路径推断模块，在总 Token Budget 内批量返回最多 4 个项目级、当前模块及相关命中技能的路径、Hash 和正文；`UserPromptSubmit` 继续使用 `resolve_skill` 从 Project SQLite 注入命中全文。两条通道都不扫描技能目录。

## 使用回执与汇报前缀

MCP 网关为每个成功或失败的 `tools/call` 结果强制覆盖写入 `_weline_mcp`，项目文件、索引内容和工具返回数据不能注入或替换这段服务端元数据。回执包含 `used=true`、Server/版本/工具、`called_at`、随机 `receipt_id`、调用原始结果的 SHA-256 摘要、`is_error`、`response_prefix="Weline："` 和本轮汇报契约。

AI 只有在本轮实际收到该回执后，才应将后续用户可见进度和最终汇报以 `Weline：` 开头。SessionStart 只预告规则，不是调用证明；`initialize`、工具清单和 CLI 直调也不产生回执。该机制提供可观察性，不能替代 Host 的工具审计、审批与授权边界。

## 当前仓库性能基线

2026-07-14 在 `/Users/weline/Project/Official/框架` 的一次完整构建记录如下：

| 指标 | 实测 |
|---|---:|
| Git 可见文件 | 11,378 |
| 进入索引文件 | 8,833 |
| Chunk / Symbol / Relation | 78,834 / 37,808 / 274,811 |
| 完整构建 | 约 93.8 秒 |
| 捕获时 SQLite 文件 | 862,277,632 bytes，约 822 MiB |
| 无变化增量检查 | 内部 486ms，CLI 墙钟约 0.72 秒 |
| 默认 MCP 工具面 | 25 → 5；`tools/list` 21,827 → 5,242 bytes |
| 初始化 instructions | 2,659 → 676 bytes |
| 同任务工具响应 | 24,826 → 5,593 bytes；兼容文本 260 bytes |
| 精确上下文 | 5 个区域、361 个估算内容 Token |
| 本地影响分析 | 11 个上游符号、2 个文件、MEDIUM |
| 既有索引完整内容补齐 | 剩余 6,266 个文件约 7.44 秒 |
| 7 文件批量读取 | 单条 SQLite 查询，99,128 字符，全部未截断 |
| 已知路径 bundle 热中位 | 222.730ms → 74.455ms，约 2.99× |
| 索引新鲜的新 PHP 进程 bundle | 端到端 121.41ms；内部 72.755ms |
| 批量影响 SQL | 8 个目标 3–4 次 SQL，不再逐符号往返 |
| Sidecar 突发合并 | 21 个定点请求 → 1 个 batch |

这些数字只用于回归和容量规划，不是 SLA。首个冷查询需要打开大型 SQLite 索引并预热页缓存，实测明显慢于同一 STDIO MCP 进程内的后续查询；因此生产接入应复用 MCP 进程，而不是为每个检索启动一次 CLI。

## MCP 工具

默认 AI 工具面只有：

- `get_edit_bundle`：唯一常规读取入口；返回精确区域、Hash、影响、文档和 Skill 位置。`structuredContent` 是权威结构，同时在文本 `content` 中镜像同一份有界 bundle，兼容只转发内容块的 Codex deferred-tool 包装。
- `apply_compact_edit`：唯一常规写入口；本地 Seal、Apply、Validate、Reindex，验证失败默认回滚。
- `get_edit_status`：恢复/诊断既有事务。
- `rollback_edit`：显式恢复仍满足 postimage guard 的事务。
- `health`：运行状态与能力诊断。

两个响应通道来自同一次本地索引查询，不是第二次读取，也不应在 UI 中被解释为逐文件读取。调用方只需向模型转发其中一份完整 bundle；不得只保留短回执后再调用原生 Read 补偿。

全部细粒度索引、文档、学习和两阶段编辑工具仍保留在 PHP 兼容层；只有显式设置 `WELINE_MCP_TOOL_PROFILE=full` 的独立 Server 才会在 `tools/list` 中公开它们。Codex 个人插件固定 allow-list 为上述 5 个工具。

## Edit Plan

模型只提交 Draft；本地路径、Hash、Git HEAD、符号范围和最终内容由 MCP 解析并封存。`get_edit_bundle` 会自动对命中区域中的符号执行有界上游影响分析，因此普通任务不需要先做第二次符号查询：

```json
{
  "schema_version": "edit-plan.v1",
  "project_revision": 42,
  "base_commit": "0123456789abcdef",
  "metadata": {
    "task": "保留本轮原始需求，供冲突后按最新版区域重新规划"
  },
  "operations": [
    {
      "op_id": "op-1",
      "kind": "replace_symbol",
      "symbol_uid": "sym-0123456789abcdef",
      "expected_digest": "sha256:...",
      "replacement": "public function get(string $key): mixed\n{\n    ...\n}"
    }
  ],
  "validation_profile": "weline_safe"
}
```

v1 只允许：

- `replace_text`：旧文本必须在目标文件中唯一命中。
- `replace_range`：行范围必须与 expected digest 一致。
- `replace_symbol`、`insert_before_symbol`、`insert_after_symbol`：由索引符号 UID/范围和 body hash 定位。
- `replace_document_section`：由 Markdown heading 和 section hash 定位。
- `create_file`：父目录和扩展名必须在允许范围内，目标不能已存在。

一个 `edit-plan.v1` 可以携带最多 50 个 Replacement，并覆盖多个文件。同一路径的非重叠操作在同一 preimage 上按 byte range 倒序合并，只产生一个 snapshot、一个临时文件和一次目标 `rename`；重叠或共享边界含义不明确时拒绝整个 Plan。不同路径先各自生成 guarded postimage；PHP CLI 有 PCNTL 且文件数大于 1 时，最多 4 个子进程并行写入同目录 staging 文件，父进程逐个复核 Hash 后按路径顺序提交。无 PCNTL、Windows 或单文件自动退回串行 staging，锁、Journal、验证、回滚和最终索引语义不变。

`get_edit_status.files[]` 返回每个文件的 `operation_count`、`stage_strategy`、`stage_workers` 和 `stage_fork_fallbacks`；`apply_pipeline` 汇总文件数、操作数、并行策略和确定性提交方式。并发只发生在不同目标的临时文件准备阶段，永不让两个 worker 同时写同一目标路径。

`apply_compact_edit` 先从 Draft 解析全部 project-relative path，去重并按字典序获取 `~/.learning-mcp/edit-locks/{project-hash}/{path-hash}.lock` 的进程级排他 `flock`。同文件调用在内核等待队列中串行；多文件计划使用同一固定顺序，不形成循环等待。锁从本地 Seal 前一直覆盖 Apply、固定验证、必要回滚、最终态定点索引和知识协调，所有返回或异常路径都在响应前释放；MCP 进程崩溃时操作系统释放所有权，持久 `.lock` 文件本身不表示仍被占用。

拿到锁后，紧凑入口先对全部 target path 执行内容 Hash 定点刷新，把 submitted/locked/refreshed revision 记入审计元数据，再按刷新后的 revision 解析目标。Whole-file Hash 已变化时，不直接套用旧 offset：无 `occurrence` 的 `replace_text` 必须在最新版中唯一命中；`replace_range` 必须带匹配当前切片的 `expected_digest`；symbol/document section 继续由当前 UID/heading 与 body/section digest 保护。满足这些条件的非重叠计划可在最新版上安全重定位，封存后的 read-set 改用最新版文件 Hash；响应中的 `target_refresh` 可审计锁内核对结果。

若文本锚点消失或变得歧义、range digest 失效、symbol/section 被改名或删除、create target 已存在，紧凑入口不写旧计划。它在仍持有文件锁时再次定点刷新相关索引，并把 `plan.metadata.task`（兼容 `requirement/objective`）与旧锚点一起交给同一 `ProjectRetriever::getEditBundle` 路径获取最新版有界区域。随后返回 `EDIT_REPLAN_REQUIRED`：`details.latest_regions`、`original_task`、最新 revision、原始 cause、刷新回执和 `retry_contract` 明确要求 AI 丢弃旧 operations，按原始需求生成全新的 `edit-plan.v1`；不得原样重试或局部修补旧计划。

应用阶段仍获取项目锁保护事务状态，复核 Project/HEAD/read-set hash，把 preimage 写入 `0700/0600` journal，再在同目录写临时文件并 rename。紧凑入口允许准备后 revision 仅向前推进，因为目标文件锁与 preimage Hash 仍会复核实际 read-set；HEAD 变化或目标文件变化继续阻断。任一文件失败则逆序恢复。Rollback 只有在当前文件仍等于 postimage hash 时才能执行，避免覆盖应用后的新修改。

验证器只有固定 Profile：PHP lint、JSON parse、Git diff check 及其安全组合；不会接收任意 Shell 命令。

## 模块文档与技能

权威事实仍在源代码和模块 `doc/`，派生技能按用途分层：

```text
app/code/{Vendor}/{Module}/doc/
├── README.md
├── AI-INDEX.md
└── ai/
    ├── INDEX.json
    └── skills/
        ├── _index.md
        ├── MCP-LEARNING-INDEX.json
        ├── MCP学习-*/SKILL.md
        └── {locator-slug}/
            ├── SKILL.md
            └── .skill-meta.json
```

`doc/ai/INDEX.json` 与 `doc/ai/skills` 是可重建派生缓存。自动生成器遵守以下边界：

- Locator Skill 只覆盖带 `<!-- weline:mcp-skill:auto-generated -->` Marker 的文件；学习 Skill 只覆盖带 `<!-- weline:mcp-learning-skill:auto-generated -->` Marker 的文件。
- 通用 `get_edit_bundle`/`resolve_skill` 只把项目 Skill 和证据门禁后的模块学习 Skill 作为 actionable；Locator Skill 继续由模块知识链结合 `.skill-meta.json` 与 drift state 返回，不能仅凭 Marker 绕过 freshness。
- 模块学习 `_index.md` 和 `MCP-LEARNING-INDEX.json` 有独立 Marker；项目 `dev/ai/skills/_index.md` 保存所有命中模块的索引入口，因此它是索引之索引。
- 手写 Skill 或手写同名索引冲突时停止，不把 Candidate、开放冲突或低置信度经验写入模块。
- Locator Skill 只陈述确定性的模块路径、来源 Hash 和检索流程；模块学习 Skill 只包含 `scope.paths` 直接落入当前模块的已验证经验子集。
- 源文档或关联代码 Fingerprint 变化后，Locator Skill 立即变为 stale；学习规则若与当前代码、文档、用户意图或运行时证据冲突，则停止应用并回到经验复审。
- 模块 Skill 不复制到 `AGENTS.md`，也不会自动变成启动期 `$SkillName`。`get_edit_bundle`/`resolve_skill` 直接从 Project SQLite 返回项目级与模块级匹配路径和有界正文。
- `sync_learning_skills` 写完文件后同步执行精确路径重索引，并以批量 SQL 反查 `indexed_files`、`indexed_file_contents` 和 `skills`：revision、文件/内容 Hash、actionable 状态、source Hash、已删除路径必须全部一致。闭环回执缺失或不一致时 Job 不得进入 `completed`，由现有 lease/retry 机制重试。
- `project_rule` 等不生成 Skill 的已验证知识保留在 Learning SQLite；`get_edit_bundle.validated_learning` 直接查询该库。生成 Skill 的知识走 Project SQLite 投影通道，两者在 bundle 内合并，避免重复文件与第二套事实来源。
- 显式 `sync_module_knowledge mode=apply confirm=true` 仍只管理 Locator Skill；`sync_learning_skills` 在后台项目锁中管理学习 Skill，两条生成链互不覆盖。

漂移检测使用确定性关系：公开 API/Attribute、`#[Col]`/`#[Index]`、配置键、Route/Controller、Event/Hook、CLI signature/help 和模块结构。向量只负责找候选文档或技能，不能单独宣布文档过期或经验有效。

## 调用 Codex 更新文档

标准 MCP 不能回调“当前 Codex 会话”。启用 `knowledge.codex.enabled` 后，MCP 可启动独立 `codex exec` Planner：

- `--ephemeral`
- `--sandbox read-only`
- `approval_policy=never`
- 严格 `doc-sync.v1` Output Schema
- 只从 stdin 接收 MCP 从持久索引取出的精确文档章节、有界代码片段、位置、Hash 和确定性事实，不获得仓库读取权限
- `WELINE_MCP_CODEX_DEPTH=1` 防递归

Planner 只能返回结构化文档操作，不能写工作区。结果仍需经过 `prepare_edit → apply_edit`。该能力默认关闭，避免隐式模型费用和未经同意的数据外发。

## 与 Cursor 类架构的对应关系

| Cursor 类能力 | 本实现 |
|---|---|
| Repository indexing | Git 清单 + Hash 增量 Project SQLite |
| Semantic code search | Exact/FTS/trigram/sparse-vector Hybrid |
| Symbol/reference context | PHP token symbol/relation overlay |
| Context packing | `get_edit_bundle` exact-region Token Budget |
| Batch context materialization | known-path one-query materialization + per-file region deduplication |
| Fast local apply | `apply_compact_edit` local Seal/Apply/Validate/Reindex/Rollback |
| Docs awareness | Module doc heading index + drift state |
| Rule/skill routing | Project + inferred-module Skill indexes and bounded batch content |
| Learning closure | Learning SQLite source → marker-owned projection → targeted Project SQLite reindex → batched revision/hash/skill verification → audited Job completion |
| Edit-triggered refresh | validation-first single final-state reindex + mutation-filtered PostToolUse |
| Project-aware startup | Personal plugin + SessionStart canonical-root routing + automatic PHP sidecar |

这不是对 Cursor 私有实现的复制，也不声称本地稀疏向量等于神经 Embedding。速度收益主要来自持久预索引、不重复扫描、精确候选过滤和本地机械编辑。
