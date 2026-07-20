# Weline MCP

[简体中文（当前）](README.md) · [English](README.en.md) · [日本語](README.ja.md)

**让 Codex 从逐文件试探，升级为任务级批量编码。**

**省 Token、加速编码、零配置分项目、多会话不丢代码，并在每次任务后自动学习、产出 Skill。**

Weline MCP 是专为 Codex 打造的本地项目智能、批量编码与自学习引擎。它把 AI 编码的工作单位从“一个文件”提升为“一个完整任务”：一次取得任务所需的精准上下文，一次提交跨文件修改，再由本地事务引擎统一完成并发保护、验证、回滚和索引更新。

- Project Intelligence：持久索引代码、文档、符号关系、项目规则和 Skill，按 Token Budget 返回真正相关的有界片段、上下游影响和匹配知识。
- Evidence-backed Learning：从用户纠正和经过测试、构建、静态检查、浏览器或运行时验证的结果中提取经验，完成证据复核、作用域判断、判重、冲突与成熟度管理，再生成项目级或模块级 `SKILL.md`。

主流程收敛为：**一次 `get_edit_bundle` 批量取全，一次 `apply_compact_edit` 批量提交。**

正常运行只依赖 PHP 8.2+、SQLite 扩展和 Git。Composer 与 Node.js 是可选发行入口；Node 包只是无依赖启动壳，最终仍由同一 PHP STDIO Server 接管。每个 canonical Git root 使用独立的 `project.sqlite`，不会跨项目串代码、知识、经验或 Skill。

> “零配置分项目”指在 Codex 中完成一次全局注册后，后续仓库无需逐项目配置。独立包不能在 Host 发现它之前自我注册，因此首次安装仍需运行安装器或 `codex mcp add`。

## 能达到什么速度优化？

能减少重复扫描、工具往返和上下文 Token。首次由 MCP 本地读取符合规则的 Git 文件并建立持久索引；后续主路径固定为“一次读、一次写”：

1. AI 调用一次 `get_edit_bundle`，同时提交任务、已知路径和符号；MCP 先对每个显式 `paths[]` 做内容 Hash 定点刷新，再只返回命中的代码/文档区域、Hash、影响摘要和 Skill 位置，不返回整文件。
2. Codex 只生成这些区域的 `edit-plan.v1` Replacement，再调用一次 `apply_compact_edit`；PHP 按 project-relative path 排序获取跨进程文件锁，在锁内完成 Seal、原子写入、固定验证、定点重索引和失败自动回滚，结束路径立即释放全部锁。

一个 Plan 可以同时包含多个文件，也可以包含同一文件的多个非重叠操作。同一路径的操作先按原始 byte range 倒序合并成唯一 postimage；重叠或共享边界含义不明确时返回 `EDIT_RANGE_OVERLAP`。当存在多个不同目标文件且 PHP CLI 提供 PCNTL 时，MCP 最多使用 4 个本地子进程并行写入各自的同目录临时文件；父进程复核全部 postimage Hash 后，再按路径顺序执行原子 `rename`。单文件、Windows 或无 PCNTL 环境自动使用相同语义的串行 staging。

同一路径的其他会话进入内核锁等待队列；拿到锁后再次定点刷新全部目标，再以最新 revision/read-set 解析计划。文件整体 Hash 已变化但唯一文本锚点或 symbol/section/range digest 仍成立时可安全重定位；片段消失、变得歧义或其他目标守卫失效时，旧计划不会继续写入，而是返回 `EDIT_REPLAN_REQUIRED`、最新有界 `regions[]`、原始 `metadata.task` 和“必须生成全新计划”的重试契约。

每次显式 `paths[]` 都先读取当前内容并核对 Hash，发生变化就立即更新 SQLite，再一次物化该范围而不跑全局 FTS/trigram/sparse；多个符号的上游影响也合并为批量 SQL。明确请求且通过安全策略的 Git 忽略文件会登记为精确索引路径，后续整仓刷新继续保留且不扫描其父目录。写链路锁内预检目标，最终成功只索引 postimage，失败回滚后只索引 preimage。

