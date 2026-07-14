# Weline Project Intelligence & Learning MCP

这是一个本地优先、无 Composer 依赖的 PHP MCP Server。它同时提供两条能力链：

- Project Intelligence：预索引代码、模块文档、符号关系、知识和 Skill，向 Codex 返回精确位置与有界片段，并在本地事务化应用结构化修改。
- Evidence-backed Learning：把 Codex 会话中的“错误尝试 → 用户纠正 → 可观察结果”整理为项目级经验，定期分析并向后续任务提供带证据、作用域和成熟度的指导。

正常运行只需要 PHP、SQLite 和 Git，不需要 Go、Node.js、Composer、外部向量数据库或额外服务。每个项目的 `project.sqlite` 同时保存文件元数据、压缩后的完整文本、Chunk、FTS、稀疏向量、符号关系和知识索引；可选的文档 Planner 会调用 Codex App 自带或配置的 `codex exec`，但默认关闭。

## 能达到什么速度优化？

能减少重复扫描、工具往返和上下文 Token。首次由 MCP 本地读取符合规则的 Git 文件并建立持久索引；后续推荐固定为两次调用：

1. AI 用 `resolve_task_context` 一次确定任务需要的完整路径清单。
2. AI 把清单一次传给 `get_indexed_files`；MCP 用一条 SQLite 查询按输入顺序返回最多 50 个文件的内容、Hash 和修订号，不逐文件读取工作区。

Codex 随后只输出小型 `edit-plan.v1`，MCP 在本地完成符号定位、机械替换、原子写入、固定验证和定点重索引。

这不是绕开 Codex，也不是“向量总比命令快”。路径、FQCN 和方法名优先走精确索引；自然语言和中文走 FTS5/trigram + 本地稀疏向量；调用/影响走符号关系层。真正的收益来自预索引、持久进程、少传候选内容和少输出机械代码。

2026-07-14 在本仓库的验收基线：发现 11,378 个 Git 可见文件，8,833 个文件进入索引；一次完整构建约 93.8 秒，当前数据库约 822 MiB；无变化增量检查内部约 0.49 秒（CLI 墙钟约 0.72 秒），同一 MCP 进程内的热上下文查询约 0.71 秒。这是当前机器和工作区的实测值，不是跨项目 SLA；首次冷查询、候选规模和磁盘缓存会影响延迟。

## 它能否定期分析会话并自我学习？

能。这里的“自我学习”是**非参数式学习**：更新本地经验库和后续检索结果，不修改模型权重，也不会自动改写仓库规则。

有两条分析触发路径：

1. `Stop` Hook 写入事件并入队，默认立即 fork 一个短生命周期 PHP worker 完成本次分析。
2. `learningd` 扫描超过 `scheduler.session_idle_after` 未活动的会话；macOS 可由显式安装的 LaunchAgent 每隔 `scheduler.launchd_interval` 执行一次，因此即使会话没有触发 `Stop`，也能增量分析。

分析任务使用事件检查点作为幂等键。没有新事件时重复扫描不会重复学习；会话后来新增事件时会生成新的检查点重新分析。

学习产物首先是 `candidate`，不能直接进入指导。只有通过证据和置信度门禁、再由人工标记为 `validated` 的经验，才会被 `get_relevant_guidance` 或可选的 `SessionStart` 上下文注入使用。晋升只生成 Proposal；候选经验不能自动修改 `AGENTS.md`、Prompt、全局 Skill、测试、CI 或安全策略。模块本地 `doc/ai/skills` 只可由已索引的确定性代码/文档事实生成，并必须经过同一个 `prepare_edit → apply_edit` 审批事务。

## 已实现

