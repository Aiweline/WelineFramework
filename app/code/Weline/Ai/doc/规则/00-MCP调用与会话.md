# 规则 00 · MCP 调用与会话

> 分册正文。上位索引 [AI硬规则索引.md](../AI硬规则索引.md)。本册定义：这一回合**走不走 MCP**、技能从哪来、宿主规则文件谁写、Codex 什么时候委派、学习知识冲突怎么处理、模块 doc 能放什么。

## 1. MCP 调用范围（`mcp_call_scope`）

MCP 是**知识面**（技能索引 / 代码地图 / 领域硬规则下发），**没有写仓工具**；编码一律用**宿主原生编辑**。

| 类别 | 是否调用 MCP | 说明 |
|------|--------------|------|
| 纯闲聊 / 身份问答 / 与本仓改码无关 | **通常跳过** | 不必 ensure，不必 prepare |
| **内容运营技能**（`content_ops_skills_skip_mcp`） | **跳过** | 见 §2 |
| 打招呼 `hi`/`你好`/`hello`（无编码任务） | 可 `prepare_project` / `resolve_skill(list_all=true)` | **仅列**技能+指令，不开始写码（`greeting_lists_mcp_skills_and_commands`） |
| 指令「提取技能」 | 同上 | 同上 |
| **编码/工程** | **强制** | ensure（若需）→ `prepare_project` → **读并遵守** `agent_guidance.hard_constraints` → 宿主原生编辑；按需 `resolve_task_context` / `search_project_knowledge` / `get_skill` |

**上下文丢失**：长对话压缩后若看不到 `hard_constraints`，**必须重新** `prepare_project`；不得凭记忆编造规则。

**MCP 挂不上**：宿主 Read [AI硬规则索引.md](../AI硬规则索引.md) + 对应分册继续开发；不得假装已遵守 MCP，不得借此绕过 `blocked`、`dev` 分支、文档/响应式/真实运行验收或部署权限门禁。

**同回合混合任务**：既有内容运营又有框架 Theme/PHP 编码时，**只有编码切片**走 MCP；内容运营切片仍只读仓内技能/指令。

## 2. 内容运营技能跳过 MCP（`content_ops_skills_skip_mcp`）

命中下列任一即属此类：产品优化 / 商品优化 / 详情优化 / 商详优化 / 翻译优化 / 商品翻译 / 主图优化 / 规格图优化 / 修主图 / 新建文章 / 写博客 / 审查文章 / 文章可行性 / 精写文章 / blog article / 规格修复（或宿主/仓内技能 `ecommerce-product-optimize`、`ecommerce-detail-suite`、`ecommerce-product-image`、`ecommerce-product-i18n`、`weline-blog-article`）。

| 允许 | 禁止 |
|------|------|
| 宿主 Read 匹配的 `dev/ai-command/**` + `app/code/*/doc/ai/skills/**/SKILL.md`（宿主 Store 镜像仅薄指针） | 调 `prepare_project` / `resolve_skill` / `get_skill` / `search_project_knowledge` / `resolve_task_context` 或拉全量索引包 |
| 同回合另有框架编码切片时，仅该切片按 §1 走 MCP | 为「以防万一」拉无关 MCP 技能或 `hard_constraints` 正文 |

内容运营任务明细见 [规则/50-内容运营.md](./50-内容运营.md)。

## 3. MCP 技能来源（`mcp_skills_fetch_from_mcp`）

| 允许 | 禁止 |
|------|------|
| **编码/工程**技能正文由 MCP `mcp-skills.v1` 提供：`prepare_project.agent_guidance.mcp_skills` → `resolve_skill` → `get_skill` | 把 Cursor/Codex 本地 `SKILL.md` 当作高于 MCP 的权威（工程技能） |
| **内容运营**：仓内 `doc/ai/skills/*/SKILL.md` + `dev/ai-command` 即为权威 | 对产品优化/文章创建等任务仍走 prepare / get_skill / 拉全量索引 |
| 宿主 Agent Skills 对工程技能仅作**可选薄壳**（提醒去调 MCP） | 恢复 `knowledge.auto_generate_skills` / 仓库内 Skill 投影 |
| 工程任务文档片段继续用 `resolve_task_context` | 用静态技能文件替代工程 workflow surfaces / 硬约束 |