`resolve_task_context`、`get_indexed_files`、`prepare_edit`、`apply_edit` 等细粒度工具仍是兼容实现，但默认不暴露给 AI。

这不是绕开 Codex，也不是“向量总比命令快”。路径、FQCN 和方法名优先走精确索引；自然语言和中文走 FTS5/trigram + 本地稀疏向量；调用/影响走符号关系层。真正的收益来自预索引、持久进程、少传候选内容和少输出机械代码。

## MCP 使用可见回执

每次真实的 MCP `tools/call`（成功或失败）都会在 `structuredContent` 中加入服务端拥有的 `_weline_mcp`；兼容文本只返回带同一 `receipt_id` 的短摘要，不再复制完整结果。调用方在本轮至少收到一个这样的回执后，后续每次用户可见进度和最终汇报都必须以 `Weline：` 开头。

仅加载插件、注册 Server、执行 `initialize` 或运行 `SessionStart` Hook 不算使用，不应仅凭启动上下文输出该前缀。`Weline：` 与回执用于让人看见本轮确实完成过 MCP 工具调用；它们不是身份认证、权限授权或不可伪造的密码学证明。独立 `learningctl intelligence` CLI 不经过 MCP `tools/call`，因此也不会生成该协议回执。

2026-07-14 在本仓库的验收基线：默认工具面从 25 个缩到 5 个，`tools/list` 从 21,827 bytes 降到 5,242 bytes；同一任务的 MCP 响应从 24,826 bytes 降到 5,593 bytes，兼容文本仅 260 bytes。P0 性能复测中，已知路径 bundle 热中位从 222.730ms 降到 74.455ms（约 2.99×）；索引已新鲜时，新 PHP/MCP 进程端到端实测 121.41ms，内部检索 72.755ms。完整索引构建和首次缓存预热仍受仓库规模与磁盘影响，这些数字不是跨项目 SLA。

## 它能否定期分析会话并自我学习？

能。这里的“自我学习”是**非参数式学习**：更新本地经验库、项目索引和 MCP 自有的生成技能，不修改模型权重，不覆盖手写规则或技能。

有两条分析触发路径：

1. `Stop` Hook 写入事件并入队，默认立即 fork 一个短生命周期 PHP worker 完成本次分析。
2. `learningd` 扫描超过 `scheduler.session_idle_after` 未活动的会话；macOS 可由显式安装的 LaunchAgent 每隔 `scheduler.launchd_interval` 执行一次，因此即使会话没有触发 `Stop`，也能增量分析。

分析任务使用事件检查点作为幂等键。没有新事件时重复扫描不会重复学习；会话后来新增事件时会生成新的检查点重新分析。默认 `analysis.provider: codex` 会在隔离、只读、无 MCP、无 Shell 继承的子进程中读取脱敏且有界的事件/Evidence；子进程失败时自动降级为确定性的用户纠正分析，不阻断 Stop。

每条提取结果先分类为 `global_rule`、`project_rule`、`skill_knowledge` 或 `operational_observation`；没有持久价值的信号返回 `discard`/`no_learning`，不写入知识。全局规则只代表跨仓库的稳定规范，永不自动验证或写入全局配置；项目规则保留在当前项目经验层；技能知识才进入生成 Skill；工具、Browser、OS 策略或运行时能力限制必须记为带 `surface` 和 `environment_constraints` 的操作观察，不能从单机结果泛化成全局硬规则。例如“当前 Codex 内置 Browser 安全策略拒绝 `file://`”应先记为操作观察，正向示例使用 localhost/file-backed route，除非官方或跨环境证据确认，否则不得升级为全局规则。

