# Security and privacy

## Trust model

Learning MCP 把以下内容视为不可信数据，而不是指令：

- Hook payload、用户消息、Tool 输入与输出；
- Transcript/Artifact 定位信息；
- 历史错误路径；
- 模型生成的 Candidate/Assessment；
- 调用方提交的 Promotion Suggestion。
- 代码、模块文档、生成 Skill、索引 Chunk 和模型返回的 Edit/Doc Plan。

Server `instructions`、`explain_experience` 和负面路径输出都会提示客户端：不得执行历史内容中的命令。

## Secret handling

- Collector 在写库前递归脱敏 Bearer Token、常见 API/Git Token、AWS Key、密码字段和私钥块。
- Store 再次脱敏 Evidence Claim/Locator、Feedback Comment、Promotion Proposal 和 Audit Details，覆盖非 Hook 写入口。
- `transcript_path` 不读取、不复制，只记录是否存在。
- API Key 只从 `analysis.api_key_env` 指定的环境变量读取。
- SQLite 数据目录为 `0700`，数据库、sidecar socket/lock/state 为 `0600`。
- LaunchAgent plist 不包含 API Key。

启发式正则可能漏掉未知格式 Secret，也可能误报。不要把生产密钥、客户数据或未授权代码发送到可选模型分析器。

## Prompt injection

Collector 检测“忽略既有指令、泄露系统提示、上传密钥、绕过策略”等模式并把事件标为 `quarantined`。确定性和模型分析路径都会跳过这些事件。模型输入使用显式 `<untrusted_...>` 数据边界，Prompt 禁止遵循 Episode 中的指令。

隔离是降低误学习风险的启发式控制，不是通用恶意内容检测器。

SessionStart 自动上下文不会注入源码、模块文档、索引 Chunk 或历史会话正文，只注入 canonical repository、Project ID、索引数据库位置、修订号、freshness、计数和工具路由约定。路径通过 JSON 编码，文本显式标为不可信项目元数据；它只帮助 Host 选中正确项目和检索工具，不能替代 Server instructions、用户审批或本地写入校验。

## Usage receipt boundary

每个 MCP `tools/call` 的 `structuredContent` 由网关在工具结果生成后写入 `_weline_mcp`。即使不可信工具结果包含同名字段，网关也会覆盖它；回执的 `result_digest` 对写入回执前的规范 JSON 计算。兼容 `content` 只保留状态、工具、同一 `receipt_id` 和有界错误/计数摘要，避免把完整结果复制两次。客户端只有在本轮实际收到 `used=true` 和 `receipt_id` 后，才应使用 `Weline：` 汇报前缀，不能把 SessionStart、Server 注册或 `initialize` 当成使用证明。

回执和前缀是本地单用户工作站上的可见调用证据，不是密码学签名、远程证明、身份认证或授权令牌。能控制 MCP 进程或客户端输出的本地操作者仍可伪造它；安全决策必须继续依赖 Host 工具日志、审批策略、sealed edit Token、Hash 和路径边界。

## Plugin and Hook trust

- Personal plugin 是 Codex 启动入口；MCP 运行时不会改写 Host 配置、注册自己或修改插件启用状态。
- 当前插件的 7 条 command Hook 由 Codex 按命令内容的精确 Hash 建立信任。命令、参数或路径变化后必须重新人工审阅并接受新的 Hash。
- 安装流程不使用 `--dangerously-bypass-hook-trust`，也不通过 managed policy 绕过用户信任边界。
- Hook 失败按 Host 的非阻断语义记录错误，不会放宽 MCP 的项目隔离、sealed edit、Hash、Token 或审批校验。

## Evidence integrity