常用别名（工程 `get_skill(skill_id=…)`）：`weline-theme-development`、`local-browser-urls`、`weline-taglib-first`（映射到对应 surface id）。

模块 doc 技能：`doc/ai/INDEX.json` + `doc/ai/skills/*/SKILL.md` 随仓；内容运营直接宿主 Read；工程侧可由 MCP 只读提取。全量列表：`resolve_skill(list_all=true)` 或指令「提取技能」。

## 4. 宿主编辑器规则（`host_editor_rules_mcp_generated_only`，强制）

| 允许 | 禁止 |
|------|------|
| 规则权威维护在 MCP `hard-constraints.v1` 与 `Ai` / `Framework` / 模块 `doc/` | Agent **手写/直接编辑** `.cursor/rules/*.mdc`、`.cursorrules`、`CLAUDE.md`、`.codex/*`、`.github/copilot-instructions.md` 等作为规则源 |
| 由 **MCP**（ensure / `HostEditorRulesGenerator`、`HostCursorHooksGenerator`）写出宿主编辑器规则产物（含 `.cursor/rules/weline-mcp-coldstart.mdc`、`.cursor/hooks.json`） | 把编辑器私有规则文件当成高于 `prepare_project.hard_constraints` 的权威 |
| `AGENTS.md` 仅作 MCP 接通指针 | 换项目后仍依赖本机/他仓残留的 Cursor/Codex 私有规则 |

原因：换项目后编辑器私有规则会丢失或分叉；只有 MCP + 仓库文档可随项目带走。

## 5. Codex CLI 宿主委派（`host_delegate_explore_plan_review_to_codex_cli`，**用户显式 opt-in**）

| 允许 / 必须 | 禁止 |
|------|------|
| **默认**：用户本回合**未提及** Codex/codex/Codex CLI → 宿主自行探索、Plan Mode 写详细计划、编码后自审 | **因 PATH/`CODEX_CLI_PATH` 有 `codex` 就自动委派** |
| 用户显式提及 Codex（触发词：`Codex` / `codex` / `Codex CLI` / `用 Codex` / `委派 Codex` / `codex exec` / `codex review`）且宿主非 Codex：探测 CLI；可用则用 **`codex exec`（只读）** 探索并写详细计划，编码后 **`codex review --uncommitted`** | Opt-in 且 CLI 可用时 Cursor 自行写笼统计划、跳过 Codex 探索/审查 |
| Opt-in 时计划正文仅 **背景 / 方案 / 细节**（`plan_content_focus_only`）；细节须含文件、符号/规则 id、测试断言、验收与 fallback | Opt-in 时另起第二套与 Codex 计划冲突或空泛的计划 |
| Opt-in 时 Cursor **只按 Codex 计划做编码**；Plan Mode 仅作审批/展示容器 | Opt-in 时把 Plan Mode 当成第二个计划作者 |
| 使用 Codex CLI **默认最新模型**；命令模板见 `agent_guidance.host_codex_delegation` | 写死 `-m` / `--model` 钉旧模型 |
| **用户可见状态（仅 opt-in 且启动委派时强制）**：启动 `codex` 前聊天明示「Codex 正在工作：{阶段}…」；完成写「Codex 已完成」；回退写「Codex 不可用，已回退宿主：{原因}」 | 静默跑 `codex`，界面上看起来仍是宿主自己在干活 |
| 与嵌套 MCP planner（`knowledge.codex.enabled` / `CodexInvoker`）**解耦**；不依赖该开关 | 因 `knowledge.codex.enabled=false` 就跳过已 opt-in 的宿主委派 |
| 当前宿主已是 Codex：原生完成探索/计划/审查 | Codex 宿主再 shell 嵌套 `codex exec`/`codex review`（递归） |
| Opt-in 但 CLI 缺失/不可执行/鉴权失败/超时/输出不合规：回退宿主 Plan Mode 并**记录原因**（同时发回退可见提示） | Opt-in 失败却静默跳过探索计划门禁 |
| 内容运营（`content_ops_skills_skip_mcp`）与闲聊豁免 | 对产品优化/写博客等仍强制委派 Codex |

