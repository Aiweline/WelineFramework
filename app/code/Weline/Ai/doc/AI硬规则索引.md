# AI 硬规则索引

> 任何 Weline **开发（编码/工程）**任务的**第一层路由**。MCP 提供技能索引、代码地图与**领域硬规则下发**；**编码用宿主原生编辑**（MCP 无写仓工具）。非编码任务通常跳过 MCP。**工程任务在 MCP 已挂载/可挂载时必须先 `prepare_project` 并遵守 `hard-constraints.v1`**，再按本表定位必读文档。规范正文以链接文件为准；MCP `workflow_contract.v1` 为任务匹配的机器可读摘要。

## MCP 调用范围（`mcp_call_scope`）

| 类别 | 示例 | 是否调用 MCP |
|------|------|--------------|
| 非编码 | 闲聊、身份/概念问答、纯口头建议、与本仓改码无关 | **通常跳过** |
| **内容运营技能**（`content_ops_skills_skip_mcp`） | 产品优化 / 详情优化 / 翻译优化 / 主图优化 / 新建文章 / 审查文章 / 规格修复 | **跳过**：宿主 Read `dev/ai-command/**` + 模块 `doc/ai/skills/**`；**禁止** prepare_project / resolve_skill / get_skill / 拉索引 |
| 打招呼 `hi`/`你好`/`hello`（无编码任务）或指令「提取技能」 | 可 `prepare_project` / `resolve_skill(list_all)` **仅列** MCP 技能+指令；不开始写码 |
| 编码/工程 | 改代码或模块文档、诊断/评审、部署规划、功能验收收口 | **强制** ensure（若需）→ `prepare_project` → 读并遵守 `hard_constraints` → 宿主原生编辑；按需 `resolve_task_context` / `search_project_knowledge` / `get_skill`。MCP 挂不上则宿主 Read 本索引，不得编造规则。**上下文丢失**（长对话压缩后看不到 `hard_constraints`）须**重新** `prepare_project`，不得凭记忆编造 |

同回合若既有内容运营又有框架 Theme/PHP 编码：仅编码切片走 MCP；内容运营切片仍只读仓内技能/指令。

宿主 `AGENTS.md` 与 MCP 生成的 `.cursor/rules/weline-mcp-coldstart.mdc` 只作指针；细则以本表与 `HardConstraintsCatalog::mcpOperationalRules()` 为准。禁止为「记住引导」而手写 `.cursor/rules`。

## 运行/状态查询默认本机（`runtime_status_query_local_first`，强制）

| 默认 | 禁止 |
|------|------|
| cron / 队列 / AI·i18n 定时翻译进度 / 日志 / DB 计数 /「还在跑吗」等**运行状态查询默认查本机**工作区库与进程 | 用户未明示时 SSH/查生产或预发；把历史会话里的「线上」当成默认 |
| 仅当用户明示「线上 / 生产 / ssh weline / aiweline.com / 预发」才查对应远端 | 把 SSH MCP **默认 profile=`weline`** 误读成「默认查生产」；发明「翻译相关必须查线上」特例 |

权威：`HardConstraintsCatalog::mcpOperationalRules()` → `runtime_status_query_local_first`。SSH 主机映射只解决「要连生产时连哪台」，不改变查询目标默认值。

## 会话学习知识库与冲突门禁（`session_learning_knowledge_conflict_gate`，强制）

| 允许 / 必须 | 禁止 |
|------|------|
| 把**可复用、应约束后续工作**的用户立场判为 **知识（学习意图）**；一次性交付任务判为 **需求**；改框架架构/策略的需求落地后可再沉淀为知识候选 | 把所有闲聊当知识，或把明确的长期规矩当一次性需求后忘掉 |
| Cursor/Codex 学习 Hook 写入 Learning SQLite；`prepare_project.agent_guidance.learning_conflicts` 与 `resolve_task_context.rules`（validated / contested / contradiction）视为项目记忆 | 静默覆盖已 validated / contested 知识或未关闭 contradiction |
| 改动将与既有知识冲突时：**停工汇报**（旧规则 vs 新要求、≥2 选项、建议），等用户决策 | 未汇报、未决策就按新说法改掉旧知识 |

权威：`HardConstraintsCatalog::mcpOperationalRules()` → `session_learning_knowledge_conflict_gate`；采集入口见 Cursor `.cursor/hooks.json`（ensure 生成）与 Codex 插件 hooks。

## 宿主编辑器规则（`host_editor_rules_mcp_generated_only`，强制）

| 允许 | 禁止 |
|------|------|
| 规则权威维护在 MCP `hard-constraints.v1` 与 `Ai/Framework/模块 doc/` | Agent **手写/直接编辑** `.cursor/rules/*.mdc`、`.cursorrules`、`CLAUDE.md`、`.codex/*`、`.github/copilot-instructions.md` 等作为规则源 |
| 由 **MCP**（ensure / `HostEditorRulesGenerator`）写出宿主编辑器规则产物（含 `.cursor/rules/weline-mcp-coldstart.mdc`） | 把编辑器私有规则文件当成高于 `prepare_project.hard_constraints` 的权威 |
| `AGENTS.md` 仅作 MCP 接通指针 | 换项目后仍依赖本机/他仓残留的 Cursor/Codex 私有规则 |

原因：换项目后编辑器私有规则会丢失或分叉；只有 MCP + 仓库文档可随项目带走。

## Codex CLI 宿主委派（`host_delegate_explore_plan_review_to_codex_cli`，强制）

| 允许 / 必须 | 禁止 |
|------|------|
| Cursor 等**非 Codex**工程宿主：探测 `CODEX_CLI_PATH` → PATH `codex`（`command -v` / `which`）；CLI 可用则用 **`codex exec`（只读）** 做代码探索并写出详细计划，编码后用 **`codex review --uncommitted`** 审查 | CLI 可用时 Cursor 自行写笼统计划、跳过 Codex 探索/审查 |
| 计划正文仅 **背景 / 方案 / 细节**（`plan_content_focus_only`）；细节须含文件、符号/规则 id、测试断言、验收与 fallback | 另起第二套与 Codex 计划冲突或空泛的计划 |
| Cursor **只按 Codex 计划做编码**；Plan Mode（`host_plan_mode_for_planning`）仅作审批/展示容器 | 把 Plan Mode 当成第二个计划作者 |
| 使用 Codex CLI **默认最新模型**；命令模板见 `agent_guidance.host_codex_delegation` | 写死 `-m` / `--model` 钉旧模型 |
| **用户可见状态（强制）**：启动委派 `codex` 前聊天明示「Codex 正在工作：{阶段}…」；完成写「Codex 已完成」；回退写「Codex 不可用，已回退宿主：{原因}」（见 `user_visible_status`） | 静默跑 `codex`，界面上看起来仍是宿主自己在干活 |
| 与嵌套 MCP planner（`knowledge.codex.enabled` / `CodexInvoker`）**解耦**；不依赖该开关 | 因 `knowledge.codex.enabled=false` 就跳过宿主委派 |
| 当前宿主已是 Codex：原生完成探索/计划/审查 | Codex 宿主再 shell 嵌套 `codex exec`/`codex review`（递归） |
| CLI 缺失/不可执行/鉴权失败/超时/输出不合规：回退宿主 Plan Mode 并**记录原因**（同时发回退可见提示） | 静默跳过探索计划门禁 |
| 内容运营（`content_ops_skills_skip_mcp`）与闲聊豁免 | 对产品优化/写博客等仍强制委派 Codex |

权威：`HardConstraintsCatalog::hostCodexDelegation()` → `prepare_project.agent_guidance.host_codex_delegation`；规则正文 `host_delegate_explore_plan_review_to_codex_cli`。

## MCP 技能（`mcp_skills_fetch_from_mcp`，强制）

| 允许 | 禁止 |
|------|------|
| **编码/工程**技能正文由 MCP `mcp-skills.v1` 提供：`prepare_project.agent_guidance.mcp_skills` → `resolve_skill` → `get_skill` | 把 Cursor/Codex 本地 SKILL.md 当作高于 MCP 的权威（工程技能） |
| **内容运营**（`content_ops_skills_skip_mcp`）：仓内 `doc/ai/skills/*/SKILL.md` + `dev/ai-command` 即为权威；宿主 Store 薄镜像只指路 | 对产品优化/文章创建等任务仍走 prepare / get_skill / 拉全量索引 |
| 宿主 Agent Skills 对工程技能仅作**可选薄壳**（提醒去调 MCP） | 恢复 `knowledge.auto_generate_skills` / 仓库内 Skill 投影 |
| 工程任务文档片段继续用 `resolve_task_context` | 用静态技能文件替代工程 workflow surfaces / 硬约束 |