- `record_outcome` 只能引用已经存在且属于同一项目的 Evidence ID。
- 用户 Comment 是反馈，不自动升级为 Evidence。
- 技术经验进入 `validated` 需要已验证的非模型证据和可观察结果证据。
- 模型引用不存在的 Evidence ID 时，Candidate 被拒绝并写审计日志。
- Experience 每次状态/内容更新都会递增版本并追加快照；状态转换受显式状态机和证据门禁约束。
- Job、Event、Feedback 和 Proposal 使用唯一键保证重试幂等。

## Project isolation

所有检索和写操作都校验 `project_id`。同时提供 Repository 与 Project ID 时，两者必须匹配。v1 不提供跨项目自动召回，也不开放网络 MCP 传输。

Project Index 进一步使用 canonical Git root 和独立数据库目录。读取路径经过 normalization、realpath containment 和 symlink escape 检查；写入路径只接受 repository-relative path，并重新逐组件检查。索引中的行号和路径只是定位提示，写入权威始终是 apply 时重新读取的文件 Hash。

## Indexing boundaries

- 文件发现使用 Git file list，不在检索请求中递归遍历目录。
- 默认排除 `.git`、索引数据库、第三方依赖、生成目录、静态构建产物、`view/tpl`、minified/source map、测试、大文件、二进制和常见密钥文件。
- 初次索引必须读取符合规则的本地文件；不能把“AI 不扫描”误写成“系统永不扫描”。
- 符合规则的代码、文档和 Skill 完整文本会 gzip 压缩后保存在对应项目的 `project.sqlite`。`get_edit_bundle` 从 Chunk/内容/符号表一次组装精确区域，不逐个读取工作区；兼容 `get_indexed_files` 仍可在 full profile 中批量读取，因此项目数据库按源代码同等敏感度保护。
- SQLite 目录/数据库/Journals 分别保护为 `0700/0600`。
- Index sidecar 只监听私有 Unix socket，不监听 TCP。请求仅允许协议版本、canonical repository、请求 ID 和最多 200 个相对路径；拒绝绝对路径、`..`、NUL、超限报文和 Symlink socket。它不接受命令或源码。
- Collector 只有在沙箱 Node 编排不含本地工具、嵌套命令完全匹配严格只读白名单，或终端调用只是空轮询时才跳过刷新；动态命令、非空终端输入和未知嵌套工具一律退化为整仓增量，不能借分类器绕过索引一致性兜底。
- Chunk 内容和模块文档可能包含 Prompt Injection；检索结果始终标为项目数据，不可改变 Server instructions 或审批边界。

## Sealed edit boundary

`prepare_edit` 与 `apply_edit` 是两个不同权限阶段：

1. Draft 只允许枚举的 symbol/text/range/document/create 操作，不接受命令、正则脚本或绝对路径。
2. Prepare 解析本地目标，检查 allowed roots/denied paths/symlink/文件与总字节预算、唯一 Anchor、expected digest、HEAD 和 Index revision。
3. Replacement 只保存在服务器 sealed plan；返回一次性随机 Token 的 Hash 进入数据库。
4. Apply 工具带 destructive annotation，调用时只接收 Token 和 plan digest，不能更换 Replacement。
5. Apply 获取项目级 `flock`，复核 read-set，把 preimage 写到私有 Journal，再同目录 temp + rename。
6. Token 在 Apply 后失效；紧凑链路先针对 postimage 运行固定验证，不先索引尚未验证的中间状态。
7. 成功时只定点索引 postimage 一次；验证失败时先依 postimage guard 回滚，再索引 preimage 一次。Rollback 仍不得覆盖用户后续修改。

默认 AI 写入口 `apply_compact_edit` 没有删除这两个阶段：它本身带 destructive annotation，在同一次受审批的本地调用中先 Prepare，再把不返回模型的一次性 Token 交给 Apply。固定验证后才索引最终状态；失败默认走同一 postimage guard 回滚，Hash/HEAD/Revision/Token 门禁均未放宽。

固定 Validation Profile 可以调用 PHP lint、JSON parse 和 Git diff check；任何模型返回的 Shell command 都会被拒绝。