权威：`HardConstraintsCatalog::hostCodexDelegation()` → `prepare_project.agent_guidance.host_codex_delegation`（含 `opt_in`）。

## 6. 会话学习知识库与冲突门禁（`session_learning_knowledge_conflict_gate`，强制）

| 允许 / 必须 | 禁止 |
|------|------|
| 把**可复用、应约束后续工作**的用户立场判为 **知识（学习意图）**；一次性交付任务判为 **需求**；改框架架构/策略的需求落地后可再沉淀为知识候选 | 把所有闲聊当知识，或把明确的长期规矩当一次性需求后忘掉 |
| Cursor/Codex 学习 Hook 写入 Learning SQLite；`prepare_project.agent_guidance.learning_conflicts` 与 `resolve_task_context.rules`（validated / contested / contradiction）视为项目记忆 | 静默覆盖已 validated / contested 知识或未关闭 contradiction |
| 改动将与既有知识冲突时：**停工汇报**（旧规则 vs 新要求、≥2 选项、建议），等用户决策 | 未汇报、未决策就按新说法改掉旧知识 |
| 用户确认知识后用 `learningctl` 记录证据；用户取代时标 contested/revised 并等决策后再动 | 内容运营技能回合仍套用本门禁 |

权威：`HardConstraintsCatalog::mcpOperationalRules()` → `session_learning_knowledge_conflict_gate`。

## 7. 工作区不可丢弃（`preserve_dirty_workspace`，严重）

MCP 的自愈、宿主重载、插件代次刷新、验证回滚和崩溃恢复，以及普通宿主编辑，都必须保留任务开始前已经存在的 tracked、staged、untracked 与 ignored 脏改。

**宿主 Agent / Shell 同等禁止（硬）**：不得为「对齐 HEAD / 清场 / 方便重做编辑」而对工作区执行 `git checkout -- <path>`、`git restore`、`git reset`、`git clean`、`git stash` 或任何等价擦脏。

**脏改区 dirty-load（硬）**：任何 Write / StrReplace / ApplyPatch 之前，必须先宿主 Read **当前磁盘**工作区文件；所有修改必须在该**已存在的脏改内容**上继续编辑。禁止拿 HEAD、索引、对话历史里的旧缓冲、其它会话快照、agent-transcript 摘录或任何更旧基线来当「原文件」再写回——这会导致**会话间相互覆盖**。多 Agent / 多聊天并行时，不得用本会话持有的旧版本覆盖其它会话正在推进的脏文件。Hash 漂移只能 fail-closed 保留磁盘现场，禁止「先还原旧版再覆盖」。丢代码风险优先于操作便利。

MCP 子进程只允许只读 Git 检查，禁止上述全部 Git 写操作，禁止 config/helper/pager 命令注入及 force/discard 变体。分支切换只能由工作区所有者显式执行。

## 8. 运行/状态查询默认本机（`runtime_status_query_local_first`，强制）

| 默认 | 禁止 |
|------|------|
| cron / 队列 / AI·i18n 定时翻译进度 / 日志 / DB 计数 /「还在跑吗」等**运行状态查询默认查本机**工作区库与进程 | 用户未明示时 SSH/查生产或预发；把历史会话里的「线上」当成默认 |
| 仅当用户明示「线上 / 生产 / ssh weline / aiweline.com / 预发」才查对应远端 | 把 SSH MCP **默认 profile=`weline`** 误读成「默认查生产」；发明「翻译相关必须查线上」特例 |

SSH 主机映射只解决「要连生产时连哪台」，不改变查询目标默认值。

### websites 站点柜 → SSH Host（防误绑）

本机 `websites/<site>/`（不进 Git）是各公网站点的**生产主机事实柜**。做该站线上/生产/部署前必须 Read 对应 `README.md`。