常用别名（工程 `get_skill(skill_id=…)`）：`weline-theme-development`、`local-browser-urls`、`weline-taglib-first`（映射到对应 surface id）。

模块 doc 技能：`doc/ai/INDEX.json` + `doc/ai/skills/*/SKILL.md` 随仓；内容运营直接宿主 Read；工程侧可由 MCP 只读提取。全量列表：`resolve_skill(list_all=true)` 或指令「提取技能」。打招呼须列技能+指令（`greeting_lists_mcp_skills_and_commands`）。

## MCP 编译（权威落点）

| 产物 | 位置 | 职责 |
|------|------|------|
| `hard-constraints.v1` | `prepare_project.agent_guidance.hard_constraints` + MCP `instructions` preamble | 全局硬约束（由本索引与交付流程编译） |
| `host-codex-delegation.v1` | `agent_guidance.host_codex_delegation`（亦挂在 hard_constraints 包内） | Cursor↔Codex CLI 宿主分工：探索/计划/审查 vs 编码 |
| `mcp-skills.v1` | `agent_guidance.mcp_skills` + `resolve_skill` / `get_skill` | 按任务可拉取的工程技能正文 |
| `session_startup_notices` | 同上 `agent_guidance` | **只指路**，不复制本表细则 |
| `workflow_contract.v1` surfaces | `resolve_task_context` | 按任务下发 Taglib/Theme/Hook 等细则 |
| 宿主 `AGENTS.md` / ensure | 仓库根 / 脚本 | 只负责接通 MCP，不写框架法 |

实现类：`app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php`、`McpSkillCatalog.php`。改本索引或硬约束摘要后，须跑 `php app/code/Weline/Ai/Mcp/tests/guidance-workflow-contract.php` 与 `mcp-skills-catalog.php`。

## 使用方式

1. 从任务描述提取**关键词**（下表第一列）。
2. 阅读对应**必须先读**文档（不得跳过）。
3. 遵守**禁止**列；完成后跑**验证**命令（如有）。
4. 写代码前完成 [扩展点选型](../Framework/doc/3-开发/扩展点选型.md)。

## 工作区不可丢弃规则（`preserve_dirty_workspace`，严重）

MCP 的自愈、宿主重载、插件代次刷新、验证回滚和崩溃恢复，以及普通宿主编辑，都必须保留任务开始前已经存在的 tracked、staged、untracked 与 ignored 脏改。

**宿主 Agent / Shell 同等禁止（硬）**：不得为「对齐 HEAD / 清场 / 方便重做编辑」而对工作区执行 `git checkout -- <path>`、`git restore`、`git reset`、`git clean`、`git stash` 或任何等价擦脏。

**脏改区 dirty-load（硬）**：任何 Write / StrReplace / ApplyPatch 之前，必须先宿主 Read **当前磁盘**工作区文件（以工作树为准）；所有修改必须在该**已存在的脏改内容**上继续编辑。禁止拿 HEAD、索引、对话历史里的旧缓冲、其它会话快照、agent-transcript 摘录或任何更旧基线来当「原文件」再写回——这会导致**会话间相互覆盖**。多 Agent / 多聊天并行时，不得用本会话持有的旧版本覆盖其它会话正在推进的脏文件。Hash 漂移只能 fail-closed 保留磁盘现场，禁止「先还原旧版再覆盖」。丢代码风险优先于操作便利。

MCP 子进程只允许只读 Git 检查，禁止上述全部 Git 写操作，禁止 config/helper/pager 命令注入及 force/discard 变体。分支切换只能由工作区所有者显式执行。

## 任务路由表