## Module docs and skills

- 文档写入只允许目标模块 `app/code/{Vendor}/{Module}/doc/**`。
- 自动 Skill 只写 `doc/ai/skills/**`，且只覆盖含 `weline:mcp-skill:auto-generated` Marker 的文件。
- 这条 module locator 路径不写手写 Skill、`AGENTS.md`、`.codex/skills`、项目级 `dev/ai/skills`、测试、CI 或安全规则。
- Locator Skill 只包含确定性路径/Hash 时可验证；模型生成的技术内容先进入 draft。Source Hash 变化后 stale，不再作为行动指导。
- 检索反馈只记录 query/result ID 和 outcome，不保存原始 Prompt，也不能提升 Skill/Experience 的信任状态。

Evidence-gated 项目学习技能是唯一个狭义背景写入例外：

- 只考虑已验证、高置信度、有 Evidence、未过期且无开放冲突的同项目 Experience；Candidate 和被隔离的 Injection 永不进入。
- 写边界固定为 marker-owned `dev/ai/skills/MCP学习-*/SKILL.md`、`dev/ai/skills/MCP-LEARNING-INDEX.json` 和 `_index.md` 的单个 marker 区块；路径逃逸、Symlink、非 Marker 覆盖/删除都被拒绝。
- 文件投影在项目锁下快照、原子替换，部分失败回滚；随后只重索引精确新旧路径。
- 它不写 `AGENTS.md`、`.agents/skills`、`.codex/skills`、用户/系统 Skill、Prompt、测试、CI 或安全策略，不跨项目传播。

## Nested Codex planner

`knowledge.codex.enabled` 默认开启，以支持已验证经验分类；`knowledge.auto_doc_sync` 仍默认关闭。子进程固定为 `codex exec --ephemeral --sandbox read-only --ignore-user-config`，approval policy 为 never，MCP Servers 清空，不继承 Shell 环境，Prompt 从 stdin 输入，并设置递归深度环境变量。

文档规划只接受 `doc-sync.v1`，结果仍需 sealed edit 审批。会话学习只接受 `session-learning.v1`：候选数有界，每条候选必须引用服务端允许的 Evidence ID，路径/类别/长度/Injection 模式再次由 PHP 校验；随后 PHP 独立判断证据支持度、知识重复与冲突。学习分类只接受 `learning-skills.v1`：服务端要求每个允许的 Experience ID 恰好分配一次，并二次校验名称、路由描述和触发词。技能正文只由 PHP 从已验证本地记录渲染。

`--ignore-user-config` 不能替代递归保护和本地写权限校验。启用前还必须确认发送给 Codex 的代码/文档片段符合组织数据政策和费用预期。

## Mutation boundaries

| 操作 | 允许的持久化变化 | 明确禁止 |
|---|---|---|
| Hook/worker | 追加 Event/Evidence/Job；创建/合并 Candidate；强用户意图或成功非模型结果经 PHP 门禁后自动设为 `validated`；记录开放冲突 | 依据单次模型输出验证技术结论、把冲突当政策、自动晋升、修改全局规则 |
| `sync_learning_skills` worker | 从已验证经验更新三类 marker-owned 项目技能投影并定点索引 | 写手写/全局 Skill，使用 Candidate，执行模型命令，跨项目传播 |
| `record_outcome` | 追加 Feedback；负面结果可入复审 Job | 创建 Evidence、修改成熟度 |
| `request_promotion` | 创建/复用 Proposal | 修改任何目标文件 |
| `mark_experience` | 审计后的状态转换 | 直接设为 `promoted`、绕过证据门禁 |
| `scheduler install` CLI | 显式写当前用户 LaunchAgent | 隐式安装、保存密钥 |
| Personal plugin install | 用户授权的 marketplace/plugin/cache/config 启用状态和 Hook trust Hash | MCP 运行时改写 Host 配置、绕过 Hook trust |
| `index_project` | 写项目私有派生索引 | 修改源码、跟随越界 Symlink、读取排除文件 |
| `prepare_edit` | 写 sealed plan/Journal metadata | 修改仓库、接受任意命令、在审批后更换内容 |
| `apply_edit` | 经 destructive 审批写允许路径并重索引 | 写 denied path、绕过 Hash/HEAD/Token、部分失败静默成功 |
| `apply_compact_edit` | 经一次 destructive 审批在本地串联 Prepare/Apply/Validate/Reindex/失败回滚 | 暴露一次性 Token、跳过任一旧门禁、接受任意验证命令 |
| `sync_module_knowledge` | 预览或经确认写 marker-owned module doc/ai | 覆盖手写 Skill、把 Candidate 变全局政策 |
| `delete` CLI | 用户显式 `--yes` 后删除 Session/Project | MCP 暴露删除能力 |