- PHP 8 本地模块，三个可执行入口：`bin/learning-mcp`、`bin/learningctl`、`bin/learningd`。
- SQLite + WAL + FTS5；事件、证据、经验版本、反馈、矛盾、提案、Job 与审计日志完整落库。
- 每个项目独立的 SQLite/WAL 代码知识索引；Git/Hash 增量发现、压缩完整内容库、单查询批量读取、双 FTS、确定性稀疏向量、PHP Token 符号与关系、Markdown Heading Chunk。
- `app/code/{Vendor}/{Module}/doc/**` 文档索引、漂移检测、`doc/ai/INDEX.json` 和 marker-owned `doc/ai/skills/**` 生成计划。
- 结构化 Edit Plan、一次性 Token、Read-set/Hash/HEAD/Revision 门禁、同目录原子替换、失败恢复、Rollback postimage 保护、立即重索引。
- 可选的 read-only/ephemeral Nested Codex 文档 Planner；只接受严格 `doc-sync.v1`，不能写工作区。
- Codex `SessionStart`、`UserPromptSubmit`、`PreToolUse`、`PostToolUse`、`PreCompact`、`PostCompact`、`Stop` Hook 采集。
- Codex 个人插件自动注册 MCP 和完整 Hook 链；`SessionStart` 根据当前 `cwd` 解析 canonical Git root，注入有界索引路由元数据，并在返回 Hook 响应后后台增量刷新对应项目索引；`PostToolUse` 对会话中的外部文件修改安排非阻断增量刷新。
- Stop 后自动短任务、活动会话空闲扫描、常驻 worker，以及 macOS LaunchAgent 安装/状态/卸载命令。
- 密钥脱敏、Prompt Injection 隔离、事件幂等、Job lease/重试/死信、项目隔离与显式删除。
- 默认离线确定性分析；也可选择 OpenAI Responses API 的“双阶段提取 + 独立验证”。
- 25 个 MCP 工具：17 个 Project Intelligence 工具和 8 个 Learning 工具。Project Intelligence 工具见 [完整契约与架构](docs/PROJECT-INTELLIGENCE.md#mcp-工具)；Learning 工具如下：

| 工具 | 类型 | 行为 |
|---|---|---|
| `get_relevant_guidance` | Read | 只返回未过期、无开放冲突的 `validated` 或更高成熟度经验。 |
| `search_experiences` | Read | FTS5、状态、分类、路径过滤；冲突不会被隐藏。 |
| `explain_experience` | Read | 展开证据、纠正、错误路径、置信度、反馈与冲突。 |
| `list_candidates` | Read | 列出待人工审核经验；不能作为执行策略。 |
| `record_outcome` | Additive | 仅引用既存 Evidence 追加幂等反馈。 |
| `request_promotion` | Additive | 从已验证经验重新生成提案，不改目标文件。 |
| `mark_experience` | Admin | 受状态机、证据和置信度门禁约束；不能直接设为 `promoted`。 |
| `health` | Read | 返回 PHP、Schema、数据量、队列、冲突、分析器和调度配置。 |

## 环境要求

- PHP 8.2 或更高版本。
- PHP 扩展：`pdo_sqlite`、`json`、`mbstring`、`openssl`。
- Git，用于项目指纹、分支、提交和工作区状态识别。
- `pcntl`/`posix` 用于 Stop 后异步短 worker；macOS Homebrew PHP 通常已包含。若缺少它们，采集仍可用，改由 `learningd`/调度器处理队列。

检查当前环境：

```bash
./bin/learningctl doctor --config ~/.learning-mcp/config.yaml
```

## Codex 自动接入

当前工作站已安装并启用个人插件 `weline-project-intelligence@personal`：

- 插件源：`/Users/weline/plugins/weline-project-intelligence`
- 个人 marketplace：`/Users/weline/.agents/plugins/marketplace.json`
- MCP 命令：`/Users/weline/Project/Official/框架/dev/ai/mcp/bin/learning-mcp`
- 配置：本目录 [config.example.yaml](config.example.yaml)，数据仍写入 `~/.learning-mcp`

Codex 新任务启动时会自动加载 MCP，不需要手工编辑 `~/.codex/config.toml` 或合并 Hook JSON。插件安装时已对 7 个命令 Hook 完成 Codex 的 hash-based trust review；Hook 内容今后如有变更，Codex 会按安全设计要求重新审核新 Hash，不会静默绕过。

`SessionStart` 只注入 canonical repository、project ID、index DB、revision/freshness/counts 和路由契约，不把代码块或文档正文整批塞进启动 Context。路由契约要求先一次确定路径、再一次批量取内容。每个 Git root 根据 remote/root fingerprint 映射到独立 `project.sqlite`；无完成 revision 时后台首建，已存在时后台增量校验。若 PHP 没有 `pcntl`，则不假装已后台刷新，首个索引读工具会同步完成 freshness 刷新。

插件是解决“MCP 必须先被 Host 发现，才能启动”的启动层；已运行的 MCP 进程无法反过来自我注册到当前 Host。因此新安装/更新插件后需要新建 Codex 任务；旧任务不会在中途动态增加工具。

## 独立 CLI 启动

以下仅用于不经 Codex 插件的独立调试；当前 Codex 正常使用不需要执行。

```bash
mkdir -p ~/.learning-mcp
cp config.example.yaml ~/.learning-mcp/config.yaml
./bin/learningctl doctor --config ~/.learning-mcp/config.yaml
```

MCP Server 是 STDIO 进程，由 Codex 按需拉起，不监听网络端口：

```bash
./bin/learning-mcp --config ~/.learning-mcp/config.yaml
```

手工首次建立项目索引：

```bash
./bin/learningctl intelligence index_project \
  --repository /absolute/path/to/repository \
  --input '{"mode":"full"}' \
  --config ~/.learning-mcp/config.yaml
```

以后查询只使用持久索引；超过 `index.refresh_interval` 时先做 Git/Hash 增量刷新：

```bash
./bin/learningctl intelligence resolve_task_context \
  --repository /absolute/path/to/repository \
  --input '{"task":"定位配置读取和关联文档","token_budget":3000}' \
  --config ~/.learning-mcp/config.yaml
```

拿到路径清单后一次读取全部索引内容：

```bash
./bin/learningctl intelligence get_indexed_files \
  --repository /absolute/path/to/repository \
  --input '{"paths":["app/code/Weline/Foo/A.php","app/code/Weline/Foo/doc/README.md"]}' \
  --config ~/.learning-mcp/config.yaml
```

手工处理当前队列并扫描空闲会话：

```bash
./bin/learningd drain --config ~/.learning-mcp/config.yaml
```

持续运行 worker：

```bash
./bin/learningd run --config ~/.learning-mcp/config.yaml
```

## macOS 定期分析

先查看将要安装的 plist；该命令不修改系统状态：

```bash
./bin/learningctl scheduler print --config ~/.learning-mcp/config.yaml
```

显式安装当前用户的 LaunchAgent：

```bash
./bin/learningctl scheduler install --config ~/.learning-mcp/config.yaml
./bin/learningctl scheduler status --config ~/.learning-mcp/config.yaml
```

它按 `scheduler.launchd_interval` 执行 `learningd drain`，后者分析超过 `scheduler.session_idle_after` 未活动的会话。卸载命令：

```bash
./bin/learningctl scheduler uninstall --config ~/.learning-mcp/config.yaml
```

安装不是自动发生的；只有显式执行 `scheduler install` 才会写入 `~/Library/LaunchAgents`。

## 手工接入 Codex（回退方案）

Personal plugin 不可用时才使用此回退方案：

1. 复制 [config.example.yaml](config.example.yaml)，按需修改索引、编辑、知识、数据目录和调度配置。
2. 把 [examples/codex-hooks.json](examples/codex-hooks.json) 中的 CLI 与配置路径替换为绝对路径，再合并到受信任的 Codex Hook 配置。
3. 把 [examples/codex-config.toml](examples/codex-config.toml) 合并到 `~/.codex/config.toml` 或受信任项目的 `.codex/config.toml`。
4. 选择 `learningd run` 或显式安装 LaunchAgent；Stop 后短 worker 已默认启用。

Codex Hook 会把 JSON 对象写入命令 stdin。Collector 使用内容指纹和数据库唯一约束处理并发/重试。官方参考：[Codex Hooks](https://learn.chatgpt.com/docs/hooks)、[Codex MCP](https://learn.chatgpt.com/docs/extend/mcp)、[Scheduled tasks](https://learn.chatgpt.com/docs/automations)。

`SessionStart` 的索引路由元数据默认注入；经验部分只包含 `promoted` 或置信度不低于 `0.90` 的 `validated` 全项目经验。候选、争议、过期、废弃或带未证明细分作用域的条目不会进入 Developer Context。

## 审核闭环

```bash
./bin/learningctl project --cwd /absolute/path/to/repository

./bin/learningctl review list \
  --project 'repo:sha256:...' \
  --config ~/.learning-mcp/config.yaml

./bin/learningctl review mark \
  --id 'exp-...' \
  --status validated \
  --actor 'reviewer@example.com' \
  --reason 'User correction is followed by an observable successful result' \
  --config ~/.learning-mcp/config.yaml
```

技术经验只有在置信度达标，并具备已验证的非模型证据和 test/build/lint/browser/runtime/user-confirmation 等结果证据时，才能进入 `validated`。MCP 与 CLI 都不能直接设置 `promoted`。

## 数据与隐私

- 默认数据目录：`~/.learning-mcp`；学习数据库：`~/.learning-mcp/learning.db`；项目索引与压缩文件内容：`~/.learning-mcp/indexes/{project-hash}/project.sqlite`；编辑 Journal：`~/.learning-mcp/edit-journal/**`。
- 目录权限强制为 `0700`，数据库权限强制为 `0600`。
- Collector 只保存脱敏后的 Hook 事件；`transcript_path` 仅记“是否存在”，不会读取或复制完整 Transcript。
- `do_not_learn: true` 跳过当前事件。
- 默认 `analysis.provider: none`，不调用远程模型。
- 所有经验绑定项目指纹；v1 不提供跨项目自动召回。

完整威胁模型见 [docs/SECURITY.md](docs/SECURITY.md)。

## 可选 OpenAI 分析器

不需要安装 SDK；实现通过 PHP HTTPS 调用 Responses API，并使用严格 Structured Outputs。配置中只保存环境变量名，不保存 API Key：

```yaml
analysis:
  provider: openai
  api_key_env: OPENAI_API_KEY
  base_url: https://api.openai.com/v1
  extractor_model: YOUR_EXTRACTOR_MODEL
  verifier_model: YOUR_VERIFIER_MODEL
```

```bash
export OPENAI_API_KEY='...'
./bin/learningd drain --config ~/.learning-mcp/config.yaml
```

提取器只能引用服务端提供的 Evidence ID；独立验证器再次检查支持度和作用域。模型臆造的 Evidence、缺少结果证据的技术结论都不能越过服务端门禁。API 格式参考 [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs)。LaunchAgent 不会把 API Key 写入 plist；启用远程模型时应另行向 launchd 提供受控环境变量，或使用前台 `learningd run`。

## 文档导航

- [Project Intelligence 索引、编辑、文档与 Skill](docs/PROJECT-INTELLIGENCE.md)
- [架构](docs/ARCHITECTURE.md)
- [安全与隐私](docs/SECURITY.md)
- [运维与故障处理](docs/OPERATIONS.md)
- [实现边界](docs/IMPLEMENTATION-STATUS.md)
- [JSON Schema](schemas/)
