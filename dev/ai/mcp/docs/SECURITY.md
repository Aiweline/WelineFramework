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
- SQLite 数据目录为 `0700`，数据库为 `0600`。
- LaunchAgent plist 不包含 API Key。

启发式正则可能漏掉未知格式 Secret，也可能误报。不要把生产密钥、客户数据或未授权代码发送到可选模型分析器。

## Prompt injection

Collector 检测“忽略既有指令、泄露系统提示、上传密钥、绕过策略”等模式并把事件标为 `quarantined`。确定性和模型分析路径都会跳过这些事件。模型输入使用显式 `<untrusted_...>` 数据边界，Prompt 禁止遵循 Episode 中的指令。

隔离是降低误学习风险的启发式控制，不是通用恶意内容检测器。

SessionStart 自动上下文不会注入源码、模块文档、索引 Chunk 或历史会话正文，只注入 canonical repository、Project ID、索引数据库位置、修订号、freshness、计数和工具路由约定。路径通过 JSON 编码，文本显式标为不可信项目元数据；它只帮助 Host 选中正确项目和检索工具，不能替代 Server instructions、用户审批或本地写入校验。

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
- 符合规则的代码、文档和 Skill 完整文本会 gzip 压缩后保存在对应项目的 `project.sqlite`。`get_indexed_files` 从该内容表单次查询，不逐个读取工作区；因此项目数据库按源代码同等敏感度保护。
- SQLite 目录/数据库/Journals 分别保护为 `0700/0600`。
- Chunk 内容和模块文档可能包含 Prompt Injection；检索结果始终标为项目数据，不可改变 Server instructions 或审批边界。

## Sealed edit boundary

`prepare_edit` 与 `apply_edit` 是两个不同权限阶段：

1. Draft 只允许枚举的 symbol/text/range/document/create 操作，不接受命令、正则脚本或绝对路径。
2. Prepare 解析本地目标，检查 allowed roots/denied paths/symlink/文件与总字节预算、唯一 Anchor、expected digest、HEAD 和 Index revision。
3. Replacement 只保存在服务器 sealed plan；返回一次性随机 Token 的 Hash 进入数据库。
4. Apply 工具带 destructive annotation，调用时只接收 Token 和 plan digest，不能更换 Replacement。
5. Apply 获取项目级 `flock`，复核 read-set，把 preimage 写到私有 Journal，再同目录 temp + rename。
6. 失败按逆序恢复；成功后 Token 失效并定点重索引。
7. Rollback 要求当前文件仍匹配 postimage hash，防止覆盖用户后续修改。

固定 Validation Profile 可以调用 PHP lint、JSON parse 和 Git diff check；任何模型返回的 Shell command 都会被拒绝。

## Module docs and skills

- 文档写入只允许目标模块 `app/code/{Vendor}/{Module}/doc/**`。
- 自动 Skill 只写 `doc/ai/skills/**`，且只覆盖含 `weline:mcp-skill:auto-generated` Marker 的文件。
- 手写 Skill、`AGENTS.md`、`.codex/skills`、全局 `dev/ai/skills`、测试、CI 和安全规则不会由此路径写入。
- Locator Skill 只包含确定性路径/Hash 时可验证；模型生成的技术内容先进入 draft。Source Hash 变化后 stale，不再作为行动指导。
- 检索反馈只记录 query/result ID 和 outcome，不保存原始 Prompt，也不能提升 Skill/Experience 的信任状态。

## Nested Codex planner

`knowledge.codex.enabled` 默认关闭。启用后子进程必须是 `codex exec --ephemeral --sandbox read-only`，approval policy 为 never，Prompt 从 stdin 输入，输出受 `doc-sync.v1` Schema 约束，并设置递归深度环境变量。它不能调用外层写工具、不能直接修改工作区、不能输出任意命令；结果仍需走 sealed edit 审批。

`--ignore-user-config` 不能替代递归保护和本地写权限校验。启用前还必须确认发送给 Codex 的代码/文档片段符合组织数据政策和费用预期。

## Mutation boundaries

| 操作 | 允许的持久化变化 | 明确禁止 |
|---|---|---|
| Hook/worker | 追加 Event/Evidence/Job，创建或合并 Candidate | 把 Candidate 当政策、修改仓库文件 |
| `record_outcome` | 追加 Feedback；负面结果可入复审 Job | 创建 Evidence、修改成熟度 |
| `request_promotion` | 创建/复用 Proposal | 修改任何目标文件 |
| `mark_experience` | 审计后的状态转换 | 直接设为 `promoted`、绕过证据门禁 |
| `scheduler install` CLI | 显式写当前用户 LaunchAgent | 隐式安装、保存密钥 |
| Personal plugin install | 用户授权的 marketplace/plugin/cache/config 启用状态和 Hook trust Hash | MCP 运行时改写 Host 配置、绕过 Hook trust |
| `index_project` | 写项目私有派生索引 | 修改源码、跟随越界 Symlink、读取排除文件 |
| `prepare_edit` | 写 sealed plan/Journal metadata | 修改仓库、接受任意命令、在审批后更换内容 |
| `apply_edit` | 经 destructive 审批写允许路径并重索引 | 写 denied path、绕过 Hash/HEAD/Token、部分失败静默成功 |
| `sync_module_knowledge` | 预览或经确认写 marker-owned module doc/ai | 覆盖手写 Skill、把 Candidate 变全局政策 |
| `delete` CLI | 用户显式 `--yes` 后删除 Session/Project | MCP 暴露删除能力 |

Stop 后 fork worker 是已有 Job 的异步处理，不扩大写权限；它仍受同一数据库、项目和证据门禁约束。

## Optional remote model

默认 `analysis.provider: none`，不会发送网络请求。启用 `openai` 后，脱敏且有界的 Event 内容、项目/Session 指纹、Evidence Claim/Locator 和 Candidate Draft 会发往受信任的 `base_url`。

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