| 任务关键词 | 必须先读 | 禁止 | 验证 |
|-----------|---------|------|------|
| **需求澄清、用例规格、EARS、clarify、use case、用户故事、规格澄清** | [需求澄清与用例规格.md](../../../../../dev/ai-command/ai/需求澄清与用例规格.md)；MCP `requirement_clarify_use_case_spec` / skill `requirement_clarify_use_case`（`weline-req-clarify`） | feature 无 `doc/开发/spec/{slug}.md` 就写码；散文验收无 EARS；跳过澄清直接改 PHP | 规格 `status=ready-for-plan`；含澄清记录 + 用户故事 + EARS + UC |
| **计划、架构方案、Plan Mode、SwitchMode、计划模式、简单跳过计划、计划正文聚焦、Codex CLI 委派、codex exec、codex review** | [AI工程交付流程.md](./AI工程交付流程.md) §1c/§3；MCP `host_plan_mode_for_planning` / `plan_content_focus_only` / `host_delegate_explore_plan_review_to_codex_cli` / `requirement_acceptance_always` / `requirement_fe_be_scope_analysis`；`agent_guidance.host_codex_delegation` | 非简单却不切 Plan Mode；**CLI 可用时 Cursor 自写笼统计划**；**Codex 宿主嵌套调自身**；命令写死 `-m`；**跳过计划却不做验收**；有 Web 却无本机 Browser WB-OP；布局/吐槽/审图跳过原型+UI；**计划写无关散文/流程复述把主题带偏** | 默认 `SwitchMode`→`plan`（容器）；Codex CLI 可用→`codex exec` 出三节计划，Cursor 只编码；编码后 `codex review --uncommitted`；simple+理由可 skip；计划正文只写**背景+方案+细节**；Web→WB-OP 视觉+逻辑；审图强制原型+UI 调整 |
| **工程团队、大型团队、子智能体、停工汇报、技术方案会、对齐冻结会、用例冻结、contracts、deps、流水线、问题上报、拉起项目经理、findings_wake_pm、requirement_issuer_owns_acceptance、waiting_acceptance、issuer_acceptance、甩手掌柜、发起方盯验收、SESSION、需求会话、session/{slug}、pm_plan_lifecycle、requirement_session_dashboard、notify_pm、监工、测试账号、admin/admin、框架专席、合规复审、组件协商、验收签收、ui_prototype_gate_before_test、related_web_urls、seat_closed_reports_related_web_urls、一席一智能体、席间互聊、channel、席位专项技能镜、seat_skill_mirrors、API 席、部件开发工程师、widget_development、支付开发工程师、payment_development、万能支付、电商顾问、电商开发顾问、运营策划、活动策划、ecommerce_advisor、电商合规、数据分析、visitor_data_analytics、像素事件、Weline_Visitor、性能检查工程师、performance_check、HotCache、N+1、提示词优化工程师、prompt_optimization、技能引用、技能压缩、翻译工程师、translation_engineer、漏译、i18n 席、主题开发工程师、theme_engineer_for_theme_work、主题开发、theme_development** | [工程团队.md](../../../../../dev/ai-command/ai/工程团队.md)；[templates/requirement-session.md](../../../../../dev/ai-command/ai/templates/requirement-session.md)；[支付开发.md](../../../../../dev/ai-command/ai/支付开发.md)；[主题开发.md](../../../../../dev/ai-command/ai/主题开发.md)；[电商顾问.md](../../../../../dev/ai-command/ai/电商顾问.md)；[性能检查.md](../../../../../dev/ai-command/ai/性能检查.md)；[提示词优化.md](../../../../../dev/ai-command/ai/提示词优化.md)；[翻译工程师.md](../../../../../dev/ai-command/ai/翻译工程师.md)；[像素拓展使用指南.md](../Visitor/doc/像素拓展使用指南.md)；[统一缓存范围与性能优化.md](../Framework/doc/统一缓存范围与性能优化.md)；[AI工程交付流程.md](./AI工程交付流程.md) §3b/§5；MCP `engineering_team_for_new_requirements` / `requirement_session_dashboard` / `pm_plan_lifecycle` / `findings_wake_pm` / `requirement_issuer_owns_acceptance` / `local_dev_test_accounts_self_serve` / `api_rest_in_owning_module` / `payment_engineer_for_payment_work` / `theme_engineer_for_theme_work` / `analytics_engineer_for_visitor_work` / `ecommerce_advisor_for_commerce` / `performance_engineer_for_design_and_review` / `prompt_engineer_for_skill_prompt_work` / `translation_engineer_for_i18n_work` / skill `engineering_team`（`weline-engineering-team`）/ `api_sdk_development`（`weline-api-sdk`）/ `payment_development`（`weline-payment-development`）/ `theme_development`（薄；权威复用 frontend_development）/ `visitor_data_analytics`（`weline-visitor-analytics`）/ `ecommerce_advisor`（`weline-ecommerce-advisor`）/ `performance_check`（`weline-performance-check`）/ `prompt_optimization`（`weline-prompt-optimization`）/ `translation_engineer`（`weline-translation-engineer`）/ `engineering_team_bundle.seat_skill_mirrors` | 复杂需求父会话扮演多席；简单需求打 `Team:`；内容运营打这两种前缀；重大架构矛盾不停工；**开发完才补主路径用例**；无 contracts/deps 就开施工；**无 SESSION 就施工 / 专席改 SESSION / 未完成清单非空却宣称完成**；**交付不 notify_pm / PM 未 DoD 检查就关计划项**；**伪造多席对白不走 channel/resume**；**向用户要本机账号密码**；触发专席跳过合规复审；涉 UI 无 UI/原型实质签收就交出；未协商就扩展组件；**只发通用骨架不粘贴该席技能镜 / 席位未 get_skill 本席文档就写码**；**跨模块代写 Rest**；**支付域只派后端/Provider 不上场支付开发工程师**；**Theme Token/壳只派前端/UI 不上场主题开发工程师**；**Visitor/像素只派前端/后端或当成框架事件席**；**热路径/缓存却不上场性能检查工程师或建议平行进程内袋**；**改技能/提示词/席位镜却不上场提示词优化工程师或整段复制技能正文当引用**；**新文案/漏译却不上场翻译工程师或跳过 collect**；**找出问题不 escalate 拉起项目经理**；**项目经理收到 escalate 却不同回合组队或不落 SESSION 计划项**；**发起方发完 escalate 就甩手 / 不读 PM 汇报 / 不写 issuer_acceptance**；**缺发起方签收却关项或汇审宣称完成**；**席位 closed 不报 related_web_urls / PM 完成汇报省略交付地址** | 简单→`监工:`；复杂父会话仅 `Team:项目经理:`；每席真实子智能体；席间 `channel/{thread}.md`+resume 互聊；**立项建 `session/{slug}.md`**；**每席 seat_skill_mirrors**（含 主题开发工程师→frontend/theme_development）；发现问题立刻 escalate `@项目经理：请立刻组队解决`；PM 同回合组队+记 SESSION 计划项；**发起方 waiting_acceptance 盯验收；进度节点 resume + issuer_acceptance=pass 才关项/汇审**；对齐冻结会钉 UC+`contracts.md`+`deps.md`；验收 `acceptance-ui`+`acceptance-prototype`；**closed 填 related_web_urls → PM「交付地址」汇总**（见 `feature_delivery_urls`）；纪要 `doc/开发/team/{slug}/`；本机后台默认 **admin/admin**，前台自建 |
| **REST、Api/Rest、AbstractRestController、BackendRestController、BinQuery、QueryProvider、query-bin、/bin/query、w_query、API SDK、公开 API 契约、跨模块 API、权限旁路、默认拒绝** | [API接口开发规范.md](../Framework/doc/3-开发/API接口开发规范.md)；[BinQuery/Provider开发指南.md](../Framework/doc/BinQuery/Provider开发指南.md)；[工程团队.md](../../../../../dev/ai-command/ai/工程团队.md) API 席；[api-seat-charter](./开发/team/api-seat-charter/meetings/align-freeze.md)；MCP `api_rest_in_owning_module` / skill `api_sdk_development`（`weline-api-sdk`） | **在 I18n 写 Website API**；跨模块代写 Rest；壳内重写 Provider 业务；靠 Attribute 默认暴露；缺 `auth` 当已鉴权；REST 有 Acl 而 query-bin 无 `backend_acl`；写操作 external 无 Key；CDN 公开敏感读；改 Rest/BinQuery 不上场 API 席；缺 `@Document`/Acl；站内业务手写 REST/native fetch；BinQuery 未 compile / 不可 `query:help`；只改代码不更文档 | **一律 QueryProvider**；Attribute **默认拒绝**（`external/frontend=false`）；公开须显式 opt-in+`auth`；薄 REST=`#[Acl]`+`w_query`；每入口独立权限矩阵；Rest **只落归属模块**；`Team:API:` 双轨；compile+query:help；文档同变更集 |
| 规格修复、技术细节补全、listing 规格缺失、PDP 只有尺码/类型 | [dev/ai-command/product/规格修复.md](../../../../../dev/ai-command/product/规格修复.md)；`Product/scripts/remediate-product-listing-spec-attrs.php`；**跳过 MCP**（`content_ops_skills_skip_mcp`） | 只口头解释不扫库；无快照伪造属性；改 combination_key/轴矩阵冒充补全；**修完不报明细、不给每品交付地址**；对本任务 prepare_project | `php app/code/Weline/Product/scripts/remediate-product-listing-spec-attrs.php --scan --dry-run` → `--apply`；汇报含修复表 + 每品 Markdown URL；Browser PDP 技术细节 |
| **产品优化 / 商品优化（父）含①图②详情③翻译；PDP `/product/` URL** | 父：[产品优化.md](../../../../../dev/ai-command/product/产品优化.md) + `Product/doc/ai/skills/ecommerce-product-optimize/`（**并行 3 子智能体 + 审查#1/#2**）；① `ecommerce-product-image`+`weline-image-pipeline.md`；② [详情优化.md](../../../../../dev/ai-command/product/详情优化.md)+`ecommerce-detail-suite`；③ [翻译优化.md](../../../../../dev/ai-command/product/翻译优化.md)+`ecommerce-product-i18n`；**跳过 MCP**（`content_ops_skills_skip_mcp` / `product_optimize_triggers_detail_suite`） | 把父与任一子写成同义词；父未开满 3 智能体；子回报完成即交付（跳过双轮审查）；FAIL 不点名返工；只做详情/`--skip-images` 却声称完成；裸 `/product/` 无意图全跑；主图未 1:1 / 启用语漏译却整单跳过；**对本任务 prepare_project** | 父→并行①②③；**审查#1→点名返工→审查#2** 两轮均 PASS 才收口；①锁 AR；②`data-weds`；③启用语真译+`/{locale}/product/`；类审 0 BAD；Browser 禁缓存 |
| **新建文章 / 写博客 / 审查文章 / 文章可行性 / 精写文章 / blog article** | [新建文章.md](../../../../../dev/ai-command/blog/新建文章.md) + `Blog/doc/ai/skills/weline-blog-article/`；**跳过 MCP**（`content_ops_skills_skip_mcp` / `blog_article_methodology_gate`） | 无来源编造工艺；AI 图冒充实物；只译 en_US；文化长文塞 CMS；入口仍 `/search?q=`；改 Catalog 不清主题缓存；审查只点评不修（用户已要求修时）；对本任务 prepare_project | create：查证→配图→zh/en→默认站全语种→`blog/{slug}`+`@url`；review：checklist 可发/需补/不可发；Browser 禁缓存 |
| **壳+Provider、万能支付、货源代发、对接供应商/支付方式、业务写在 Controller、支付开发工程师、退款退货资金面、支付真浏览器闭环** | [支付开发.md](../../../../../dev/ai-command/ai/支付开发.md)、[Payment/payment-shell.md](../Payment/doc/payment-shell.md)、[Payment/provider-development.md](../Payment/doc/provider-development.md)、[Dropship/dropship-shell.md](../Dropship/doc/dropship-shell.md)、[Dropship/provider-development.md](../Dropship/doc/provider-development.md)；MCP `shell_provider_business_isomorph` / `payment_engineer_for_payment_work`（**强制**）；skill `payment_development`（`weline-payment-development`） | 在壳 Controller/Service **重写**某供应商 API/凭证解析/履约/目录/支付生命周期；为每个供应商新写一套业务控制器；绕过 Provider 接口硬编码网关；**支付域只派后端/通用 Provider 不上场支付开发工程师**；壳碰卡号；密钥明文；回调非纯函数；**只改代码不拉测试席 / 不用真浏览器过触及支付全流程就宣称过手** | Extends Provider 一文件按能力接口实现；壳只编排；对接交付=Provider+配置模板；支付施工 `Team:支付开发工程师:` 双轨；退款走 Provider.refund+壳 Ledger；**改完拉起 `Team:测试:` 真 Browser 过方法全流程 + 证据才 review pass** |
| `.phtml`、模板、Taglib、`<w:` | [Taglib/doc/README.md](../Taglib/doc/README.md)、[场景映射表.md](../Taglib/doc/场景映射表.md)、[如何自定义Tag.md](../Taglib/doc/如何自定义Tag.md) | 手写领域 select/input；`w:*` 属性内 `<?=` / `<?php`；**Taglib callback 返回 HTML 里写裸 `@static(...)`** | — |
| **配置、统一配置、统一配置中心、系统配置、嵌入配置、配置嵌入、`<w:config:*>`、SystemConfig、Weline_SystemConfig** | [SystemConfig README](../SystemConfig/doc/README.md)、[config-embed标签使用指南.md](../SystemConfig/doc/config-embed标签使用指南.md)；MCP `systemconfig_unified_config_terms` / `SystemConfigTermRouting` | 自造业务配置表/私有 Config Service/平行设置页；把「配置」落到 MCP host/`env.php`/模块 `etc` 语义而忽略统一配置中心 | 业务配置走 SystemConfig；业务页用 `<w:config:embed>`；检索词表命中 SystemConfig 文档 |
| **范围、Scope、配置范围、继承、网站/店铺/渠道、target_scope、`<w:scope>`、路径过滤** | [store-saleschannel-scope.md](../Websites/doc/store-saleschannel-scope.md)；[SystemConfig README 继承](../SystemConfig/doc/README.md)；MCP `weline_business_scope_hierarchy`；后台范例 Shipping `scope-toolbar` / Visitor 事件供应商 | 把「范围」当成 URL 路径通配；忽略 **channel←store←website←global** 继承；把站店渠塞进 `path_include`/`scope_json`；手写站店渠 `<select>` 替代 `<w:scope>`；跨模块依赖 ShippingConfigScopeService | 工具条左上 `<w:scope>`；文案含继承；路径控件为「路径过滤」 |
| **`<w:config:embed>`、配置嵌入、没有这个字段、undeclared、业务页 SystemConfig** | [config-embed标签使用指南.md](../SystemConfig/doc/config-embed标签使用指南.md)；范例 Affiliate/B2B/Dropship `Backend/Config` | embed `field`/`fields` 自造短名或与 `<w:config:field key>` 不一致；未先写 Extends 模板就 embed；与 Affiliate「分销」配置混用货源键 | embed 字符串≡声明 key；先声明再消费；对照 Affiliate/B2B 页壳 |
| 图片、`<img>`、`file:image`、CLS、宽高比 | [file-image-cls-尺寸与响应式.md](../FileManager/doc/file-image-cls-尺寸与响应式.md)；[选图与出图分工](../FileManager/doc/file-manager-选图与file-image出图.md)；MCP `image_explicit_width_height_css` | 只写响应式 CSS 不写 HTML width/height；裸 `<img src>` 替代 `<w:file:image>`（动态业务图）；把 `file:image` 当选图器 | 源码含 width/height 或 aspect_ratio；主题 `.w-file-image` / foundation `height:auto`；选图用 `<w:file-manager>` / `WelineMedia` |
| 选图/上传/AI 加图/媒体引用身份 | [media-reference-identity-protocol.md](../FileManager/doc/media-reference-identity-protocol.md)；skill `media-reference-identity`；MCP `media_reference_identity_protocol` | 手拼 identity_path；合成 `scope~sku`；换图物理删文件 | `w_scope` / `window.w_scope`；换图只卸引用；`resource.scope` 可选（CLI 必传） |
| 注释、文件头、`<?=` / `<?php` 开标签 | 本文；MCP `no_php_tags_in_comments`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) | **注释**（`//` `#` `/* */` `/** */` `<!-- -->`）内出现 `<?=` / `<?php` / 短开标签；文件头残留生成器短回显；用 `<?php /* ?>…<?=…*/` 包死代码 | 检索注释内开标签；**非**禁止 `// $x = 1;` 这类普通注释掉语句 |
| 注释语言、代码风格、可读性 | 本文；MCP `chinese_comments_friendly_style`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) | 新增说明性注释/PHPDoc 却用英文堆砌；过度巧妙/过度抽象/工业式套话导致难读；无视周围既有风格 | Diff 抽查注释语言与命名/控制流可读性 |
| `@static`、静态资源 404、`/@static(` | [Framework/doc/static-resource-versioning.md](../Framework/doc/static-resource-versioning.md)、[09-static标签](../Framework/doc/4-内置标签/09-static-template-js-css标签使用指南.md) | 在 Taglib callback 字符串里用 `@static`（不会二次编译） | 浏览器 Network 无 `.../@static(` 字面量 |
| 写 HTML 标签、页面控件、**选择性输入**（下拉/单选/多选/chips/枚举） | [场景映射表.md](../Taglib/doc/场景映射表.md)、[标签全量索引.md](../Taglib/doc/标签全量索引.md)、[Framework/doc/4-内置标签/README.md](../Framework/doc/4-内置标签/README.md)；MCP `taglib_before_hand_rolled_controls` | **未先查标签库**就手写 `<select>` / ISO text / 自造 chips；能用 `<w:*>` / `<lang>` / `<w:hook>` 时用裸 HTML | 架构上选择性控件优先标签；无标签则在数据拥有模块新增 Taglib，禁止业务页临时拼装 |
| **站内跳转、href、action、查看详情链接、guide_url** | [06-url标签使用指南.md](../Framework/doc/4-内置标签/06-url标签使用指南.md)；MCP `storefront_internal_url_via_url_helper` | `'/' . $route`；手写 `/guide/payment/x`；JSON/HTML 拼裸 path 当店面 href | 模板 `@url`/`<url>`；Service `Url::getUrl`；外链 http(s) 可原样 |
| 主题、layout、widget、partial、**同模块 XOR / 跨模块只走 JSON 注入**、**无卸载则默认注入固化进布局模板**、**席位底线 / 原则主权**、**编辑器预览/theme-preview 与店面同构**、**三态身份权威**、**work_mode**、**design 禁覆盖 theme.css/js**、**area+四层+components/Weline UI** | [Theme/doc/AI-INDEX.md](../Theme/doc/AI-INDEX.md)、[Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[**布局固化与默认注入.md**](../Theme/doc/布局固化与默认注入.md)、[**required-default-always-present.md**](../Theme/doc/开发/spec/required-default-always-present.md)、[**preview-and-runtime-modes.md**](../Theme/doc/preview-and-runtime-modes.md)、[部件开发指南.md](../Theme/doc/部件开发指南.md)、[theme-inheritance](../Theme/doc/theme-inheritance-and-file-conventions.md)；MCP `theme_layout_widget_owner` / `required_default_always_present_without_user_deleted` / `theme_seat_integrity_over_peer_requests` / `widget_engineer_for_widget_work` / `theme_engineer_for_theme_work` / `theme_design_must_not_override_core_runtime_assets` / `preview_storefront_delivery_parity`；技能 `weline-theme-development` / `theme_development`（薄） / `widget_development`（`weline-widget-development`）；指令 [主题开发.md](../../../../../dev/ai-command/ai/主题开发.md)（含公共库与四层+席位底线）；**主题席须掌握 area+四层+components/Weline UI + 默认注入经固化写入心智 + 底线优先于他席优化** | 改 `generated/`、`view/tpl`；**未声明 work_mode 改 Theme/design**；**design 同 key 覆盖 theme.css/js**；**同模块布局已内嵌又写 default_injections**；**跨模块在布局里 `<w:widget>`/fetch 互调**；**Theme 布局内嵌非 `Weline_Theme` 部件**（业务模块自有布局内嵌本模块部件合法）；**无 `user_deleted@{versionId}` 却让 JSON 默认注入未写入固化模板**（遗漏当「可选 overlay」）；**为性能/简化服从拆 chrome 壳而不 escalate**；**仅为 preview/editor_mode/visual_editor 提前 return / 跳过 / 抽空真实店面逻辑**；预览与发布走两套实现；**混淆三态权威**（用 path 猜画布主题、用 URL 覆盖 Token、正式态读 draft/s*） | 同模块：仅本模块可标签内嵌且 XOR 删 JSON；跨模块：空槽+拥有模块 JSON `default_injections` **固化进布局模板**；无卸载+有槽→必须固化（与主题/版本无关）；无模板→激活主题运行期动态固化；插件注入→全主题重固化涉及布局；冲突拆壳→refuse+escalate；部件相关派 **部件开发工程师**；Theme Token/layout壳/预览三态/`app/design` 派 **主题开发工程师**（先声明 `work_mode`；`theme_engineer_for_theme_work`）；`php bin/w frontend:check-theme-layout-widgets`；**预览与真实同一套业务逻辑**；身份：可视化=**参数**、版本真实预览=**Token 反解析**、正式=**RequestContext**；禁止为安静画布丢逻辑 |
| 前台部件 JS、`Weline.declare`、`data-weline-load`、`weline.modules.js`、**部件静态 `layout-source`/`source` bake→指定位置** | [前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md)、[部件静态资源固化规范.md](../Theme/doc/部件静态资源固化规范.md)、[Theme.js使用指南.md](../Theme/doc/Theme.js使用指南.md)、[Theme开发总指南.md §2.6](../Theme/doc/开发/Theme开发总指南.md)；MCP `theme_js_module_declare_only` / `widget_static_assets_bake_to_head` / `weline_js_loader_framework_only`（**强制**） | 部件/布局手搓 `<script src="@static(...js)">` / 裸 `<js>` 直拉**业务模块**；不注册 modules 就加载；**改登记不 `resource:compile welineModules`**；**在 `weline.js` 写死业务名**；任何部件内联 CSS/可执行 JS（含 style= 与 on*=）或模板裸资源标签；跳过 bake 闭包 | 业务模块：`declare` + `data-weline-load`；静态文件：`layout-source`/`source` → bake → 指定位置 去重；改模块后 `php bin/w resource:compile welineModules` |
| 前端 UI、Weline UI、CSS 变量、地址表单、结账样式、**后台 admin 页** | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md)、[theme-semantic-color-matrix.md](../Theme/doc/theme-semantic-color-matrix.md)、[场景映射表.md](../Taglib/doc/场景映射表.md)；**MCP** `get_skill(weline-theme-development)`；MCP `weline_ui_theme_first` / `theme_base_components_token_only` / `ui_skill_requires_theme_skill` / `backend_admin_ui_requires_frontend_theme_skills` | 第三方 UI（Bootstrap/Element/Ant）；硬编码色值/间距；**基础组件（`w-button`/`w-input`/`w-alert` 等）私写 hex/rgb 或平行色变量**；手写国家/省/市 input 替代 `<w:theme:address>`；**只用 frontend-design/UI/原型技能却不从 MCP 取主题技能**；自造 hex/rgb 色板或 px 间距阶梯；**后台交裸 h1+form/table 不套 w-backend-page/w-card** | 对照主题组件类名与 Token；Browser 多断点；后台对照 Marketing/Smtp 既有页 |
| UI 技能、frontend-design、审美/配色、自造设计系统 | 同上；**必须先** `get_skill(weline-theme-development)` + Theme Token 文档 | 按通用 UI 技能发明私有 palette/spacing/radius/shadow；用 UI 技能覆盖主题 Token；给基础组件另写颜色；把宿主 `SKILL.md` 当权威 | 主题 Token 优先；UI 技能仅构图/层次/文案；基础组件只消费 `--weline-theme-*` / `--color-*` |
| 审图、截图、发图审查、**用户附图/粘贴图**、**截图不说**、**线稿/抽取线图**、**原型调整**、**有图=视觉UI+原型必上** | [dev/ai-command/theme/审图.md](../../../../../dev/ai-command/theme/审图.md)；**MCP** `user_image_attachment_triggers_shentu` / `ui_skill_surface_signal_gate` / `image_attachment_shentu_bundle`；**任意附图即审**（含后台/CMS/错误页，不必再说「审图」）；**web_ui 附图强制 participate + 原型上场**（复杂 team 席位 原型+UI+前端+主题）；**非报错 `ui_shot` 默认=改 UI**（禁止只点评）；**同回合联合**：线稿抽取 → `prototype` 调整 → `frontend-design` → `weline-theme-development` CSS/Token；`error_shot` 优先修异常且错误页可读；缺 UI/原型技能须提示并自行装入 Agent Store；fail 必须改到 pass | 非本仓前端图仍套店面修复；`ui_shot` 只点评不改；跳过线稿/原型直接糊 CSS；把产品 UI 截图当闲聊插图；有图却 `ui_skill_decision=skip` 或不派原型；缺技能仍写 E/F pass | 分流（含 error/ui）+ 线稿 + 原型调整 + 技能门禁 + 检查清单；Browser 验收 |
| 地区筛选、国家/省/市/区选择、地址多选 chips、系统禁运新增国家 | [场景映射表.md](../Taglib/doc/场景映射表.md)；MCP `theme_address_for_region_pickers` / `taglib_before_hand_rolled_controls` | 手写国家/地区 `<select>`；**手写 ISO 国家码 text input**；自造筛选芯片行；绕开 `<w:theme:address>` 的级联 input | 列表/表单筛选用 `selection=single\|multi`；仅选国可用 `levels=country`；chips/菜单走标签与浮层内核 |
| 浮层、下拉、menu、popover、tooltip、combobox、dialog、选品/媒体/图标选择器、anchored-float、边界翻转、portal、**MCP/Agent 前端弹出** | [Theme开发总指南.md §通用浮层](../Theme/doc/开发/Theme开发总指南.md)、[anchored-float.md](../Theme/doc/widgets/anchored-float.md)、MCP `weline_ui_floating_primitives` | **手写 `left/top`**；自研 flip/边界检测；私有 portal/modal 栈；产品/媒体选择器内嵌窄侧栏自造浮层；地址多选菜单绕开 `UI.floating.attach` / `Weline.UI.dialog` | 对照 `data-w-component=dialog\|menu\|…` / `UI.dialog.open` / `UI.floating.attach`；Browser 底部展开上翻 |
| 版心、内容区宽度、页面容器、`.w-container`、`max-width`、gutter | [theme-layout-content-width.md](../Theme/doc/theme-layout-content-width.md)；MCP `frontend_unified_content_container` | **自写一套页面容器**；`1440px`/`1200px`/`1180px`/`90rem` 私有壳；已在 `.w-container` 内再写 `max-width`+`padding-inline`（双重 gutter）；`var(--weline-layout-content-max-width, 1440px)` | ThemeFrontendLayoutsContentWidthContractTest / ThemeStorefrontModuleContentWidthContractTest；对照顶栏左右沿 |
| section、`weline-code` | [frontend-section-weline-code.md](../Theme/doc/frontend-section-weline-code.md) | 缺/空 `weline-code`；无语义名如 `section1`；同文件重复 code | `php bin/w frontend:check-section-code` |
| 前台文案、翻译、i18n、**前端开发语言** | [Theme开发总指南.md §4.1](../Theme/doc/开发/Theme开发总指南.md)、[01-lang标签](../Framework/doc/4-内置标签/01-lang标签使用指南.md)、[模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md)「前端开发成员约定」；MCP `module_i18n_chinese_source_default` / `frontend_ui_requires_zh_en_csv` / surface `frontend_development` | `.phtml` HTML 内 `__()`；**英文当默认源串**；改文案不写中英 CSV；把双语当成 CSS 或改模板语言 | 源串默认简中（开发语言）；双语靠 zh/en CSV；`i18n:collect` |
| **定时翻译进度、队列/cron 是否在跑、翻译记录有没有新增、运行状态查询** | 本文 `runtime_status_query_local_first`；MCP `hard_constraints.mcp_operational` | **未明示就查生产/SSH weline**；把 SSH 默认 profile 当默认查线上；「翻译特例必须查生产」 | 默认查本机 DB/进程；仅用户说线上/生产/ssh weline/aiweline.com 才查远端 |
| i18n CSV、中英翻译、collect | [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md)；MCP `module_i18n_csv_collect` / `module_i18n_chinese_source_default` / `active_locale_must_show_target_language` / surface `module_i18n_csv` | **模板/菜单英文当源串**；只改 CSV 不 `i18n:collect`；缺 en_US；**en_US 第二列仍为中文 source 占位**；**默认/活跃语非中文却仍显示中文**；前后台词条不对齐 | 源串默认中文（正确）；非中文 locale 必须显示对应译文；`php bin/w i18n:collect Weline_Module`；交付前抽检活跃/默认 locale 用户可见词条 |
| **用户提到「翻译」**（含附图漏译 chrome） | 本文 + [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md)「默认网站全语种」；MCP `user_mentions_translation_all_default_website_locales` | **只译 en_US**；不查默认网站 `language_codes`；非中英语种写进模块 CSV；非中文 locale 译文仍是中文 source | 本地 DB `WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)`（website_id=0）对**每一个已选语种**写真实译文：模块 CSV **仅** `zh_Hans_CN`/`en_US`，其它语种进**系统词典**；中英改完后 `i18n:collect`；用户可显式收窄语种；未要求则不启动 Ollama |
| 新建 Hook、`view/hooks` | [Hook创建规范.md](../Hook/doc/Hook创建规范.md)、[Hook使用指南.md](../Theme/doc/Hook使用指南.md) | 只有 `.phtml` 无 `hook.php` + `doc/hook/*.md`；**type 段发明 `theme-editor`/`checkout` 等功能名**（须 `partials` 或 `layouts`） | `php bin/w setup:upgrade --route` |
| **新建 Event、Observer、`event.xml`** | [事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md)、[event/README.md](../Framework/doc/event/README.md) | 发明未文档化事件名；跨模块直调 Service | 检索 `doc/event/` 与 dispatch 一致 |
| **数据分析、像素事件、WelinePixel、weline-pixel、Visitor 报表、PixelEventVendor、访客事件、沙盒监视、转化去重、事件链** | [像素拓展使用指南.md](../Visitor/doc/像素拓展使用指南.md)；[像素事件供应商管理-定稿合同.md](../Visitor/doc/像素事件供应商管理-定稿合同.md)；[事件链注册.md](../Visitor/doc/event/事件链注册.md)；[conversion-event-dedupe.md](../Visitor/doc/开发/spec/conversion-event-dedupe.md)；[工程团队.md](../../../../../dev/ai-command/ai/工程团队.md)；MCP `analytics_engineer_for_visitor_work` / skill `visitor_data_analytics`（`weline-visitor-analytics`） | **只派前端/后端代写 Visitor**；把像素当成框架「事件」席或把 Visitor `event.xml` 甩给事件席；旁路 dataLayer/私接三方；开 GTM 仍开 GA4 直连；站店渠与路径过滤混淆 | `Team:数据分析:` 双轨；整模块归属（含本模块像素桥接 Observer）；禁止产品混岗；GTM/GA4 互斥；`<w:scope>`≠`scope_json`；Vendor+`cspDirectives`；技能引用 frontend+taglib |
| 跨模块读/写 | [扩展点选型.md](../Framework/doc/3-开发/扩展点选型.md) | 跨模块 `new` 对方 Service/Model | — |
| 浏览器 AJAX、表单提交、地址/地区等业务数据 | [Weline.Api使用指南.md](../Frontend/doc/Weline.Api使用指南.md)；[BinQuery/README.md](../Framework/doc/BinQuery/README.md)；Theme `browser_api_binquery_default` | 原生 `fetch` / `axios` / `$.ajax` / XHR；手写 query-bin；**HTTP 主路径 + BinQuery 回退** | — |
| **document/body 级 MutationObserver、delivery_storm、DOM 监听** | [DOM-Mutation观察总线.md](../Frontend/doc/架构/DOM-Mutation观察总线.md)；[前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md) §1.2；MCP `dom_mutation_observe_via_weline_dom` | 对 `document`/`documentElement`/`body` **裸 `new MutationObserver`**；双 rAF/`queueMicrotask`/仅 `requestIdleCallback({timeout})` 立刻 re-observe；把 DEV 截断当修法 | **唯一入口** `Weline.dom.observe`（共享总线）；安静窗 `setTimeout`；DEV 看门狗仅护栏 |
| 后台页面、Toast、Confirm | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；**MCP** `no_native_js_dialogs`（独立高压线，勿埋进 `no_generated_no_routes_xml`） | JS `alert` / `confirm` / `prompt`；业务页用原生对话框代替 `Weline.UI.toast` / `Weline.UI.dialog.confirm` / `Theme.Notice` | 契约断言模板/脚本无 `window.alert(` / `window.confirm(` / `window.prompt(` |
| 缓存、HotCache、WLS、CachePool、进程内 memo、清理不同步、**性能检查、慢请求、TTFB、N+1、冷启动** | [统一缓存范围与性能优化.md](../Framework/doc/统一缓存范围与性能优化.md)、[性能检查.md](../../../../../dev/ai-command/ai/性能检查.md)、[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；MCP `performance_engineer_for_design_and_review` / `theme_seat_integrity_over_peer_requests` / skill `performance_check` | **业务类再自做一层进程内缓存**（绕开 CachePool/Adapter）；读写/清理 **key 不一致**；清理只清驱动不清进程内（或反之）；可变 Model/个性化 HTML/草稿/未提交事务读进共享缓存；**热路径设计不上场性能检查工程师**；**查出问题不立刻 escalate 拉起项目经理**；跨样本伪加速比；**把拆 header/footer/必装 widget 当优化药方** | 可缓存事实走 Framework **缓存类**：进程内存储 → 驱动存储；**同 key** 读写与清理同步；单次读命中进程内则不再打驱动。`cache_lookup_tier_process_shared_db` **已取消**（勿再当 MCP 硬规则要求业务类自做三层）。复杂 team 热路径须 `Team:性能检查工程师:` 设计检查+开发后复审；fail/缺口须立刻 escalate `@项目经理：请立刻组队解决`；优化方向禁拆 chrome 壳 |
| ORM、Model、Schema | [模块版本与升级门禁.md](../Framework/doc/3-开发/模块版本与升级门禁.md)、[模块开发完整指南.md](../Framework/doc/3-开发/模块开发完整指南.md) | 改 Model/Controller 不 bump `etc/module.php` version | `php bin/w setup:upgrade -m Weline_Module` |
| 新建 Controller、路由、后台链接 | 同上 §控制器；**URL 动作为 `edit`/`add`/`save`，禁止写成 `getEdit`/`getAdd`/`postSave`** | 把 `get*`/`post*` 方法前缀拼进 URL | `php bin/w setup:upgrade --route`；对照 [03-自定义控制器.md §HTTP方法](../Framework/doc/2-快速开始/03-自定义控制器.md) |
| 交付、验收 URL、Browser 自测、**自行验证**、**功能 e2e**、**章通路+计划组套件**、**禁止甩测给用户**、**禁止问用户要本机账号**、**e2e 默认无头**、**仅正式 Playwright runner**、**测试必须真**、**禁止自造数据自验通过**、**抹掉自动化标志**、**操作员 Browser 非抢占后台** | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)、[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)、[AI工程交付流程.md](./AI工程交付流程.md) §6–§7；[工程团队.md](../../../../../dev/ai-command/ai/工程团队.md)；MCP `agent_self_verify_before_done` / `browser_operator_self_test` / **`browser_operator_non_preemptive`** / **`ui_feature_requires_e2e`** / **`plan_full_pathway_e2e_suite`** / **`acceptance_real_business_pathway`** / **`tester_tests_must_be_real`** / **`browser_strip_automation_flags`** / **`forbid_user_manual_test_handoff`** / **`local_dev_test_accounts_self_serve`** / **`e2e_playwright_headless_default`** / **`e2e_playwright_formal_runner_only`** / `feature_delivery_urls` / `closeout_delivery_reminder` / `browser_cache_disabled_on_open` / `browser_release_after_delivery` | **只改代码不跑 UT/RT/WB**；无 evidence 标 acceptance passed；单测/curl/**仅 CDP** 冒充 e2e 完成；**壳层冒烟（空取消页 CTA / 账户页不 fatal）冒充真实通路却无 order_uuid 等证据**；**自造假数据/假响应再断言假数据宣称 pass**；**带着 `navigator.webdriver`/AutomationControlled 去点人机验证再把 reCAPTCHA 拦截当 pass**；**默认 `position:active` 抢占 IDE/对话焦点**；**请用户测试/刷新再试**；**向用户要本机账号密码**；**只做部分章节不测就汇报完成**；省略「交付地址」；臆造路由；写死某一 IDE Browser；**主 Host 用 `*.weline.test` 或在有 `*.test.weline.com` 时强行 `127.0.0.1`**；**带着默认缓存验本回合静态资源**；**写完交付地址仍不关验收 Browser**；**任何 feature 无章通路 `type=e2e` / 无 `e2e-plan-suite` / 未跑 `php bin/w e2e:run` PASS / 用 skipped 冒充**；**Agent 跑 e2e 默认 `--headed` 弹 Chromium 干扰桌面**；**`node -e`/`chromium.launch` 探活挂后台留下 chrome-headless-shell** | 实现后 Agent **亲自**按验收层级验证；`passed/skipped/na` 须带 evidence；**feature 每章通路 e2e + 计划组套件必须 status=passed**；**测试必须真**（经真实业务栈产生可独立回查证据；禁止自造数据自验通过）；**开验收 Browser/e2e 须抹掉自动化标志**（`browser_strip_automation_flags`）；**WB-OP 默认非抢占后台**（`browser_operator_non_preemptive`：省略 `position`；禁止默认前台抢焦点；后台≠免测）；**e2e 默认无头**（勿加 `--headed`/`--ui` 除非用户要求观看）；**仅 `php bin/w e2e:run` / `npx playwright test`**（禁止临时 Playwright 探活）；宿主可用真实 Browser：**打开即禁用缓存+抹自动化标志**后跑用例 + curl 探活；**任何 feature 须 Playwright 章 e2e + 收口组套件 PASS，禁止甩测给用户**；本机后台默认 **admin/admin**、前台自建；本机主链默认 `{project_hash}.test.weline.com`；汇报「交付地址」后立即关闭本回合验收标签 |
| Cursor 调试 ingest、`127.0.0.1:7277`、`#region agent log`、CSP `connect-src` 拦截调试 | [安全响应头策略.md](../Framework/doc/3-开发/安全响应头策略.md)「开发态 CSP 工具链片段」；MCP `cursor_debug_csp_developer_tooling` | 把 Cursor localhost 写进 Framework `SecurityHeaderDefaults` / Extends `Security/Csp` 应用默认；生产基线永久放行 7277；依赖调试记录却不配 Env | 本机 `app/etc/env.php` → `security.headers.csp_developer_tooling` = `connect-src http://127.0.0.1:7277 http://localhost:7277`；仅 DEV/DEBUG 响应时 union；控制台不再 CSP 拦 ingest |
| 多 todo 计划收口、进度汇报 | [AI工程交付流程.md](./AI工程交付流程.md) §7；MCP `plan_todo_evidence_closeout` | 计划未逐项举证就宣称「已完成」；Cursor todo 无证据标 completed；隐瞒未清库/未删代码/未跑 Factory Reset | 对每个 todo 给出路径/DB/命令/Browser 证据；部分完成须列「未完成清单」并写入 `doc/开发日志.md` |
| 计划合规审核、章节 e2e 闭环、计划组套件、串行进度 | [AI工程交付流程.md](./AI工程交付流程.md) §3–§6；MCP `task_plan_compliance_review` / `chapter_ut_rt_wb_dl` / `plan_full_pathway_e2e_suite` | 计划不审架构/解耦/电商合规/原型/e2e/体量/闭环；多任务无章节；章节无 `acceptance_ids`；多章共用同一 e2e；缺 `e2e-plan-suite`；未完成绑定验收就标 done/开下一章；并行多个 in_progress；半截汇报/甩人测 | `dev_tasks.acceptance_ids` 硬绑定；feature 章独立通路 e2e + 收口组套件；passed+evidence 后才 done；收口自检 `compliance_dimensions` |
| 写码前计划、TDD、**需求澄清/用例规格(EARS+UC)**、**宿主 Plan Mode（简单可 skip）**、**工程团队（非简单；一席一智能体；席间 channel+resume 互聊；框架优先/全专席双轨/组件协商/UI·原型先审过签才测；简单与内容运营免除）**、**计划正文仅背景+方案+细节**、**需求必验收/本机 Browser**、**前后端范围分析**、框架审视纠偏、功能判定/隐形需求分析/横切触点清单/原型·UI 按分析决策、视觉 UI 信号强制 participate、加功能先审当前图、功能页分组强制顶部 Tab/展开卡片、验收审图、结束汇审、架构层映射需求、结构化 architecture_design、framework_candidates、框架解耦/禁止耦合 | [AI工程交付流程.md](./AI工程交付流程.md) §1–§4 / §6–§7；[需求澄清与用例规格.md](../../../../../dev/ai-command/ai/需求澄清与用例规格.md)；[扩展点选型.md](../Framework/doc/3-开发/扩展点选型.md)；MCP `requirement_clarify_use_case_spec` / `host_plan_mode_for_planning` / `engineering_team_for_new_requirements` / `plan_content_focus_only` / `requirement_acceptance_always` / `requirement_fe_be_scope_analysis` / `requirement_framework_scrutiny` / `requirement_feature_kind_gate` / `requirement_implicit_analysis_skill_decision` / `requirement_cross_layer_impact_gate` / `ui_skill_surface_signal_gate` / `architecture_first_for_requirements` / `architecture_design_structured` / `framework_decoupled_only` / `closeout_requires_huishen` / `agent_self_verify_before_done` / `ui_feature_requires_e2e` / `browser_operator_self_test` | 跳过澄清/用例规格直改代码；跳过计划却跳过验收；**非简单新需求不编制工程团队 / 重大矛盾不停工**；**计划写无关散文带偏主题**；有 Web 无 Browser 真机验；布局/吐槽/审图不让原型+UI 调整；字面照做不合理需求；耦合写码；非简单 feature 无 e2e；跳过汇审 | 计划正文 **背景/方案/细节**；宿主原生编辑 + UT/RT/WB/e2e；文档收口 |

## 写 HTML / 模板前决策流（硬规则）

```text
1. 用户可见文案？     → **源串写简体中文** + <lang> / @lang()；禁止 HTML 内 __()；禁止英文当默认源串
   · 源文含逗号？     → 必须 <lang>…</lang> 或加引号 @lang('a, b')；禁止 @lang{a, b}（逗号当参数分隔，编译 ParseError）
2. 领域/选择性控件？ → **先**查 Taglib 场景映射表 + 标签全量索引；禁止裸 <select>/<input>/ISO text；无现成标签则在拥有模块新增 Taglib
2b. 站内跳转 href/action/data-*-url？ → 模板 `@url`/`<url>`；PHP `Url::getUrl`/`getFrontendUrl`/`getBackendUrl`；禁止 `'/' . $path`
3. 页面插槽/可运营块？ → Hook 或 Widget
4. 布局骨架？         → layout/partial/component/widget 分层，读 Theme 总指南
5. 内容区宽度/容器？  → 先读 theme-layout-content-width.md：已在 .w-container 内用壳层 A（width:100% + padding-inline:0）；独立壳用壳层 B（--weline-layout-content-* / .w-theme-content-width）。禁止自写第三套容器或像素字面量版心
6. 浮层/下拉/工具条？ → menu/popover/tooltip/combobox/anchored-float 或 UI.floating.attach；禁止手写 left/top 与自研边界翻转
7. 图片？             → 先分选图/出图：[选图与出图分工](../FileManager/doc/file-manager-选图与file-image出图.md)
                      · 选图 → `<w:file-manager>` 或 `WelineMedia`（Theme 部件 `media_image` 用 `value_mode=file-image`+`usage=1`）
                      · 出图 → `<w:file:image>` 设 width+height 或 aspect_ratio（HTML 占位防 CLS）+ 主题 CSS max-width:100%;height:auto；禁止无尺寸裸 img；禁止用 file:image 当选图器
8. 以上都不满足？     → 才写原生 HTML，TaskContract 说明原因
```

## 会话反复纠正清单

| 纠正点 | 权威文档 |
|--------|----------|
| Hook 只有 phtml、无规约 | [Hook创建规范.md](../Hook/doc/Hook创建规范.md) |
| Hook type 段非 partials/layouts | [Hook创建规范.md](../Hook/doc/Hook创建规范.md) §type 段 |
| Event 名未文档化就 dispatch | [事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md) |
| 缺 `weline-code` | [frontend-section-weline-code.md](../Theme/doc/frontend-section-weline-code.md) |
| 手写 select 代替 Taglib | [场景映射表.md](../Taglib/doc/场景映射表.md) |
| 把「范围」写成路径通配 / 忽略站店渠继承 | [store-saleschannel-scope.md](../Websites/doc/store-saleschannel-scope.md)；MCP `weline_business_scope_hierarchy`；SystemConfig `getFallbackScopes` |
| 业务「配置」另造私有表/Service 或忽略统一配置中心 | [SystemConfig README](../SystemConfig/doc/README.md)；MCP `systemconfig_unified_config_terms` / `SystemConfigTermRouting` |
| embed `fields` 与声明 `key` 不一致 / 「没有这个字段」红标 | [config-embed标签使用指南.md](../SystemConfig/doc/config-embed标签使用指南.md)；Affiliate/B2B/Dropship Config |
| 前端不用自研主题 / 硬编码视觉 / 手写地址级联 | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md) |
| 用 UI/frontend-design/原型技能却自造色板间距、不从 MCP 取主题技能 | MCP `get_skill(weline-theme-development)`；`ui_skill_requires_theme_skill`；[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md) |
| 提到 CSS/主题却不读 UI+原型+主题三技能 | MCP `css_or_theme_requires_ui_prototype_theme_skills`；`frontend-design` + `prototype` + `get_skill(weline-theme-development)` |
| 后台/admin 页不做主题化（裸表单）或搜索条全宽竖叠 | MCP `backend_admin_ui_requires_frontend_theme_skills`；对照 Marketing `w-backend-page`/`w-card`/`w-field`；工具条紧凑横排 |
| 前端用英文源串 / 改文案却不写中英 CSV / zh 列塞英文 / en 列留中文 / 误以为双语靠 CSS | MCP `frontend_ui_requires_zh_en_csv` + `module_i18n_chinese_source_default` + `module_i18n_csv_collect`；[模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md)「前端开发成员约定」；Theme §4.1；前端席必须挂 template_i18n+module_i18n_csv；**开发语言默认中文，双语靠 CSV** |
| 把宿主 SKILL.md 当工程技能权威 | 本文 `mcp_skills_fetch_from_mcp`；`resolve_skill` / `get_skill` |
| 主题开发却给基础组件私写颜色 | MCP `theme_base_components_token_only`；[theme-semantic-color-matrix.md](../Theme/doc/theme-semantic-color-matrix.md) |
| 地区筛选/国家省市区手写 select 或自造 chips | [场景映射表.md](../Taglib/doc/场景映射表.md)；MCP `theme_address_for_region_pickers` |
| 浮层手写 left/top / 自研 flip / 私造 modal / 绕开 Weline.UI.dialog | [anchored-float.md](../Theme/doc/widgets/anchored-float.md)；MCP `weline_ui_floating_primitives`（含 dialog / 产品·媒体·图标选择器 / MCP 前端弹出） |
| 自写一套页面/模块版心容器 / 双重 gutter / `1440px` 私有壳 | [theme-layout-content-width.md](../Theme/doc/theme-layout-content-width.md)；MCP `frontend_unified_content_container` |
| 写 HTML 不查 Taglib | 本文 + [标签全量索引.md](../Taglib/doc/标签全量索引.md) |
| 业务类自做进程内缓存 / 清理与驱动 key 不一致 | [统一缓存范围与性能优化.md](../Framework/doc/统一缓存范围与性能优化.md)（缓存类进程内+驱动同 key）；勿再引用已取消的 `cache_lookup_tier_process_shared_db` |
| 图片缺 HTML 宽高 / 只靠 CSS 声称响应式 | [file-image-cls-尺寸与响应式.md](../FileManager/doc/file-image-cls-尺寸与响应式.md)；MCP `image_explicit_width_height_css` |
| 前台 phtml 用 `__()` | [Theme开发总指南.md §i18n](../Theme/doc/开发/Theme开发总指南.md) |
| `@lang{含,逗号}` 编译 ParseError | [01-lang标签使用指南.md](../Framework/doc/4-内置标签/01-lang标签使用指南.md)：逗号为参数分隔；改 `<lang>` 或加引号 |
| Theme 布局内嵌他模块 widget（业务模块自有布局内嵌本模块部件除外） | [部件开发指南.md](../Theme/doc/部件开发指南.md)、[Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) |
| 部件直接 `@static` / `<script src>` 拉 JS 模块 | [前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md)（`data-weline-load` / `Weline.declare`） |
| 在 `weline.js` 写死 cart/account/compare 等业务名、业务代理或兑券/加购/维护 UI；把核心 i18n.js 放进外置 Weline_I18n | 同上；MCP `weline_js_loader_framework_only`（强制）；核心 i18n 归 `Weline_Framework::js/i18n.js`；Theme declare 加载；`Weline_I18n` 仅增强；勿把 Phrase 改名为 I18n（与小写 `i18n/` CSV 冲突） |
| `w:*` 属性写 PHP | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) |
| 注释内写 `<?=` / `<?php`（含文件头日期短回显） | 本文 `no_php_tags_in_comments`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) |
| 新增注释用英文堆砌 / 代码过度巧妙难读 | 本文 `chinese_comments_friendly_style`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) |
| raw fetch/ajax | [Weline.Api使用指南.md](../Frontend/doc/Weline.Api使用指南.md) |
| alert/confirm | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；MCP `no_native_js_dialogs` → `Weline.UI.toast` / `dialog.confirm` |
| routes.xml / 改 generated | [AI-ENTRY.md](../../../AI-ENTRY.md) |
| 交付 URL 格式错误 / 省略交付地址 | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)；MCP `feature_delivery_urls` |
| 验收 Browser 未禁用缓存就验本回合 UI | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md) 门禁 A WB-CACHE；MCP `browser_cache_disabled_on_open` |
| WB-OP 默认 `position:active` 抢 IDE 焦点 | 同上 门禁 A·3a；MCP `browser_operator_non_preemptive`（省略 position 后台开页） |
| 写完交付地址仍不关验收 Browser | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md) 门禁 D；MCP `browser_release_after_delivery` |
| 主验收 Host 写成 `*.weline.test` 或强行 `127.0.0.1` | 同上「本机默认 Host」；默认 `{project_hash}.test.weline.com` |
| 未自行验证就宣称完成 / acceptance 无 evidence / 功能缺审图 / 缺汇审 | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；MCP `agent_self_verify_before_done` / `acceptance_phase_requires_shentu` / `closeout_requires_huishen` |
| 未规划 / 未 TDD / unit 无真实跑测 evidence | [AI工程交付流程.md](./AI工程交付流程.md)（先计划；红→绿；PASS evidence） |
| 未 Browser 自测就宣称完成 | 同上；只能报「代码已改，WebUI 验收未完成」 |
| 多 todo 计划未逐项举证就说「已完成」 | [AI工程交付流程.md](./AI工程交付流程.md) §7；须报「部分完成」+ 未完成清单 |
| 计划未按七维合规审核 / 多任务无章节 e2e 闭环 / 未标进度就下一章 | [AI工程交付流程.md](./AI工程交付流程.md) §3 |
| 让用户手写 MCP Settings | [AGENTS.md](../../../AGENTS.md) |
| 手写 `.cursor/rules` / Codex 私有规则当权威 | 本文 `host_editor_rules_mcp_generated_only`；仅允许 MCP 生成宿主规则产物 |
| Cursor 在 Codex CLI 可用时自行写笼统计划 / Codex 宿主递归调用自身 / 命令写死 `-m` / **静默委派不提示「Codex 正在工作」** | 本文 `host_delegate_explore_plan_review_to_codex_cli`；`agent_guidance.host_codex_delegation.user_visible_status` |
| 查翻译/cron/队列状态默认去生产 / 把 SSH 默认 profile 当默认查线上 | 本文 `runtime_status_query_local_first`；无翻译特殊线上规定 |
| 改 Model 字段不 bump 模块 version | [模块版本与升级门禁.md](../Framework/doc/3-开发/模块版本与升级门禁.md) |
| 用户提出需求后不澄清/不写用例规格 / 计划阶段不启用宿主 Plan Mode / 不分析 / 功能不标 work_kind / 验收无审图 / 结束无汇审 | [AI工程交付流程.md](./AI工程交付流程.md) §1–§7；[需求澄清与用例规格.md](../../../../../dev/ai-command/ai/需求澄清与用例规格.md)（`requirement_clarify_use_case_spec` / `host_plan_mode_for_planning` / `requirement_feature_kind_gate` / `acceptance_phase_requires_shentu` / `closeout_requires_huishen`） |
| 新建 Controller 不 upgrade/route | 同上 |
| URL 写成 `/getEdit`、`/getAdd`、`/postSave` | [03-自定义控制器.md §HTTP方法](../Framework/doc/2-快速开始/03-自定义控制器.md)：`getEdit()`→路径 `/edit`，`postSave()`→`/save`；前缀只约束请求方法 |
| 改 CSV 不 collect / en_US 未译 | [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md) |
| 前台加词后台 CSV 不补 | 同上 |
| Taglib callback 里写 `@static(...)` 导致 404 | [如何自定义Tag.md §静态资源](../Taglib/doc/如何自定义Tag.md)、[I18n/Taglib/Local.php](../I18n/Taglib/Local.php) `resolveModuleStaticUrl` |
| 控制台 `.../@static(Weline_*.css)` 404 | 同上；查 `<w:*>` 标签 callback 是否裸输出 `@static` |

## MCP 检索

```text
resolve_task_context(task="...", kinds=["doc","rule"])
get_indexed_document(path="app/code/Weline/Ai/doc/AI硬规则索引.md")
```

会话完整硬约束由 `prepare_project.agent_guidance.hard_constraints` 提供。后续 `guidance-bundle.v1.workflow_contract`（`workflow-contract.v1`）只返回任务匹配的规范和权威文档入口；正常响应不再重复下发全量工作流、固定 `pinned_fragments` 或前端兼容别名。`rules` 可携带验证后的学习规则，`sources`、`pinned_fragments` 仅为可选旧版字段。

`fragments` 自带路径、行号与来源 hash。`content_hash` 标识完整索引源片段，不是返回摘要的 hash；最终预算再次裁剪正文时标记 `content_truncated=true`，来源 hash 保持不变。编辑符号时以当前磁盘源码为准，保留完整区域上下文。

`token_usage.estimated` 按完整序列化工具响应的 Unicode 字符数除以 4 估算，包含封装；不是模型 tokenizer 实测。若预算小于必要约束和会话元数据的固定成本，返回真实估算及 `budget_exceeded=true`，不新增执行门禁。

## 相关

- [文档索引.md](./文档索引.md)
- [AI工程交付流程.md](./AI工程交付流程.md)