Stop 后 fork worker 是已有 Job 的异步处理，不扩大写权限；它仍受同一数据库、项目和证据门禁约束。

## Optional remote model

默认 `analysis.provider: codex`，会话学习提取和已验证经验分类都会通过当前已登录的 Codex CLI 处理脱敏、有界的数据，不需要单独 API Key。子进程虽为 read-only/ephemeral，但模型请求仍可能离开本机；启用前应确认组织数据和费用策略。完全离线时必须设置 `analysis.provider: none`，并同时设置 `knowledge.learning_skills.enabled: false` 和 `knowledge.codex.enabled: false`。

启用 `analysis.provider: openai` 后，脱敏且有界的 Event 内容、项目/Session 指纹、Evidence Claim/Locator 和 Candidate Draft 会发往受信任的 `base_url`。

不会主动发送完整 Transcript、原始 Secret 或数据库文件。PHP 使用 Responses API Structured Outputs，但服务端仍会解析和复核 Evidence ID。启用前应确认组织的数据政策、模型保留策略、模型名和 Base URL 信任边界。

LaunchAgent 不继承交互式 shell 的全部环境。不要把 Key 写进 plist；使用受控的 launchd 环境注入，或在已设置环境变量的前台运行 `learningd run`。

## Deletion and retention

```bash
./bin/learningctl delete session --id SESSION --actor REVIEWER --yes
./bin/learningctl delete project --id PROJECT --actor REVIEWER --yes
```

删除使用外键级联清理数据，并在成功后写独立审计记录。删除 Session 导致 Experience 失去全部来源或证据时，相关成熟经验会转为 `contested`。SQLite 备份仍可能保留历史数据，必须按相同保留政策处置。

Project Intelligence 的 `query_log.query_text` 只保存脱敏查询的 SHA-256 指纹，不保存原始 Prompt；每次新查询都会按 `privacy.raw_retention` 删除过期 Query，并通过外键级联清理其 Result/Feedback。Learning Event 与通用审计数据尚未实现统一的后台保留期清理；强制合规删除仍应使用显式命令或受控删除整个数据目录。

## Known limitations

- Hook 事件不等于完整因果图；系统只学习可观察轨迹，不读取隐藏 Chain-of-Thought。
- Artifact 内容解析、签名验证、CI Attestation、SBOM 与完整 Decision Graph 尚未实现。
- 本地操作者拥有数据库文件权限时，可以绕过 CLI 修改 SQLite。
- 无多租户认证、远程 HTTP、数据库加密或系统密钥链集成。
- PHP Token Relation 是保守的实时覆盖层，不等同于完整 LSP/跨语言 AST 图；低置信度影响必须显式标注。
- 默认稀疏 Feature Hash 不是 Neural Embedding；它避免外部依赖和数据外发，但语义召回能力有限。
- macOS 内置调度器仅支持当前用户 LaunchAgent；其他系统应使用自身进程管理器调用 `learningd drain/run`。

因此当前版本适合单用户、单机、受信任工作站，不应直接暴露为共享网络服务。