| 站点柜 | 主用 SSH Host | 禁止 |
|--------|---------------|------|
| `websites/changanhanfu.com/` | **`hanfu`**（同机兼容 `manycart-server` / `motor` / `fcdc`；IP `47.251.242.124`） | `weline` / `daocharms` / `47.92.25.188` / 库 `www_aiweline_com` |
| `websites/daocharms.com/` | **`daocharms`**（同机兼容 `weline`；IP `47.92.25.188`；应用根 `/www/wwwroot/www.aiweline.com`） | `hanfu` / `47.251.242.124` / 汉服 wwwroot / 库 `changanhanfu` |
| （无柜）www.aiweline.com | **`weline`** | `weline-saas` 当默认生产；汉服机当官方站 |

索引：本机 `websites/README.md`。Cursor alwaysApply 镜像：`.cursor/rules/ssh-weline-host.mdc`（本机生成/维护，不进 Git）。

## 9. 模块 doc 禁放一次性工作资料（`module_doc_forbids_ephemeral_work_artifacts`，强制）

| 允许写入模块 `app/code/*/doc/` | 禁止写入模块 `doc/`（改写到仓库根 `dev/`） |
|------|------|
| 三文档契约：`需求.md` / `功能现状.md` / `开发日志.md`、README | SESSION 总控、team roster/channel/meetings、停工汇报波次文件 |
| 耐久指南 / API / 架构说明、`doc/ai/skills/**` | 一次性 ops brief、scratch、临时调查笔记、可弃验收日志 |
| 耐久功能规格：`doc/开发/spec/{slug}.md`；已沉淀为产品知识的长期章程 | 把临时操作文件「归档」进模块 doc 冒充耐久文档 |

权威路径：`dev/session/{slug}.md`、`dev/team/{slug}/`、抛掷物 `dev/tmp/`。历史已落在模块 `doc/开发/team|session` 的文件可只读延续；**新建写入必须进 `dev/`**。

> **禁止迁移历史纪要（血泪教训）**：`doc/开发/team|session` 下**已入库**的历史纪要属上句的「只读延续」豁免——**它们不与本规则冲突，保持原地不动**。
> - **禁止**迁进 `dev/`：`dev/.gitignore` 首行为 `*`（`dev/` 是本机工作区、按设计不入 Git），迁入会让**已入库**纪要脱离版本控制。
> - **禁止**改名归档到模块内新路径（如 `doc/开发/纪要/{slug}/`）：那正是上表最后一行禁止的「把临时操作文件『归档』进模块 doc 冒充耐久文档」。
> - 动 `dev/` 前必须先 `git check-ignore` / `git ls-files` 验证目标目录是否受版本控制。

工程团队路径见 [工程团队.md](../../../../../../dev/ai-command/ai/工程团队.md)。

## 10. MCP 编译（权威落点）

| 产物 | 位置 | 职责 |
|------|------|------|
| `hard-constraints.v1` | `prepare_project.agent_guidance.hard_constraints` + MCP `instructions` preamble | 全局硬约束（由硬规则索引与各分册编译） |
| `host-codex-delegation.v1` | `agent_guidance.host_codex_delegation`（亦挂在 hard_constraints 包内） | Cursor↔Codex CLI 宿主分工 |
| `mcp-skills.v1` | `agent_guidance.mcp_skills` + `resolve_skill` / `get_skill` | 按任务可拉取的工程技能正文 |
| `session_startup_notices` | 同上 `agent_guidance` | **只指路**，不复制细则 |
| `workflow_contract.v1` surfaces | `resolve_task_context` | 按任务下发 Taglib/Theme/Hook 等细则 |
| 宿主 `AGENTS.md` / ensure | 仓库根 / 脚本 | 只负责接通 MCP，不写框架法 |

实现类：`app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php`、`McpSkillCatalog.php`、`GuidanceWorkflowCatalog.php`。**改本册 / 索引 / 交付流程 / 目录后，必须跑**：

```bash
php app/code/Weline/Ai/Mcp/tests/guidance-workflow-contract.php
php app/code/Weline/Ai/Mcp/tests/mcp-skills-catalog.php
php app/code/Weline/Ai/Mcp/tests/host-mcp-registration-contract.php
```

## 相关

- [AI硬规则索引.md](../AI硬规则索引.md) · [规则/10 工程流程与验收](./10-工程流程与验收.md) · [AI工程交付流程.md](../AI工程交付流程.md) · [AI开发治理.md](../AI开发治理.md)