所有候选知识必须同时携带一个具体正向示例和一个不同的负向示例。PHP 确定性门禁会校验证据 ID、知识类别、产品表面、环境、作用域和双示例；缺任一示例、两个示例相同、操作观察缺环境、仅有用户技术主张、临时信息或存在冲突时，只能保留为 Candidate/Contested，不能自动进入 `validated`，也不能生成技能。通过门禁后仍会查询同项目 Experience 和当前 Project SQLite 代码/文档/规则/配置/技能索引：语义重复合并证据；已明确索引的规则记为 `known_project_knowledge`；新增范围记为 `enrichment`；反向规则建立 Contradiction。

明确的用户意图/偏好，或具备 test/build/lint/browser/runtime/user-confirmation/CI 成功证据的非冲突技术知识，在完成分类和双示例后可以自动通过 PHP 状态门禁进入 `validated`。自动验证不等于自动晋升：`promotion.automatic` 始终为 false，MCP 不能自动改写 `AGENTS.md` 或全局策略，`global_rule` 必须人工审核作用域。

同步任务只选取双示例完整的 `skill_knowledge` 与 `operational_observation`，把脱敏、有界的经验摘要交给隔离的 read-only/ephemeral Codex。Codex **只分组经验 ID，并原样返回知识类别与 surface 路由元数据**；PHP 再从本地验证记录确定性渲染含 Positive/Negative Example 的技能。若 `knowledge.learning_skills.output_directory` 留空，继续使用项目级 `dev/ai/skills/MCP学习-*/SKILL.md`，并对命中 `app/code/{Vendor}/{Module}` 的经验保留模块投影；配置目录后，项目级技能、`_index.md` 和 `MCP-LEARNING-INDEX.json` 全部输出到该目录，不再假定 Weline 模块结构。索引调用返回后，任务会立即批量反查 Project SQLite 的 `indexed_files`、压缩内容库和 `skills`，逐一核对 revision、文件 Hash、删除状态、Skill actionable 状态与 source Hash；只有拿到 `closed_loop.status=verified` 才能完成 Job，否则按可重试错误保留为未完成。

不生成 Skill 的 `project_rule` 仍以 Learning SQLite 的 Experience 为权威来源；`get_edit_bundle.validated_learning` 在每次查询时直接读取这条数据库通道，因此不需要伪造一个重复文档才能被 AI 命中。生成 Skill 的知识则必须通过上述文件投影 → Project SQLite → 批量反查闭环，两条通道最终在同一个 `get_edit_bundle` 中合并。

晋升只生成 Proposal；候选经验不能自动修改 `AGENTS.md`、Prompt、手写 Skill、测试、CI 或安全策略。模块 `doc/ai/skills` 同时容纳确定性代码/文档 Locator Skill 与证据门禁后的学习技能，两类文件使用不同 Marker，手写冲突都会停止。

## 已实现

- PHP 8 本地模块，三个可执行入口：`bin/learning-mcp`、`bin/learningctl`、`bin/learningd`。
- SQLite + WAL + FTS5；事件、证据、经验版本、反馈、矛盾、提案、Job 与审计日志完整落库。
- 每个项目独立的 SQLite/WAL 代码知识索引；Git/Hash 增量发现、压缩完整内容库、单查询批量读取、双 FTS、确定性稀疏向量、PHP Token 符号与关系、Markdown Heading Chunk。
- 自动 PHP 索引 sidecar：Unix socket、项目队列合并、100ms 去抖、已知路径定点刷新、15 分钟空闲退出，以及一次性 PHP 刷新回退。
- SQLite 读热路径默认启用 256MiB demand-paged mmap 和 16MiB 连接页缓存；已知路径检索与批量符号影响均返回阶段计时。
- `app/code/{Vendor}/{Module}/doc/**` 文档索引、漂移检测、`doc/ai/INDEX.json`、marker-owned Locator Skill，以及按经验作用域自动生成的模块学习 Skill/索引。
- 结构化 Edit Plan、一次性 Token、Read-set/Hash/HEAD/Revision 门禁、同文件跨会话排队锁、锁后安全重定位、重叠冲突最新区域回传、同目录原子替换、失败恢复、Rollback postimage 保护、立即重索引。
- 可选的 read-only/ephemeral Nested Codex 文档 Planner；只接受严格 `doc-sync.v1`，不能写工作区。
- 默认开启的 read-only/ephemeral Codex 会话学习提取器；严格 `session-learning.v1` 要求 Evidence ID、四类知识作用域、surface/环境和不同的正反示例。
- 默认开启的 read-only/ephemeral Codex 学习分类器；严格 `learning-skills.v1` 要求每个可生成技能的 Experience ID 只能分配一次，且知识类别与 surface 必须和 PHP 来源记录完全一致。
- Evidence-gated 项目/模块双层 `MCP学习-*` 投影；只接收双示例完整的技能知识/操作观察，并把类别、surface、环境、Positive/Negative Example 写入技能正文和 JSON manifest；定点重索引后批量校验 revision、完整内容 Hash、Skill 元数据和删除状态，闭环回执随 Job 审计落入 Learning SQLite。
- `get_edit_bundle` 从全部已知路径推断模块，一次返回项目级、当前模块及相关命中技能的路径、Hash 和有界正文；`UserPromptSubmit` 继续从同一 Project SQLite 注入命中全文，路由失败不阻断用户请求。
- Codex `SessionStart`、`UserPromptSubmit`、`PreToolUse`、`PostToolUse`、`PreCompact`、`PostCompact`、`Stop` Hook 采集。
- Codex 个人插件自动注册 MCP 和完整 Hook 链；`SessionStart` 根据当前 `cwd` 解析 canonical Git root，注入有界索引路由元数据，并自动唤起 sidecar 刷新对应项目。`PostToolUse` 会跳过严格只读 Shell、MCP 读工具和不调用本地工具的沙箱 Node 编排；嵌套只读命令也不刷新，嵌套/直接 `apply_patch` 提取精确路径，动态命令、终端输入和未知写工具保守退化为整仓增量。
- Stop 后自动短任务、活动会话空闲扫描、常驻 worker，以及 macOS LaunchAgent 安装/状态/卸载命令。
- 密钥脱敏、Prompt Injection 隔离、事件幂等、Job lease/重试/死信、项目隔离与显式删除。
- 默认使用 Codex CLI 提取、PHP 确定性证据复核和知识判重；可设为 `none` 仅分析用户纠正，也可选择 OpenAI Responses API 的“双阶段提取 + 独立验证”。
- 默认只向 AI 暴露 5 个 MCP 工具：`get_edit_bundle`、`apply_compact_edit`、`get_edit_status`、`rollback_edit`、`health`。设置进程环境变量 `WELINE_MCP_TOOL_PROFILE=full` 可恢复全部兼容工具用于独立诊断；Codex 个人插件仍固定使用精简工具面。完整契约见 [完整契约与架构](docs/PROJECT-INTELLIGENCE.md#mcp-工具)。
- `get_edit_bundle` 以 `structuredContent` 保存权威结构，同时把同一份有界 bundle 镜像到文本 `content`。因此只转发 `content` 的 Codex deferred-tool 包装也能一次收到全部区域，不需要再调用原生逐文件 Read。

Full profile 中保留的学习管理工具包括：

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
- Codex CLI，用于默认开启的已验证经验分类；Codex Desktop/ChatGPT.app 已自带，`doctor` 会检查可用性。
- `pcntl`/`posix` 用于 Stop 后异步短 worker；macOS Homebrew PHP 通常已包含。若缺少它们，采集仍可用，改由 `learningd`/调度器处理队列。

检查当前环境：

```bash
./bin/learningctl doctor --config ~/.learning-mcp/config.yaml
```

## 安装与启动

三种发行方式使用同一套 PHP 实现和同一份配置，任选一种即可。

### 方式一：源码与跨平台启动脚本

GitHub：

```bash
git clone https://github.com/Aiweline/Weline-Codex-Mcp.git
cd Weline-Codex-Mcp
./start.sh
```

Gitee：

```bash
git clone https://gitee.com/aiweline/weline-codex-mcp.git
cd weline-codex-mcp
./start.sh
```

Windows CMD：

```bat
git clone https://gitee.com/aiweline/weline-codex-mcp.git
cd weline-codex-mcp
start.bat
```

`start.sh` 与 `start.bat` 会检查 PHP 8.2+、`pdo_sqlite`、`json`、`mbstring`、`openssl` 和 Git；缺失时尝试使用 Homebrew、APT、DNF/YUM、Pacman、Zypper、WinGet 或 Chocolatey 安装。首次运行会复制示例配置到用户目录并启动 STDIO MCP。日志只写 stderr，不污染 JSON-RPC stdout。无交互 MCP Host 应先在终端运行一次，以便完成 sudo/UAC 安装。

### 方式二：Composer 包

Packagist 登记前可立即从 GitHub 安装：

```bash
composer global config repositories.weline-mcp vcs https://github.com/Aiweline/Weline-Codex-Mcp
composer global require aiweline/weline-codex-mcp:^0.9
composer global exec -- weline-mcp-install --register-codex
```

也可把 repository URL 换成 `https://gitee.com/aiweline/weline-codex-mcp`。登记 Packagist 后可直接执行：

```bash
composer global require aiweline/weline-codex-mcp
```

`weline-mcp-install` 会检查运行时、保留已有配置、缺失时创建 `~/.learning-mcp/config.yaml`，并只在显式传入 `--register-codex` 时修改 Codex MCP 配置。Composer 只负责分发，运行时不依赖 Composer。

### 方式三：Node/npm 启动壳

npm Registry 发布前可直接从 Git 仓库安装：

```bash
npm install -g git+https://github.com/Aiweline/Weline-Codex-Mcp.git
codex mcp add weline -- weline-mcp
```

Gitee 源：

```bash
npm install -g git+https://gitee.com/aiweline/weline-codex-mcp.git
codex mcp add weline -- weline-mcp
```

发布 npm Registry 后可执行：

```bash
npm install -g weline-codex-mcp
codex mcp add weline -- weline-mcp
```

Node 壳无第三方依赖，只把 stdin/stdout/stderr、参数、环境变量、退出状态和信号交给 `bin/learning-mcp`。它不改写协议，也不启动 HTTP 端口；机器仍需 PHP 8.2+。可用 `WELINE_MCP_PHP` 或 `PHP_BINARY` 指定 PHP。

### 技能输出目录

默认配置为 `~/.learning-mcp/config.yaml`，Windows 为 `%USERPROFILE%\.learning-mcp\config.yaml`。可用 `LEARNING_MCP_CONFIG` 指定配置文件，用 `LEARNING_MCP_SKILL_OUTPUT_DIR` 覆盖技能输出目录：

```yaml
knowledge:
  learning_skills:
    output_directory: ".codex/skills"
```

留空保持原有项目级和模块级投影；相对路径按目标仓库根解析。绝对路径也支持，但仓库外目录不会进入该项目 SQLite 索引，需要由 MCP Host 将其作为技能目录加载。一个配置目录只能由一个项目 Manifest 管理，避免跨项目静默覆盖。

## Codex 接入与项目自动隔离

独立发行版只需注册一次 MCP；之后每个任务根据当前目录自动定位 canonical Git root，并选择该项目独立的索引、知识、经验与 Skill 空间。

源码安装：

```bash
codex mcp add weline -- /absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp --config /absolute/path/to/config.yaml
codex mcp list
```

Composer 安装运行 `weline-mcp-install --register-codex`；Node 安装注册 `weline-mcp`。Codex Desktop、Codex CLI 与 IDE Extension 在同一 Host 上共享 MCP 配置。也可在 Desktop/IDE 的 **Settings → MCP servers → Add server** 中选择 STDIO，填写命令，保存后重启客户端。

手工 TOML：

```toml
[mcp_servers.weline]
command = "/absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp"
args = ["--config", "/absolute/path/to/config.yaml"]
startup_timeout_sec = 20
tool_timeout_sec = 120
```

确认连接后使用 `/mcp` 或 `codex mcp list`。Server 是 Host 按需拉起的 STDIO 子进程，不需要网络端口。

独立 MCP 已包含项目识别、索引、编辑和学习能力。若还需要在 SessionStart、UserPromptSubmit、PostToolUse、Stop 等生命周期自动刷新与采集，可额外安装个人插件，或按 `examples/codex-hooks.json` 合并 Hook；Hook 不是五个核心工具的前置条件。

建议在项目 `AGENTS.md` 中加入：

```markdown
- Call get_edit_bundle once with the task, all known paths, and all affected symbols.
- Use returned regions, hashes, impacts, docs, and Skills instead of repository-wide scans.
- Submit one edit-plan.v1 and call apply_compact_edit once.
- Use get_edit_status and rollback_edit for recovery only.
```

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

以后查询只使用持久索引；Codex/Hook/MCP 写入会即时定点刷新，`index.refresh_interval` 默认 60 秒，仅作为绕过 Hook 的外部编辑兜底：

```bash
./bin/learningctl intelligence get_edit_bundle \
  --repository /absolute/path/to/repository \
  --input '{"task":"定位配置读取和关联文档","paths":["app/code/Weline/Foo/A.php"],"token_budget":1800}' \
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

## 可选 Hook 与 worker

五个核心 MCP 工具只需要 STDIO Server。若要在每次任务结束后自动采集证据、分析经验并投影 Skill，再按需启用 Hook 和 worker：

1. 复制 [config.example.yaml](config.example.yaml)，按需修改索引、编辑、知识、数据目录和调度配置。
2. 把 [examples/codex-hooks.json](examples/codex-hooks.json) 中的 CLI 与配置路径替换为绝对路径，再合并到受信任的 Codex Hook 配置。
3. 选择 `learningd run`、周期性 `learningd drain`，或显式安装系统调度；安装 MCP 本身不会静默创建系统服务。
4. 新建 Codex 任务，让 Host 重新加载新增的 MCP/Hook。

Collector 使用内容指纹和数据库唯一约束处理并发与重试。官方参考：[Codex Hooks](https://learn.chatgpt.com/docs/hooks)、[Codex MCP](https://learn.chatgpt.com/docs/extend/mcp)、[Scheduled tasks](https://learn.chatgpt.com/docs/automations)。

`SessionStart` 的索引路由元数据不包含整段代码正文；经验只在成熟度、证据、作用域和冲突门禁通过后进入上下文。已运行任务不会在中途动态增加刚安装的工具或 Hook。

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

技术经验只有在置信度达标，并具备已验证的非模型证据和 test/build/lint/browser/runtime/user-confirmation/CI 等结果证据时，才能进入 `validated`。满足门禁且无冲突时 worker 会自动标记；弱证据、冲突和人工复核仍可使用上述命令。MCP 与 CLI 都不能直接设置 `promoted`。

## 数据与隐私

- 默认数据目录：`~/.learning-mcp`；学习数据库：`~/.learning-mcp/learning.db`；项目索引与压缩文件内容：`~/.learning-mcp/indexes/{project-hash}/project.sqlite`；编辑 Journal：`~/.learning-mcp/edit-journal/**`；跨会话文件锁：`~/.learning-mcp/edit-locks/{project-hash}/{path-hash}.lock`（文件可持久存在，所有权由进程级 `flock` 生命周期决定）。
- 目录权限强制为 `0700`，数据库权限强制为 `0600`。
- Collector 只保存脱敏后的 Hook 事件；`transcript_path` 仅记“是否存在”，不会读取或复制完整 Transcript。
- `do_not_learn: true` 跳过当前事件。
- 默认 `analysis.provider: codex`，会话提取调用当前 Codex 账号/配置的模型，只发送脱敏、有界的 Session/Event/Evidence；不会读取仓库或获得 MCP/Shell 权限。学习技能分类只发送脱敏、有界的已验证经验摘要。两条路径都不需要单独 API Key，但仍应符合组织数据与费用策略。
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
