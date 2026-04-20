# AI建站中台-计划（PageBuilder唯一版）

> 模块：`GuoLaiRen_PageBuilder`  
> 状态：`in_progress_replanned`  
> 说明：本文件为 `app/code/GuoLaiRen/PageBuilder/doc/建站中台` 目录唯一保留计划文件。

## 1. 目标与范围

- 统一 AI 建站工作台执行口径为“两阶段”：
  - 阶段一：方案生成与确认（plan-first）
  - 阶段二：按确认方案执行构建（build-by-blueprint）
- 统一 AI 生成执行口径：阶段一 AI 方案生成、阶段二 AI 任务方案生成、虚拟主题 AI 构建全部在后台队列执行；SSE 只承担各阶段进度同步与结果就绪通知。
- 统一重入策略：从默认自动续跑改为显式“继续/稍后再说”。
- 统一真相源：`build_tasks[*].status` 是任务完成真相，`build_checkpoint` 仅恢复索引。
- PageBuilder 侧聚焦：工作台、SSE、执行蓝图、双轨构建、物化与发布门禁。

## 1A. 需求细节基线（MUST，不可丢失）

> 本节为实现与评审强约束。若与其他章节冲突，以本节为准。

### 第一阶段：块化方案生成工作台（老板最新口径）

- MUST 第一阶段不再使用方案弹窗；方案工作台直接写在第一阶段主区域下方，包含共享区、页面 Tab、总进度区、队列状态区、AI 操作区。
- MUST 第一阶段的“方案”本身就是块树，而不是先写一篇自由 Markdown 再二次拆块；最小规划单位固定为 `shared block` 与 `page block`。
- MUST 用户一句话需求先进入 `需求扩展队列`，先生成 `theme_design`、`shared:header`、`shared:footer` 三类共享规划，再派发页面类型方案任务。
- MUST Header/Footer 与主题设计先完成并持久化，页面类型方案任务才允许开始；页面任务粒度固定为“一次请求 = 一个页面类型”，允许并发。
- MUST 页面任务请求必须强制携带：主题设计、Header 规划、Footer 规划、站点定位、页面目标、反写死约束、当前页面类型上下文，保证主题连续性。
- MUST 每个块在第一阶段就补齐：实施方式、实际规划内容、设计理由、完成判定、可编辑字段、内容来源、样式方向、响应式规则。
- MUST 共享块与页面块生成完后组装为统一的 `plan_book.markdown + plan_book.structured + block_index`，供第二阶段直接按块拆任务。
- MUST 页面方案一旦完成就立即插入对应页面 Tab，允许先看先改，不等待全站方案完成。
- MUST 整个第一阶段完整生成都走后台队列；SSE 只负责读取进度、推送页面就绪/块就绪/装配完成状态；轻量微调与局部重建才允许使用短时 SSE 流式返回。

#### 第一阶段的生成顺序与并发规则（MUST）

- MUST 后台任务图固定为：`stage1.requirement_expand` -> `stage1.shared.theme_design` -> `stage1.shared.header_footer` -> `stage1.page_plan:*` -> `stage1.plan_assemble`。
- MUST `stage1.shared.theme_design` 输出统一的 `theme_context_snapshot`，至少包含：站点定位、视觉方向、共享导航策略、共享 CTA、共享内容语气、SEO 总策略、禁止写死字段。
- MUST `stage1.shared.header_footer` 基于 `theme_context_snapshot` 生成共享块，并输出给页面任务复用的 `shared_prompt_context`。
- MUST 页面类型任务在共享规划完成后并发执行，默认一个页面一个队列任务；同一页面内块可顺序或小批并发生成，但页面级任务必须保留共享上下文哈希。
- MUST 若共享规划被微调或重建，所有尚未完成的页面任务必须重新比对 `shared_context_hash`；不一致则标记 `stale` 并等待重建。
- MUST 页面级任务完成后立即触发 `stage1.plan_assemble` 增量装配，把新页面并入总方案与页面 Tab。

#### 第一阶段的内联工作台与块级交互（MUST）

- MUST 第一阶段展示三层视图：
  - 全站总览：一句话需求、共享规划进度、页面完成度、总任务进度；
  - 共享规划区：主题设计、Header、Footer 三个共享块卡片；
  - 页面 Tab 区：每个页面类型一个 Tab，进入后展示该页面的块列表。
- MUST 页面 Tab 顶部提供页面级 AI 操作：`微调当前页面`、`重建当前页面`、`新增块`。
- MUST 每个块 hover 时显示块级操作：`微调块`、`局部重建`、`删除块`；块列表底部提供 `新增块` 按钮。
- MUST 块级操作直接作用于块数据结构，不允许只改 Markdown 字符串而不改结构化数据。
- MUST 用户在页面之间切换 Tab 时保留未提交输入草稿与当前选中块状态。
- MUST 页面块编辑后立即触发该页面局部装配，更新当前页面预览与总方案书对应章节。

#### 第一阶段的提示词分层（MUST）

- MUST 把第一阶段提示词拆为两类：
  - `buildStageOneSharedPlanPrompt(...)`：专门负责主题设计 + Header/Footer + 共享规则；
  - `buildStageOnePagePlanPrompt(...)`：专门负责单页面类型方案，输入必须包含 `theme_context_snapshot + shared_prompt_context + page_type_request`。
- MUST `buildStageOneSharedPlanPrompt(...)` 输出必须包含：主题定位、共享视觉规则、Header 规划、Footer 规划、共享 CTA、共享字段、共享设计理由。
- MUST `buildStageOnePagePlanPrompt(...)` 输出必须包含：页面目标、块顺序、每块实施方式、每块实时内容、每块理由、每块完成判定。
- MUST 第一阶段所有提示词都遵守“不能写死未提供事实”的反写死校验，并在输出中区分 `已知事实 / 合理建议 / 待确认变量`。

#### 第一阶段：提示词具体性契约（MUST，与实现对齐）

> 目的：杜绝“方向性描述”（如通篇“围绕…/突出…/说明…/完善导航/优化体验”），保证阶段一产出**可直接进入阶段二拆解**的落地文案与结构化字段样例。与代码侧提示词条款一致，便于评审对照。

- MUST 实现参考（整站/聚合方案提示词）：`GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService::buildAiPlanPrompt()`、`buildPageMarkdownTemplate()`；与上文 `buildStageOneSharedPlanPrompt(...)` / `buildStageOnePagePlanPrompt(...)` 的拆分目标一致——**无论拆分为几条提示词，每条都必须遵守本节契约**。
- MUST 将「用户一句话需求」置于提示词靠前位置，作为拓写权威来源；模型输出的是**客户可见的标题、正文、CTA、导航项、字段示例**，不是“教别人怎么写”的元说明。
- MUST 满足 **CONCRETENESS CONTRACT（五条）**：
  1. 每个块包含真实页面级字符串：导航标签、页标题、主副标题、正文句、CTA 文案、链接目标、表单字段、信任点等（按块类型取舍，但不得只剩方法论句）。
  2. 若某段话换任意无关行业仍成立，必须用站点名、用户一句话需求、已知事实中的专有名词/数字/优惠/证据点改写。
  3. 使用具体名词、数字、卖点、品牌语气；禁止整段只有“突出价值/说明亮点/完善导航”等空洞动词短语。
  4. 事实不确定时允许 `[假设]` 前缀，但**仍须给出可展示的示例值**，禁止“待补充”“详见后文”等占位。
  5. `navigation_plan.header_items`、`footer_plan.featured`、`footer_plan.policies` 须为非空、可点击的真实 `{label, href}`（或等价结构），禁止 `Link1`/`Nav item`/“补充政策链接”式占位。
- MUST 在提示词或评审中提供 **GOOD vs BAD 对照锚点**（模型不得照抄示例句，但须学会：BAD=方向性/meta，GOOD=可落地字符串与字段样例）。示例维度至少覆盖：`field_plan.sample`、`blocks[].content`、`execution_script.core_copy`、`navigation_plan.header_items`。
- MUST 在模型侧执行 **Final audit（返回前静默自检）**：逐块检查 (a) 是否引用用户一句话需求中的具体名词/数字/品牌；(b) 非平凡块的 `field_plan` 是否具备足够条目与具体 `sample`；(c) 是否避免仅用「围绕/突出/说明/完善/优化」充当内容；任一项不通过则重写该块后再输出。

#### 第一阶段确认进入第二阶段（MUST）

- MUST 用户点击“确认第一阶段方案”时，先持久化：
  - `plan_workbench.stage1.request_summary`
  - `plan_workbench.stage1.theme_context_snapshot`
  - `plan_workbench.stage1.shared_plan`
  - `plan_workbench.stage1.page_plans`
  - `plan_workbench.confirmed.plan_book_markdown`
  - `plan_workbench.confirmed.structured_plan`
  - `plan_workbench.confirmed.block_index`
  - `plan_workbench.confirmed.shared_prompt_context`
- MUST 第一阶段确认成功后，第二阶段默认读取上述确认版数据，不允许再从“临时 Markdown 文本”反推块结构。
- MUST 第一阶段确认失败时留在当前内联工作台，并清晰提示是哪类持久化失败，不得假切换到第二阶段。

### 第二阶段：块任务细化与虚拟主题生成工作台（老板最新口径）

- MUST 第二阶段不是重新写一份大方案，而是基于第一阶段已确认块树，细化每个共享块/页面块的任务资料、字段配置、素材位、内容补充说明。
- MUST 第二阶段的最小执行单元固定为 `block task`；例如 Header 的 logo 配置、导航项、CTA 配置、移动端折叠规则都属于 Header block task 的细化内容。
- MUST 第二阶段先细化共享块任务，再细化页面块任务；页面块任务可以并发，但每个任务都必须携带同一个 `theme_context_snapshot` 与 `stage2_context_snapshot`，保证主题延续。
- MUST 第二阶段完成后要先组装为每页可阅读的《块任务方案》，用户可继续按页面 Tab 查看、微调、删除、增加块任务。
- MUST 第二阶段确认后，才允许进入“后台生成虚拟主题”；虚拟主题必须严格按照已确认块树与块任务生成页面结构。
- MUST 第二阶段完整生成同样走后台队列；SSE 只负责进度、轻量微调、局部重建与页面就绪通知。

#### 第二阶段的生成顺序与并发规则（MUST）

- MUST 后台任务图固定为：`stage2.confirmed_plan_parse` -> `stage2.shared_task_plan` -> `stage2.page_task_plan:*` -> `stage2.plan_assemble` -> `virtual_theme.tree_build` -> `virtual_theme.page_build:*` -> `virtual_theme.publish_ready_check`。
- MUST `stage2.shared_task_plan` 产出共享块任务细化包，至少包含：字段矩阵、素材位、默认值、编辑约束、共享组件依赖。
- MUST 页面块任务并发前，先生成只读 `stage2_context_snapshot`，其中包含：主题上下文、共享块任务摘要、页面级内容语气、统一提示词版本、统一反写死约束。
- MUST 页面块任务执行时若发现 `stage2_context_hash` 变化，必须中止并重新排队，禁止用旧上下文继续生成。
- MUST 每个页面的块任务完成后立即触发该页面任务方案装配，并刷新该页面 Tab 的块任务视图。

#### 第二阶段的内联工作台与块任务交互（MUST）

- MUST 第二阶段同样以内联工作台展示，不使用确认弹窗作为主编辑容器。
- MUST 页面 Tab 内展示“设计块任务卡片”，每张卡片至少显示：块名称、任务目标、补充字段、素材要求、依赖、当前状态。
- MUST 页面级 AI 操作提供：`微调当前页面任务`、`重建当前页面任务`、`新增块任务`。
- MUST 块任务 hover 操作提供：`微调任务`、`局部重建`、`删除任务`；页面底部允许 `新增块任务`。
- MUST 第二阶段微调/删除/新增的结果同时更新：结构化块任务、页面装配结果、虚拟主题待生成树。

#### 第二阶段：提示词具体性契约（MUST，与实现对齐）

> 目的：阶段二不是“再写一份教程”，而是把阶段一已确认意图细化为**可执行块任务**（字段键、默认值、示例文案、路由/内链、素材位、完成判定）。与代码侧提示词条款一致。

- MUST 实现参考（任务方案总提示词）：`GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService::buildTaskPlanPromptBase()`；分批生成时另有 `buildTaskPlanGenerationBatchPrompt()`，**分批与整包须遵守同一契约**。
- MUST 将「用户一句话需求」（及阶段一已确认摘要）置于提示词靠前位置；`task_script.story_goal` 描述**访客在页面上读到的可见结果**（“访客看到/读到 ___”），禁止等同于“撰写文案说明 ___”式元指令。
- MUST 满足 **CONCRETENESS CONTRACT（阶段二六条）**：
  1. 每条任务含真实字符串：导航标签、标题、正文、CTA、链接目标、表单标签、信任点等（按任务类型取舍）。
  2. `task_script.content_fill_rule` 须**枚举待填字段**，且每个关键字段至少给一条**可照抄或略改的示例值/取值范围**。
  3. `field_content_requirements[].sample` 为定稿文案或 `[假设]` + 仍具体的文案；禁止“待补充”“突出卖点”“详见后文”“围绕主题展开”等。
  4. 复用或强化阶段一已出现的具体导航/Hero/页脚链接文案，不得用抽象描述替换。
  5. `shared:header` / `shared:footer` 须写明信息分组与**用户可见的链接标题 + 目标**（`page_type` 或 `href`），禁止 `nav TBD`。
  6. `execution_order`、`task_tree`、Markdown 任务书须能指导实施者**不经猜测**完成主题/HTML 配置。
- MUST 提供 **GOOD vs BAD** 对照锚点（至少覆盖：`story_goal`、`content_fill_rule`、字段 `sample`、导航项）。
- MUST **Final audit**：逐任务检查 story_goal 可见性、content_fill_rule 与样例具体性、链接/导航是否具名；不通过则重写后再输出。

#### 虚拟主题生成与发布前链路（MUST）

- MUST 第二阶段确认后先生成 `virtual_theme_build_tree`，树结构至少覆盖：site -> shared -> page -> block -> component -> slot。
- MUST `virtual_theme_build_tree` 必须可直接驱动后台页面生成任务；页面生成仍按“一页面一任务”并发，页面内组件按块树顺序组装。
- MUST 页面生成完成即立即可预览、可进入可视化编辑，不必等待全站完成。
- MUST 最终发布前必须通过：共享块完成、必需页面完成、关键页面可访问、虚拟主题树无阻塞节点。

#### 全流程后台队列、SSE 与轮询（MUST）

- MUST 阶段一、阶段二、虚拟主题生成全部由后台队列执行；前端不直接承担长耗时 AI 生成。
- MUST SSE 只负责：进度推送、页面就绪通知、块就绪通知、轻量微调结果、局部重建结果。
- MUST 各阶段进度展示必须分阶段绑定：`stage1-progress-sse` 只更新第一阶段方案进度区，`stage2-progress-sse` 只更新第二阶段任务方案进度区，`virtual-theme-progress-sse` 只更新虚拟主题构建进度区，禁止跨阶段串写进度显示。
- MUST 后端完成任一 AI 子任务时，必须先持久化当前阶段状态、阶段产物与任务进度，再通过对应阶段 SSE/轮询把进度刷新到该阶段 UI。
- MUST 前端同时支持 `status polling`，即便 SSE 断开也能通过轮询恢复当前任务图、当前完成度、已完成页面。
- MUST 后端状态必须支持按 `session_public_id` 或 `website_public_id` 查询，不强依赖单一浏览器会话。

#### 块级 API 与操作范围（MUST）

- MUST 提供统一块级 API，支持以下动作：`refine`、`rebuild`、`create`、`delete`、`move`、`status`。
- MUST 所有块级 API 都同时支持：
  - `session_public_id`
  - `website_public_id`
  - `stage=stage1|stage2|virtual_theme`
  - `page_key`
  - `block_key`
- MUST `refine/rebuild` 默认只影响目标块；若需要联动共享块或关联页面，必须把影响范围写入返回值与进度事件。
- MUST `create/delete/move` 完成后更新块树版本号与装配版本号，避免前端展示旧数据。

#### 发布前最终门禁（MUST）

- MUST 只有在用户确认“虚拟主题效果无问题”后，才允许进入正式发布网站。
- MUST 发布前至少校验：虚拟主题树完成度、关键页面可视化可访问、共享块未丢失、站点域名/供应商门禁通过。
- MUST 若发布门禁失败，必须回到第二阶段或虚拟主题阶段继续微调，不得绕过门禁强制发布。

## 2. 完整需求意图

### 2.1 业务意图

- 让 AI 建站从“进来就跑”改为“先确认方案再执行”，确保结果可控、可解释、可回看。
- 把构建行为从隐式逻辑切换到显式蓝图驱动，避免任务遗漏、重复执行、状态漂移。
- 在用户重进工作台时，优先尊重用户决策权，不再静默自动续跑长任务。

### 2.2 工程意图

- 强制建立统一真相源：任务状态以 `build_tasks[*].status` 为准，checkpoint 只做恢复定位。
- 固化关键顺序：状态先持久化，再发 SSE，避免前端看到“播报成功但状态丢失”。
- 统一接口语义和错误码，保证前后端、测试与文档同口径。

### 2.3 边界与非目标

- 本计划聚焦 PageBuilder 建站工作台链路，不扩展到 WLS/hosts 基建细节实现。
- 不在本轮引入“同站双轨混用”，仍保持站点级二选一。
- 不把历史自动续跑兼容逻辑继续当默认行为，只保留显式入口。

### 2.4 禁止偏离项

- 禁止未 confirmed plan 启动 build。
- 禁止将 `build_checkpoint` 当作任务完成真相。
- 禁止以“自动续跑成功率”替代“用户显式继续”交互目标。
- 禁止阶段一 plan 流混入阶段二 build 任务状态写入。

## 3. 角色边界

- `Weline_Websites`：中台入口、provider 选择、域名流程与跨模块编排。
- `GuoLaiRen_PageBuilder`：`provider=pagebuilder` 的生成/编辑主事实源，负责工作台构建与发布链路。
- 域名相关高风险动作保持显式确认；PageBuilder 仅消费 `site_ready` 等契约字段做发布门禁。

## 4. 两阶段执行模型（核心）

### 4.1 阶段一：共享优先的块化方案生成

- 输入：一句话需求、页面类型、语言/站点事实、用户补充说明。
- 顺序：先扩展需求 -> 生成主题设计与共享块 -> 并发生成单页面块化方案 -> 增量装配总方案。
- 产出：
  - `theme_context_snapshot`
  - `shared_plan`
  - `page_plans[*].blocks`
  - `plan_book.markdown`
  - `structured_plan`
  - `block_index`
- 文案与结构化字段须满足 **§1A「第一阶段：提示词具体性契约」**（禁止整段方向性描述；导航/页脚/字段样例必须可落地）。
- 前端工作形态：内联工作台 + 页面 Tab + 块 hover 操作 + 进度区。
- 运行方式：第一阶段 AI 方案生成在后台队列执行，SSE/轮询只把队列进度同步到第一阶段进度区；轻量微调与局部重建可短时流式返回。

### 4.2 阶段二：块任务细化与虚拟主题生成

- 输入：第一阶段确认版块树、共享上下文、页面块方案、共享提示词上下文。
- 顺序：先细化共享块任务 -> 再并发细化页面块任务 -> 装配每页任务方案 -> 确认后生成虚拟主题树 -> 并发生成页面。
- 产出：
  - `stage2_context_snapshot`
  - `shared_block_tasks`
  - `page_block_tasks`
  - `task_plan_book.markdown`
  - `virtual_theme_build_tree`
  - `execution_blueprint`
- 块任务文案与 `task_script`/`field_content_requirements` 须满足 **§1A「第二阶段：提示词具体性契约」**（任务级可执行，非元说明）。
- 前端工作形态：内联任务工作台 + 页面 Tab + 任务卡片 + 块任务微调/删除/新增。
- 运行方式：第二阶段 AI 任务方案生成、虚拟主题建树与页面构建均在后台队列执行，SSE/轮询分别同步第二阶段任务进度与虚拟主题构建进度；最终通过虚拟主题确认后再发布。

## 5. 数据契约

### 5.1 第一阶段 scope 持久化

- `scope_json.plan_workbench.stage1.request_summary`
  - `raw_requirement`
  - `explicit_facts`
  - `safe_inferences`
  - `pending_variables`
- `scope_json.plan_workbench.stage1.theme_context_snapshot`
  - `theme_context_id`
  - `site_positioning`
  - `visual_direction`
  - `content_tone`
  - `shared_navigation_strategy`
  - `shared_cta_strategy`
  - `seo_strategy`
  - `anti_hardcode_rules`
  - `context_hash`
- `scope_json.plan_workbench.stage1.shared_plan`
  - `theme_design`
  - `header_block`
  - `footer_block`
  - `shared_prompt_context`
- `scope_json.plan_workbench.stage1.page_plans[]`
  - `page_key`
  - `page_status`
  - `page_goal`
  - `page_context_hash`
  - `blocks[]`
  - `assembly_version`
- `scope_json.plan_workbench.confirmed`
  - `plan_book_markdown`
  - `structured_plan`
  - `block_index`
  - `shared_prompt_context`
  - `confirmed_signature`

### 5.2 第二阶段与虚拟主题持久化

- `scope_json.plan_workbench.stage2.context_snapshot`
  - `source_confirmed_signature`
  - `theme_context_snapshot`
  - `shared_task_context`
  - `prompt_version`
  - `context_hash`
- `scope_json.plan_workbench.stage2.shared_block_tasks[]`
- `scope_json.plan_workbench.stage2.page_block_tasks[]`
  - `page_key`
  - `task_status`
  - `blocks[]`
  - `context_hash`
- `scope_json.virtual_theme_build`
  - `tree_signature`
  - `build_tree`
  - `page_jobs[]`
  - `page_render_status[]`
  - `publish_gate`

### 5.3 块级公共字段（阶段一/阶段二共用）

- `block_key`
- `block_type`（`shared:header` / `shared:footer` / `page:content:*`）
- `page_key`
- `title`
- `goal`
- `implementation_detail`
- `realtime_content`
- `editable_fields`
- `content_source`
- `style_direction`
- `responsive_rule`
- `seo_role`
- `reason`
- `completion_rule`
- `dependencies`
- `prompt_context_hash`
- `version`

### 5.4 队列任务与进度模型

- `job_key`
- `job_type`
- `stage`
- `session_public_id`
- `website_public_id`
- `page_key`
- `block_key`
- `depends_on[]`
- `status`（`pending|queued|running|stale|done|failed|cancelled`）
- `progress_percent`
- `result_ref`
- `context_hash`
- `retry_count`
- `last_error`
- `updated_at`

## 6. 双轨策略（站点级二选一）

- `workspace_track=html_blocks`：页面区块轨，`blocks[] + ai_html`，按块再生成。
- `workspace_track=virtual_theme`：高级虚拟主题轨。
- 首版同站不混用两轨；默认轨切换与产品口径统一后再落地。

## 7. 接口与交互

### 7.1 第一阶段接口

- `post-start-stage1-plan`
  - 输入：`session_public_id`、一句话需求、`page_types[]`、`plan_locale`
  - 行为：创建阶段一主任务与共享任务，不直接同步生成。
- `get-stage1-plan-status`
  - 输入：`session_public_id|website_public_id`
  - 输出：共享进度、页面进度、已完成页面、总进度、当前上下文版本。
- `post-stage1-page-refine`
- `post-stage1-page-rebuild`
- `post-stage1-block-refine`
- `post-stage1-block-rebuild`
- `post-stage1-block-create`
- `post-stage1-block-delete`
- `post-confirm-stage1-plan`

### 7.2 第二阶段接口

- `post-start-stage2-plan`
  - 输入：`session_public_id|website_public_id`
  - 行为：读取第一阶段确认版，创建共享块任务与页面块任务。
- `get-stage2-plan-status`
- `post-stage2-page-refine`
- `post-stage2-page-rebuild`
- `post-stage2-block-task-refine`
- `post-stage2-block-task-rebuild`
- `post-stage2-block-task-create`
- `post-stage2-block-task-delete`
- `post-confirm-stage2-plan`

### 7.3 虚拟主题与发布接口

- `post-start-virtual-theme-build`
- `get-virtual-theme-build-status`
- `post-virtual-theme-block-rebuild`
- `post-virtual-theme-page-rebuild`
- `post-confirm-virtual-theme-and-publish`

### 7.4 SSE 与轮询交互

- `stage1-progress-sse`
- `stage2-progress-sse`
- `virtual-theme-progress-sse`
- `get-build-status-polling`
- SSE 只推送：`queued`、`progress`、`shared_ready`、`page_ready`、`block_ready`、`assembled`、`done`、`error`。
- `stage1-progress-sse` 只消费/展示第一阶段后台 AI 方案生成进度。
- `stage2-progress-sse` 只消费/展示第二阶段后台 AI 任务方案生成进度。
- `virtual-theme-progress-sse` 只消费/展示虚拟主题后台建树、页面生成与发布前检查进度。
- 轻量微调/局部重建允许使用短连接 SSE 事件：`start/progress/chunk/done/error`。

### 7.5 重入交互（禁止默认自动续跑）

- 检测到运行中队列：提示“继续观察当前生成进度”。
- 检测到未完成块任务：提示“继续执行剩余块任务”。
- 统一按钮：`继续` / `稍后再说`。
- 重入后默认恢复到上次所在阶段、上次所在页面 Tab、上次选中块。

## 8. 当前已落地与待完成

### 已落地

- `operation-sse` duplicate observer 模式已落地。
- `build_tasks/build_task_summary` 任务级记录已落地。
- block regenerate/refine 已支持先确认再应用。

### 待完成

- 阶段一 plan 真实生成与确认写回闭环。
- confirmed execution blueprint 全链路消费。
- `stream-sse` 单连接治理（tab_token、pagehide 清理、reconnect reason）。
- 自动续跑逻辑改造为显式继续交互。
- 默认轨与入口产品口径统一。

## 9. 实施步骤

1. 后端闭环：plan/confirm/resume 接口、build 前置校验、错误码统一。
2. 蓝图收敛：固定 tasks 字段、共享任务显式化、resume 仅续未完成任务。
3. 前端改造：移除自动续跑入口，增加显式继续弹层与分支。
4. 测试收口：集成 + E2E 覆盖 plan-first、build guard、checkpoint 恢复、重入交互。

## 9A. 老板指定逻辑（强制覆盖旧口径）

> 本节为最高优先级需求意图与执行约束。后续实现若与本文其他段落冲突，以本节为准。

### Summary

- 方案书与任务书文案须满足 **§1A 两阶段「提示词具体性契约」**：产出为可落地的标题/正文/CTA/导航/字段样例，禁止通篇方向性描述或元写作说明（与 `AiSiteExecutionBlueprintService` / `AiSiteVirtualThemePlanService` 提示词条款一致）。
- 第一阶段先生成“建站方案书”，SSE 组件只负责流式传输方案内容并实时预览，不承担任何第二阶段执行职责。
- 第一阶段 `微调` 的语义是“按用户要求修改当前方案”，必须返回修订后的完整方案并替换当前草案，不是把补充说明追加到方案底部。
- 用户确认方案后，先一次性把第二阶段所有块级任务规划完整并持久化，再进入生成主题；第二阶段只能执行已确认方案，不允许再按一句话需求临时拆任务。
- 第二阶段必须具备任务级断点续跑：每完成一个任务就保存一次；下次进入工作台友好提示是否继续；用户确认后从未完成任务继续。

### Requirements And Measures

#### 要求 1：第一阶段 SSE 只负责方案流传输与修订回写

- 第一阶段使用独立 `PbAiPlanRunner` 和方案弹窗。
- 不复用工作台右侧日志终端、不显示 build guard、不更新块级任务进度、不参与页面预览切换。
- plan SSE 只处理 `start/progress/chunk/done/error` 五类事件。
- `chunk` 只做流式片段拼接与实时预览刷新（传输层语义）。
- 微调完成时必须以“修订后的完整 `draft.plan_json`”覆盖当前草案；禁止将微调结果作为补充说明追加到原文底部。

#### 要求 2：第二阶段必须先规划完整任务再执行

- 方案确认时同步产出 `execution_blueprint.tasks[]`。
- 任务拆分必须覆盖：`shared:header`、`shared:footer`、每个页面的每个 block，并先写入 `scope_json`。
- build 只消费该任务蓝图。
- 每个块任务执行前必须具备：字段规划、生成说明、风格、配色、内容方向、SEO 词规划、内部链接、CTA 方向。

#### 要求 3：每个任务完成即保存

- 第二阶段以“任务”为最小 checkpoint。
- 每个任务完成后：先持久化任务状态与产物，再发 `task_completed/progress` SSE。
- 当前任务未完成时不得记为完成。
- 恢复从“第一个未完成任务”重跑，保证一致性。

#### 要求 4：下次进入工作台友好提示是否继续

- 移除进入工作台自动续跑逻辑。
- 检测到“已确认方案 + 有未完成任务”时弹继续提示框。
- 提示文案分两种：
  - 有活跃 build：`检测到上次生成仍在进行，是否立即继续查看并接管进度？`
  - 无活跃执行但有未完成任务：`检测到上次未完成的生成任务，是否从上次进度继续？`

#### 要求 5：用户确认继续后立即从断点继续

- 若旧执行仍 `queued/running`：前端直接重连该 `execution_token` 的 `operation-sse`（观察/接管模式）。
- 若旧执行已停止但有未完成任务：调用 `post-resume-build`，后端从 `build_tasks` 找到第一个未完成任务作为恢复起点。
- 恢复点以任务边界为准，不做块内半成品续写。

### Key Changes

#### 1. 第一阶段：方案书生成

- 保留当前顶层 stage，不新增顶层 stage；在 `virtual_theme` 阶段内新增 `plan` operation。
- 点击“AI构建方案”后直接打开现有方案弹窗，不再走旧页面类型确认弹窗。
- 方案弹窗职责仅保留：
  - 展示 Markdown 原文
  - 展示 Markdown 实时预览
  - 展示生成进度与状态
  - 接收用户“补充要求”
  - 触发“AI 再次微调方案”
  - 触发“确认方案并进入第二阶段”
- 第一阶段输出：
  - `draft.plan_json`
  - `draft.execution_blueprint`
- 方案书必须包含：
  - 色系与选型原因
  - 整体主题风格
  - Header/Footer 风格与导航/link 规划
  - 首页与其他页面 SEO 结构和内容布局
  - 响应式策略
  - 第二阶段完整执行顺序
  - 每个页面块级规划

#### 2. 第二阶段：方案驱动的块级任务蓝图

- 用户确认方案时，必须先生成并保存完整 `execution_blueprint.tasks[]`，禁止边生成边拆任务。
- 每个任务至少包含：

```json
{
  "task_key": "page:home_page:hero",
  "page_type": "home_page",
  "region": "content",
  "block_key": "hero",
  "component_kind": "hero",
  "dependencies": ["shared:header", "shared:footer"],
  "status": "pending|running|done|failed|paused",
  "field_plan": [
    {
      "field": "headline",
      "generation_mode": "ai|fixed|derived",
      "instruction": ""
    }
  ],
  "style_brief": {
    "visual_tone": "",
    "layout_rule": "",
    "responsive_rule": ""
  },
  "palette_usage": {
    "background": "",
    "accent": "",
    "text": "",
    "reason": ""
  },
  "content_brief": {
    "goal": "",
    "why": "",
    "headline_direction": "",
    "body_direction": "",
    "cta_direction": ""
  },
  "seo_brief": {
    "intent": "",
    "keywords": [],
    "anchors": [],
    "internal_links": []
  },
  "result_ref": {}
}
```

- `shared:header` 和 `shared:footer` 也必须进入任务蓝图，不再隐式处理。
- 第二阶段执行器优先读取 `plan_workbench.confirmed.execution_blueprint.tasks`，不再以弱蓝图为主来源。

#### 3. 持久化结构

- 在 `scope_json` 中新增并固定：

```json
{
  "plan_workbench": {
    "status": "idle|generating|ready|confirmed|stale",
    "round": 0,
    "source_signature": "sha1",
    "conversation": [],
    "draft": {
      "markdown": "",
      "structured": {},
      "execution_blueprint": {},
      "derived_scope_patch": {}
    },
    "confirmed": {
      "markdown": "",
      "structured": {},
      "execution_blueprint": {},
      "derived_scope_patch": {},
      "confirmed_at": ""
    }
  },
  "build_checkpoint": {
    "plan_signature": "",
    "status": "idle|running|paused|completed|failed",
    "current_task_key": "",
    "last_completed_task_key": "",
    "next_task_key": "",
    "completed_count": 0,
    "total_count": 0,
    "updated_at": ""
  }
}
```

- `build_tasks` 保留，但内容以 `confirmed.execution_blueprint.tasks` 为准。
- `build_checkpoint` 仅恢复索引，任务完成真相来源仍是 `build_tasks[*].status`。

#### 4. 第二阶段 SSE 与恢复机制

- `operation-sse` 新增 `plan` 分支，保留现有 `build/regenerate_page/publish`。
- 第一阶段前端只用 `PbAiPlanRunner`；第二阶段继续用 `PbAiOperationRunner`，但增强为“任务驱动恢复”。
- 第二阶段执行顺序固定：
  1) 读取 `build_tasks`  
  2) 找到第一个 `status != done` 的任务  
  3) 标记 `running`  
  4) 执行生成  
  5) 保存产物到对应 scope/virtual page/shared component  
  6) 标记任务 `done`  
  7) 更新 `build_checkpoint`  
  8) 发 SSE  
  9) 继续下一任务
- 保存顺序必须“先落库，后发 SSE”。
- 断线规则：
  - 任务已完成并保存：下次从下一个未完成任务继续。
  - 任务未完成：不记完成 checkpoint，下次从该任务重新执行。
- 执行器检测到 lease/连接失效后，不再启动新任务；若当前任务可安全收尾，则收尾保存后退出，并把 `build_checkpoint.status` 设为 `paused`。
- 新增恢复入口 `post-resume-build`：只续跑，不重置任务。
- `post-start-build` 调整为新一轮执行入口：
  - 仅允许在有 confirmed 方案时调用；
  - 同一 `plan_signature` 下已有未完成任务时默认拒绝重置，并提示使用恢复入口；
  - “重新开始”后续再加显式强制重置分支。

#### 5. 工作台入场提示

- 入场先读 confirmed 方案与 `build_tasks/build_checkpoint`。
- 若无 confirmed 方案：不弹继续框。
- 若有 confirmed 且存在 `pending/running/paused`：弹继续提示。
- 按钮固定：`继续` / `稍后再说`。
- 点击 `继续`：
  - 若旧 `active_operation` 仍在 `queued/running`：立即连接旧 `operation-sse`；
  - 否则调用 `post-resume-build` 并立即连接新 `operation-sse`。
- 点击 `稍后再说`：
  - 不自动执行 build；
  - 保留进度不变；
  - 可继续查看方案、预览与已有结果。

### Public APIs / Files

- 主要改动文件：
  - `AiSiteAgent.php`
  - `workspace.phtml`
  - `modals.phtml`
  - `script-stream.phtml`
  - `zh-Hans` 镜像模板 `com_workspace.phtml`
- 新增接口：
  - `post-start-plan`
  - `post-confirm-plan`
  - `post-resume-build`
- `post-start-build` 新约束：
  - 无 confirmed 方案返回 `PLAN_REQUIRED_BEFORE_BUILD`
- 建议新增服务：
  - `AiSitePlanGenerationService`
  - `AiSiteExecutionBlueprintService`
  - `AiSiteBuildResumeService`

### 代码清理要求（删除旧冗余逻辑）

> 目标：把不符合“方案优先 + 任务断点续跑 + 显式继续”的旧工作台逻辑从代码层移除，而不是仅停止调用。

#### 删除判定标准

- 任何“进入工作台即自动启动/自动接管 build”的逻辑，均判定为需删除。
- 任何将第一阶段 plan SSE 与第二阶段 build 日志/进度混用的逻辑，均判定为需删除。
- 任何绕过 `confirmed.execution_blueprint.tasks` 临时拆任务执行的逻辑，均判定为需删除。
- 任何把 `build_checkpoint` 当完成真相源覆盖 `build_tasks[*].status` 的逻辑，均判定为需删除。

#### 优先清理点（workspace 相关）

- `workspace.phtml`
  - 删除页面加载即触发自动续跑/自动连接旧 operation 的逻辑（如 `autoResumeActiveOperation`、`workspaceSnapshotResume` 对应入口）。
  - 删除自动续跑触发链路相关的冗余状态变量与分支代码，保留“继续/稍后再说”显式入口。
- `script-stream.phtml`
  - 删除把 plan 流和 build 流混用到同一输出终端的旧分支。
  - 删除与旧自动续跑耦合的流重连捷径，改为由显式继续动作触发。
- `modals.phtml`
  - 删除旧页面类型确认弹窗在“AI构建方案”入口中的强耦合调用，统一改为方案弹窗入口。
- `zh-Hans` 镜像模板 `com_workspace.phtml`
  - 同步删除上述旧逻辑的镜像分支，保证模板行为一致。

#### 控制器与服务清理点

- `AiSiteAgent.php`
  - 删除 `post-start-build` 中与“未确认方案也可启动”相关的兼容分支。
  - 删除恢复入口缺失时由 `post-start-build` 偷跑恢复的旧逻辑，统一走 `post-resume-build`。
- 构建执行链路服务
  - 删除“边执行边临时拆任务”的入口，统一改为消费已确认蓝图任务。
  - 删除与旧弱蓝图字段兼容但无实际调用价值的分支（删除前须由 grep 证明无引用或仅历史引用）。

#### 删除执行顺序

1. 先接入新入口和新流程（plan/confirm/resume）。  
2. 再删旧自动续跑和弱蓝图分支。  
3. 最后做模板镜像与测试回归同步。  

#### 删除验收

- 全局搜索不再出现自动续跑触发源作为默认行为。
- 工作台重入仅能通过“继续/稍后再说”进入恢复路径。
- build 执行仅来源于 confirmed 方案任务清单。
- 删除后集成/E2E 回归通过，且中文镜像模板行为一致。

### Test Plan

- 第一阶段方案流：
  - `post-start-plan` 返回 `operation=plan`
  - `operation-sse` 仅输出方案事件
  - `draft.plan_json` 与 `draft.execution_blueprint` 在 done 后存在
- 方案确认：
  - `post-confirm-plan` 保存 `confirmed.*`
  - 执行蓝图写为第二阶段任务清单
  - 自动进入 build
- build 前置限制：
  - 无 confirmed 方案调用 `post-start-build` 必须失败
- 每任务保存：
  - 单任务完成后 `build_tasks` 与 `build_checkpoint` 已更新
  - SSE 中断时已完成任务不丢失
- 断点恢复：
  - 中断在任务之间：下次从下一未完成任务继续
  - 中断在任务内部：下次从该任务重新开始
- 工作台重入提示：
  - 有未完成任务时进入工作台出现继续提示框
  - 点击继续后连接旧流或启动恢复流
  - 点击稍后再说不自动执行
- 回归：
  - 原“进入工作台自动续跑”测试改为“弹提示后决定是否继续”
  - 默认模板与 `zh-Hans` 模板的方案弹窗、继续提示、恢复流程一致

### Assumptions

- 第一阶段方案 SSE 组件是独立、极简方案流查看器，不承担第二阶段任务日志或恢复职责。
- 第二阶段恢复粒度固定为“任务边界”。
- 重新确认新方案会使旧任务蓝图失效，并重建新的 `build_tasks/build_checkpoint`。

## 10. 验收标准

- 无 confirmed plan 时不能启动 build，并返回标准错误码。
- 重入工作台不自动触发操作流，必须用户显式继续。
- build 输入源唯一为 confirmed execution blueprint。
- 文档口径与代码行为一致，集成/E2E 通过。

## 10A. 验收方案（按新增细节对齐）

### A. 第一阶段方案弹窗验收

- 验收点 A1：方案输出格式
  - 结果必须为 Markdown（原文区与预览区同源）。
  - 必须包含风格、色系、Header/Footer、页面块设计与“为什么这样设计”。
- 验收点 A2：右侧模式交互
  - 右侧 Tab 必须有 `微调/重建` 两种模式。
  - 模式切换后 prompt_mode 正确切换，且消息不混流。
  - 手风琴说明默认收起，不主动打扰，用户可手动展开。
- 验收点 A3：弹窗关闭保护
  - 点击遮罩/鼠标移出/失焦不关闭。
  - 仅显式按钮可关闭；生成中关闭需二次确认。

### B. 第一阶段确认与持久化验收

- 验收点 B1：确认后持久化
  - `plan_workbench.confirmed.*` 完整落库。
  - `plan_locale` 与 `default_locale` 同步持久化并可追踪。
- 验收点 B2：页面刷新
  - 确认成功后刷新当前 workspace 页面进入第二阶段上下文。
  - 失败时停留第一阶段并给出可操作错误提示。

### C. 第二阶段任务方案确认弹窗验收

- 验收点 C1：第二阶段也有 Markdown 方案确认弹窗。
- 验收点 C2：支持 `微调任务方案/重建任务方案` + SSE 实时交流。
- 验收点 C3：确认后弹“方案已保存，是否立即生成”：
  - `立即生成`：启动执行并连接 SSE
  - `稍后生成`：不自动开跑
- 验收点 C4：确认版只读快照可预览可复制，不可直接修改。

### D. 任务拆分与执行顺序验收

- 验收点 D1：任务拆分完整（shared/header/footer + 首页 + 其他页面）。
- 验收点 D2：顺序固定执行：shared -> home -> other pages。
- 验收点 D3：每任务状态流转与落库正确，且“先落库后 SSE”。

### E. 断点恢复与进度验收

- 验收点 E1：进度最小单位为块组件任务。
- 验收点 E2：成功单元不回滚；恢复时跳过 done，从首个未成功单元继续。
- 验收点 E3：断线后快照对齐，进度条与任务状态不倒退。

### F. 页面可视化与组件级能力验收

- 验收点 F1：每完成一个页面类型后，立即可视化渲染并开放对应 Tab 编辑。
- 验收点 F2：组件支持微调/重建/文本编辑/AI再生成。
- 验收点 F3：组件 meta 信息参与生成上下文，改动写回 result_ref/配置映射。

### G. 预览链接与语言验收

- 验收点 G1：未发布可视化态下，仅重写站内页面类型链接到编辑预览路由。
- 验收点 G2：外部链接不被误重写。
- 验收点 G3：方案语言 `plan_locale` 与内容语言 `default_locale` 语义分离且生效。

## 10B. E2E 用例清单（新增）

### E2E-01 第一阶段方案生成基础流

- 步骤：进入工作台 -> 打开方案弹窗 -> 触发 `post-start-plan` -> 观察 SSE。
- 断言：
  - 仅出现 `start/progress/chunk/done/error`
  - Markdown 原文与预览同步更新
  - 不出现 build guard/任务进度更新

### E2E-02 第一阶段微调与重建 Tab 流

- 步骤：在右侧切换微调/重建，分别输入并发送。
- 断言：
  - prompt_mode 与 Tab 一致
  - 微调仅改目标范围，重建全量重写
  - 手风琴默认收起，手动可展开

### E2E-03 第一阶段确认后刷新进入第二阶段

- 步骤：点击确认方案。
- 断言：
  - confirmed.* 落库成功
  - 页面刷新到第二阶段上下文
  - 持久化失败时不刷新并显示错误

### E2E-04 第二阶段任务方案确认弹窗

- 步骤：生成第二阶段任务方案 -> 打开确认弹窗 -> 微调/重建 -> 确认。
- 断言：
  - Markdown 显示完整
  - 右侧 SSE 模式可切换且不混流
  - 弹窗关闭受保护（不可误触关闭）

### E2E-05 第二阶段确认后启动选择

- 步骤：确认任务方案后处理提示框。
- 断言：
  - 显示“已保存，是否立即生成”
  - 立即生成会启动执行与 SSE
  - 稍后生成不自动开跑

### E2E-06 任务执行顺序与实时进度

- 步骤：启动执行并观察任务队列。
- 断言：
  - shared -> home -> other 顺序正确
  - 每任务状态变化都实时刷新 UI
  - SSE 事件含进度字段（completed/total/percent）

### E2E-07 断点恢复语义

- 步骤：在任务间与任务内分别制造中断后恢复。
- 断言：
  - 任务间中断：从下一个未完成继续
  - 任务内中断：从当前任务重跑
  - done 任务被跳过且不回滚

### E2E-08 页面实时可视化与 Tab 激活

- 步骤：执行过程中观察页面类型 Tab。
- 断言：
  - 页面完成后立刻可视化渲染
  - 对应 Tab 立即可点击进入编辑

### E2E-09 组件级编辑与 AI 再生成

- 步骤：打开页面可视化编辑，对组件执行微调/重建/文本编辑/AI再生成。
- 断言：
  - 组件改动即时预览
  - 组件 meta 作为上下文生效
  - 失败不影响其它组件编辑

### E2E-10 预览内链接重写

- 步骤：在未发布可视化态点击页面内链接。
- 断言：
  - 站内页面类型链接跳转到对应编辑预览页
  - 外部链接保持原语义

### E2E-11 只读历史方案预览与复制

- 步骤：进入历史确认方案预览入口。
- 断言：
  - 第一阶段与第二阶段确认方案可查看可复制
  - 显示“已确认，只读”
  - 无编辑入口

### E2E-12 语言策略验证

- 步骤：设置 `plan_locale` 与 `default_locale` 不同并执行两阶段。
- 断言：
  - 方案与任务说明语言使用 `plan_locale`
  - 页面内容主生成语言使用 `default_locale`
  - 产物记录 source/target locale

### E2E-13 全链路收官冒烟（新增）

- 步骤：从第一阶段方案生成开始，完成两阶段确认、任务执行、页面可视化产出、发布流程与域名就绪链路。
- 断言：
  - 两阶段核心流程无阻断完成；
  - 任务进度与恢复语义符合约束；
  - 发布后目标域名可访问站点首页与关键页面；
  - 域名访问结果与工作台“已完成/可访问”状态一致。

## 11. 关联说明

- WLS/hosts/运行时基建：见 `Weline/Server` 侧计划。
- 工作台壳层、域名与供应商流程：见 `Weline/Websites` 侧计划。
- 本文件仅保留 PageBuilder 建站中台执行计划，不再拆分同目录多份计划文档。

## 12. 细分任务列表与进度表示（新增）

### 12.1 任务清单（执行拆分，细化版）

> 状态标记：
> - `- [x]` = done（已完成）
> - `- [~]` = in_progress（进行中）
> - `- [ ]` = todo（待开始）
>
> 本轮基线说明：当前仅完成“计划重写与架构定稿”，其余研发任务尚未进入代码实现，故统一保留为 `todo`。

#### A. 本轮计划与架构基线

- [x] T00 重写第一阶段/第二阶段方案与虚拟主题链路计划（status=done, progress=100%, owner=architecture, note=2026-04-17 已按老板最新要求完成文档重构与任务拆分）

#### B. 第一阶段：共享优先的块化方案生成

- [ ] T01 第一阶段主队列编排器（status=todo, progress=0%, owner=backend-ai, note=创建 stage1 主任务图与共享前置依赖）
- [ ] T02 `buildStageOneSharedPlanPrompt(...)` 与共享规划校验器（status=todo, progress=0%, owner=prompt-engineering, note=输出主题设计 + Header/Footer + shared_prompt_context；须遵守 §1A/§13.2.6 具体性契约；当前整站参考实现见 `AiSiteExecutionBlueprintService::buildAiPlanPrompt`）
- [ ] T03 共享规划持久化与上下文哈希管理（status=todo, progress=0%, owner=backend, note=保存 theme_context_snapshot/shared_context_hash）
- [ ] T04 `buildStageOnePagePlanPrompt(...)` 与页面任务输入装配器（status=todo, progress=0%, owner=prompt-engineering, note=注入主题设计与共享规划保证主题连续性；须遵守 §1A/§13.2.6；字段样例须可落地非方向性）
- [ ] T05 页面类型并发队列与冲突控制（status=todo, progress=0%, owner=backend-queue, note=固定一页面一任务，控制共享上下文版本）
- [ ] T06 第一阶段块树装配器（status=todo, progress=0%, owner=backend, note=增量生成 plan_book.markdown + structured_plan + block_index）
- [ ] T07 第一阶段内联工作台 UI（status=todo, progress=0%, owner=frontend, note=共享区/页面 Tab/总进度/队列状态）
- [ ] T08 第一阶段页面级 AI 操作（status=todo, progress=0%, owner=frontend+backend, note=微调页面/重建页面/新增块）
- [ ] T09 第一阶段块级操作 API（status=todo, progress=0%, owner=backend-api, note=块 refine/rebuild/create/delete/move）
- [ ] T10 第一阶段块 hover 交互与即时重装配（status=todo, progress=0%, owner=frontend, note=hover 菜单、局部刷新、保留草稿输入）
- [ ] T11 第一阶段 SSE + 轮询状态面板（status=todo, progress=0%, owner=sse-runtime, note=只读进度，页面完成即点亮 Tab）
- [ ] T12 第一阶段确认持久化（status=todo, progress=0%, owner=backend, note=写 confirmed plan + block_index + shared_prompt_context）

#### C. 第二阶段：块任务细化与任务方案装配

- [ ] T13 第二阶段 confirmed plan 解析器（status=todo, progress=0%, owner=backend, note=从第一阶段确认版恢复共享块与页面块）
- [ ] T14 `buildStageTwoSharedTaskPrompt(...)` 与共享块任务细化器（status=todo, progress=0%, owner=prompt-engineering, note=先补齐 Header/Footer/共享素材与字段；须遵守 §1A/§13.3.6；参考 `AiSiteVirtualThemePlanService::buildTaskPlanPromptBase` 契约）
- [ ] T15 `buildStageTwoPageTaskPrompt(...)` 与页面块任务细化器（status=todo, progress=0%, owner=prompt-engineering, note=并发前统一注入 stage2_context_snapshot；story_goal/content_fill_rule/样例须可执行非元指令；分批见 `buildTaskPlanGenerationBatchPrompt`）
- [ ] T16 第二阶段上下文快照版本控制（status=todo, progress=0%, owner=backend, note=context_hash 变化时让旧页面任务失效重排）
- [ ] T17 第二阶段块任务装配器（status=todo, progress=0%, owner=backend, note=生成每页块任务方案 + 任务卡片视图数据）
- [ ] T18 第二阶段内联工作台 UI（status=todo, progress=0%, owner=frontend, note=页面 Tab、任务卡片、依赖与状态）
- [ ] T19 第二阶段页面级 AI 操作（status=todo, progress=0%, owner=frontend+backend, note=微调页面任务/重建页面任务/新增块任务）
- [ ] T20 第二阶段块任务 API（status=todo, progress=0%, owner=backend-api, note=任务 refine/rebuild/create/delete/move）
- [ ] T21 第二阶段确认持久化（status=todo, progress=0%, owner=backend, note=写 stage2 confirmed plan + execution_blueprint）

#### D. 虚拟主题生成与页面可视化

- [ ] T22 `virtual_theme_build_tree` 派生器（status=todo, progress=0%, owner=backend, note=从 stage2 confirmed block tree 生成 site->page->block->component 树）
- [ ] T23 虚拟主题页面并发生成器（status=todo, progress=0%, owner=backend-queue, note=一页面一任务并发生成）
- [ ] T24 页面完成即预览/可视化编辑接入（status=todo, progress=0%, owner=frontend+pagebuilder, note=页面 ready 即开放预览与编辑）
- [ ] T25 虚拟主题块级重建接口（status=todo, progress=0%, owner=backend-api, note=支持页面或块局部重建）
- [ ] T26 最终发布门禁与确认流程（status=todo, progress=0%, owner=backend+product, note=虚拟主题确认后才允许发布）

#### E. 通用契约、状态与质量保障

- [ ] T27 scope_json/schema 升级与兼容层（status=todo, progress=0%, owner=backend, note=新增 stage1/stage2/virtual_theme 持久化字段）
- [ ] T28 队列 job envelope 与恢复引擎（status=todo, progress=0%, owner=backend-queue, note=任务状态、依赖、stale 检测、失败续跑）
- [ ] T29 SSE/轮询统一状态协议（status=todo, progress=0%, owner=sse-runtime, note=三阶段统一 status payload）
- [ ] T30 `session_public_id|website_public_id` 双路由 API 支持（status=todo, progress=0%, owner=backend-api, note=所有状态与块操作均可双标识访问）
- [ ] T31 反写死/覆盖率/示例泄漏校验器（status=todo, progress=0%, owner=validation, note=阶段一阶段二统一阻断非法输出）
- [ ] T32 第一阶段单测/集成测试（status=todo, progress=0%, owner=qa, note=共享优先 + 页面并发 + 装配校验）
- [ ] T33 第二阶段单测/集成测试（status=todo, progress=0%, owner=qa, note=共享块任务优先 + 上下文一致性校验）
- [ ] T34 虚拟主题/恢复/E2E 验证（status=todo, progress=0%, owner=qa, note=页面 ready、块操作、发布门禁全链路覆盖）

### 12.1C 任务-细节对齐规则（新增）

- MUST 任何新增计划细节都在 12.1/12.1B 追加至少一条可执行任务，不允许“有细节无任务”。
- MUST 任务标题可直接映射到代码改动点与验收用例，不使用抽象空任务。
- MUST 在任务状态更新前，先检查该任务是否覆盖对应细节条款；未覆盖不得标记完成。
- MUST 提示词相关任务（T02/T04/T14/T15 等）完成定义须与 **§1A、§13.2.6、§13.3.6** 可逐条对照；交付物含可评审的 GOOD/BAD 维度或等价自动化校验（与 `AiSiteExecutionBlueprintService` / `AiSiteVirtualThemePlanService` 行为一致）。

### 12.2 任务状态字段（统一）

- 每个任务 MUST 具备：
  - `task_id`（如 `T1`）
  - `title`
  - `status`（`todo|in_progress|blocked|done`）
  - `progress_percent`（0-100）
  - `owner`
  - `updated_at`
  - `note`（当前进展/阻塞说明）

### 12.3 总进度计算规则

- 总进度 MUST 由任务加权汇总，默认等权重。
- 单任务进度建议口径：
  - `todo` = 0%
  - `in_progress` = 1%~99%
  - `blocked` = 保持当前百分比并标记阻塞
  - `done` = 100%
- 总进度示例公式：
  - `overall_progress = round(sum(task.progress_percent) / task_count)`

### 12.4 展示规则（工作台/任务面板）

- MUST 展示：
  - 总进度条（overall_progress）
  - 每个细分任务的状态标签与百分比
  - 当前进行中任务高亮
  - 阻塞任务单独标识
- MUST 在 SSE 状态变化后实时刷新任务列表与总进度，不需手动刷新页面。

### 12.5 任务完成硬性门禁（新增）

- MUST 执行“一个任务对应一个用例”规则：每完成一个细分任务（12.1 任务清单内的全部任务，含 T1~T39），必须新增或更新至少一个对应冒烟测试用例。
- MUST 在将任务状态置为 `done` 前，先完成该任务对应用例的冒烟执行并通过。
- MUST 遵循状态更新顺序：
  1) 实现任务代码  
  2) 编写/更新对应用例  
  3) 跑该任务冒烟并通过  
  4) 更新任务状态为 `done`
- MUST 若冒烟未通过，则任务状态保持 `in_progress` 或 `blocked`，不得标记为完成。
- MUST 在任务记录中写入对应用例标识与最近一次冒烟结果（通过/失败、时间、摘要）。

### 12.6 冒烟失败后的修复红线（新增）

- MUST 在冒烟测试发现问题时立即进入修复流程，优先修复真实问题本身。
- MUST NOT 通过补丁式绕过、条件跳过、关闭断言等方式“让测试看起来通过”。
- MUST NOT 使用硬编码/假数据/分支作弊仅为跑通用例而牺牲真实业务逻辑。
- MUST 在问题修复后重新执行该任务用例与相关回归冒烟，确认真实通过后才能继续推进任务状态。
- MUST 将“问题原因 -> 修复方案 -> 复测结果”记录到任务进展中，保证可审计。

### 12.7 最终全链路验收门禁（新增）

- MUST 在全部细分任务完成后执行 `E2E-13 全链路收官冒烟` 作为最终门禁用例。
- MUST 仅当 `E2E-13` 通过时，任务整体状态才允许标记为最终完成。
- MUST `E2E-13` 通过后满足最终业务结果：可通过目标域名访问该站点（至少首页可访问，关键页面可达）。

### 12.8 最终创建站点阶段展示与域名门禁（新增）

- MUST 在“最终创建站点”阶段向用户明确展示以下入口信息：
  - 预览地址（Preview URL）
  - 正式地址（Production URL）
  - 前往 PageBuilder 页面管理的入口链接（用于继续编辑页面）
- MUST 在 UI 中区分“预览地址”与“正式地址”语义，避免用户误用。
- MUST 在会话数据落盘到最终建站阶段前执行域名联通性校验（正式环境）。
- MUST 在域名联通性未通过时阻断最终建站落库流程，并给出可操作提示。
- MUST NOT 在域名未通过时创建正式站点记录或进入最终完成态。
- MUST 在域名通过后才允许进入最终建站落库，并更新为可访问正式地址状态。

### 12.9 第一阶段域名选择与推荐细节（新增）

- MUST 在第一阶段使用“域名选择”标签（或等价独立区域）承载域名选择流程，不与方案文本输入混在同一交互通道。
- MUST 在可选域名列表中展示“可创建网站但尚未创建网站”的域名供用户选择。
- MUST 提供 AI 推荐域名入口；正式环境下推荐域名需通过弹窗供用户确认选择。
- MUST 在正式环境下对推荐域名执行可用性检测，且检测规则按所选供应商生效（不同供应商可有不同校验策略）。
- MUST 在本地供应商模式下采用简化校验策略（不要求与正式供应商同等级外部校验链路）。
- MUST 在域名未通过可用性检测时阻断“用于正式建站”流程，并提示用户更换域名或供应商。
- MUST 在域名通过后将选中域名与供应商信息写入会话 scope，供后续两阶段与最终落库使用。

## 13. 两阶段块化方案与虚拟主题工作流定版（2026-04-17）

> 本节按老板最新口径重写第一阶段方案生成、第二阶段块任务细化、虚拟主题后台生成、API/队列/SSE/进度/数据结构。若与旧描述冲突，以本节为准。

### 13.1 本轮重构要解决的核心问题

- 旧方案把第一阶段当成“先出一篇方案文案”，导致后续还要二次拆块，任务边界不稳定。
- 旧方案把阶段一/二的长生成过度绑定 SSE，会造成串流、超时、页面切换困难。
- 旧方案没有把共享主题、Header、Footer 作为所有页面的前置事实源，导致并发页面生成时主题连续性差。
- 旧方案没有为块级微调/删除/新增建立统一结构化数据与接口，后续难以按块拆任务、按块生成页面。

### 13.2 第一阶段定版：一句话需求 -> 共享优先的块化方案书

#### 13.2.1 阶段一目标

- 把一句话需求扩展成一份可确认的块化方案书。
- 方案书的最小单元不是段落，而是 `shared block` 与 `page block`。
- 每个块在第一阶段就必须写清：
  - 这个块要做什么；
  - 页面上真实显示什么；
  - 为什么这样设计；
  - 第二阶段要补哪些资料；
  - 后续可编辑哪些字段。

#### 13.2.2 阶段一 6 层生成架构

1. `requirement_expand`
   - 解析一句话需求，沉淀 `explicit_facts / safe_inferences / pending_variables`。
2. `theme_design`
   - 定义站点定位、品牌语气、视觉方向、CTA 方向、SEO 总策略。
3. `shared_plan`
   - 生成 Header/Footer/共享 CTA/共享信任位，产出 `shared_prompt_context`。
4. `page_plan_fanout`
   - 按页面类型并发生成单页块化方案；一页面一个任务。
5. `plan_assemble`
   - 把共享块 + 页面块装配成统一方案书与结构化块树。
6. `inline_edit`
   - 用户在页面 Tab 与块级操作上继续微调、重建、删除、新增块。

#### 13.2.3 阶段一共享提示词（必须独立）

建议固定函数：

- `buildStageOneSharedPlanPrompt(...)`
- `buildStageOnePagePlanPrompt(...)`
- `validateStageOneSharedPlan(...)`
- `validateStageOnePagePlan(...)`

`buildStageOneSharedPlanPrompt(...)` 必须输入：

- 一句话需求原文
- 页面类型集合
- 已知站点事实
- 语言/locale
- 反写死规则
- 示例格式（仅结构，不可抄内容）

`buildStageOneSharedPlanPrompt(...)` 必须输出：

- `theme_design`
- `header_block`
- `footer_block`
- `shared_fields`
- `shared_navigation_strategy`
- `shared_cta_strategy`
- `shared_prompt_context`
- `reason_summary`

#### 13.2.4 阶段一页面提示词（必须独立）

`buildStageOnePagePlanPrompt(...)` 必须输入：

- `theme_context_snapshot`
- `shared_prompt_context`
- `page_type`
- `page_goal`
- `locale`
- `anti_hardcode_rules`
- 当前页面已有块（微调/重建时）

`buildStageOnePagePlanPrompt(...)` 必须输出：

- `page_key`
- `page_goal`
- `blocks[]`
- `page_reason_summary`
- `page_context_hash`

其中每个 `block` 至少包含：

```json
{
  "block_key": "home.hero",
  "block_type": "content",
  "title": "首屏 Hero",
  "goal": "5 秒内说明网站价值并引导点击 CTA",
  "implementation_detail": "左文右图 + 双 CTA + 3 条信任短句",
  "realtime_content": {
    "headline": "把发票、收入、税务一次理清（示例：须替换为站点专属一句价值）",
    "supporting_copy": ["报税前不再翻三天收据；导入银行流水与发票后自动归类。"],
    "cta": [{"label": "免费试用 30 天", "target": "form:lead"}],
    "media": [{"kind": "illustration", "rule": "抽象流程图，不写死真实截图"}],
    "editable_slots": ["headline", "subheadline", "cta", "hero_visual"]
  },
  "content_source": ["safe_inference", "editable_field"],
  "style_direction": "大标题 + 强 CTA + 主题主色",
  "responsive_rule": "移动端改纵向堆叠",
  "seo_role": "首页主关键词承接",
  "reason": "首屏承担定位与首个转化动作",
  "completion_rule": "标题/说明/CTA/主视觉均可视化可编辑"
}
```

#### 13.2.5 阶段一装配规则

- 共享块先生成，页面块后生成。
- 页面块完成一个就装配一个，不等待全部页面。
- 装配结果同时维护：
  - 用户阅读版 Markdown；
  - 机器消费版 `structured_plan`；
  - 块级索引 `block_index`；
  - 页面 Tab 数据源 `page_tabs_state`。
- 装配逻辑必须是结构化优先，Markdown 只作为展示结果，不是事实源。

#### 13.2.6 阶段一提示词：具体性契约与 GOOD/BAD（与代码对齐）

- **代码锚点**：`AiSiteExecutionBlueprintService::buildAiPlanPrompt()`、`buildPageMarkdownTemplate()`（整站/聚合方案；共享/单页拆分实现时须逐条继承本节）。
- **CONCRETENESS CONTRACT（五条）**：同 **§1A「第一阶段：提示词具体性契约」** 中五条枚举（此处不重复展开，避免双源漂移）。
- **GOOD vs BAD 维度（评审用）**：
  - `field_plan.sample`：BAD「标题围绕核心价值展开」→ GOOD「30 分钟上手的轻量记账工具，给独立创作者用」（须与真实站点一致，示例仅说明风格）。
  - `blocks[].content`：BAD 单句「突出品牌价值」→ GOOD 多句可给客户读的文案，含具体 CTA 与信任点。
  - `navigation_plan.header_items`：BAD 空数组或 `Link1` → GOOD 非空 `{label,href}` 且与选中页面类型一致。
- **Final audit**：返回前逐块自检；不通过则重写（见 §1A）。

### 13.3 第二阶段定版：确认块树 -> 块任务细化 -> 任务方案书

#### 13.3.1 阶段二目标

- 把第一阶段确认块树进一步细化为“每个块具体要补齐哪些资料、字段、素材、配置”的任务方案书。
- 第二阶段仍以块为最小单元，但强调“可执行的任务资料”，不是重复写第一阶段的布局结论。
- 第二阶段完成后，必须得到可直接驱动虚拟主题生成的块任务树。

#### 13.3.2 阶段二 5 层生成架构

1. `confirmed_plan_parse`
   - 读取第一阶段确认版 shared/page blocks。
2. `shared_task_refine`
   - 先细化 Header/Footer/共享组件的字段、素材、导航、CTA、logo、可编辑配置。
3. `page_task_fanout`
   - 按页面并发细化块任务；同页内块可小批并发，但必须共享同一上下文快照。
4. `task_plan_assemble`
   - 组装为每页可阅读任务方案书与 `page_block_tasks`。
5. `task_inline_edit`
   - 用户继续按页面/按块任务微调、重建、删除、新增。

#### 13.3.3 阶段二共享上下文一致性规则

- 阶段二并发前必须冻结 `stage2_context_snapshot`。
- 快照至少包含：
  - `theme_context_snapshot`
  - `shared_block_tasks_summary`
  - `content_tone`
  - `visual_direction`
  - `global_field_matrix`
  - `prompt_version`
  - `anti_hardcode_rules`
- 所有页面块任务并发时都必须带 `stage2_context_hash`。
- 若共享块任务或主题上下文被修改，则未完成页面任务必须重排，防止主题风格漂移。

#### 13.3.4 阶段二块任务数据结构

```json
{
  "task_key": "home.hero.copy_and_assets",
  "page_key": "home",
  "block_key": "home.hero",
  "task_goal": "补齐 Hero 文案、视觉素材位、CTA 配置与可编辑字段",
  "implementation_steps": [
    "补齐主标题、副标题、CTA 及跳转",
    "定义主视觉素材位与默认约束",
    "输出表单锚点、SEO 承接与移动端差异"
  ],
  "realtime_output": "首页 Hero 出现可视化标题、双按钮、主视觉、信任短句",
  "editable_meta": {
    "fields": ["headline", "subheadline", "cta_primary", "cta_secondary", "hero_visual"]
  },
  "dependencies": ["shared:header", "shared:footer"],
  "materialize_policy": "page",
  "reason": "Hero 是首页第一个必须可见且可编辑的高权重模块",
  "completion_rule": "字段完整、素材位完整、预览可见且可编辑",
  "context_hash": "stage2:..."
}
```

#### 13.3.5 阶段二提示词分层

建议固定函数：

- `buildStageTwoSharedTaskPrompt(...)`
- `buildStageTwoPageTaskPrompt(...)`
- `validateStageTwoSharedTasks(...)`
- `validateStageTwoPageTasks(...)`

`buildStageTwoSharedTaskPrompt(...)` 负责补齐：

- Header logo/config/navigation/CTA/mobile behavior
- Footer 信息分组、政策链接、信任区块、联系位、社媒位
- 共享字段矩阵与默认值

`buildStageTwoPageTaskPrompt(...)` 负责补齐：

- 每个页面块需要的文案字段
- 每个块需要的素材位
- 每个块的 CTA 与内链
- SEO/响应式/组件配置
- 第二阶段完成后页面实际可见内容

#### 13.3.6 阶段二提示词：具体性契约与 GOOD/BAD（与代码对齐）

- **代码锚点**：`AiSiteVirtualThemePlanService::buildTaskPlanPromptBase()`；若启用分批：`buildTaskPlanGenerationBatchPrompt()`（分批与整包契约一致）。
- **CONCRETENESS CONTRACT（六条）**：同 **§1A「第二阶段：提示词具体性契约」** 中六条枚举。
- **GOOD vs BAD 维度（评审用）**：
  - `task_script.story_goal`：BAD「撰写首页 Hero 文案，突出产品价值」→ GOOD「访客在 5 秒内看到一句话价值并点击 [免费试用 30 天]」（须与真实方案一致）。
  - `task_script.content_fill_rule`：BAD「按品牌语气补充正文」→ GOOD 枚举字段并给出每关键字段示例句或取值范围。
  - `field_content_requirements[].sample`：BAD「突出卖点」→ GOOD 含数字或场景的具体一句。
  - 共享导航项：BAD「导航1」→ GOOD `{label, page_type|href}` 具名可点。
- **Final audit**：返回前逐任务自检（见 §1A）。

### 13.4 虚拟主题后台生成定版

#### 13.4.1 输入来源

- 只允许使用第二阶段确认版 `page_block_tasks + shared_block_tasks + build_tree_seed`。
- 不允许绕过第二阶段确认版直接用运行时口述需求建树。

#### 13.4.2 建树规则

- 树节点固定为：`site -> shared -> page -> block -> component -> slot`。
- `shared` 节点必须先建，再建 `page` 节点。
- `page` 节点下块顺序必须与第二阶段确认版一致。
- `component` 与 `slot` 节点必须能映射到 PageBuilder 可视化编辑目标。

#### 13.4.3 页面生成规则

- 一个页面一个后台任务，允许并发。
- 页面内块按树顺序装配，必要时块内组件再串行。
- 页面达到“可预览”条件就立即开放页面预览与编辑，不等待全站完成。
- 页面重建时只重排当前页树与受影响共享节点，不全站回滚。

### 13.5 队列、SSE、轮询、恢复协议

#### 13.5.1 队列任务类型

- `stage1.requirement_expand`
- `stage1.shared.theme_design`
- `stage1.shared.header_footer`
- `stage1.page_plan.generate`
- `stage1.plan.assemble`
- `stage1.block.refine`
- `stage1.block.rebuild`
- `stage2.shared.tasks`
- `stage2.page.tasks`
- `stage2.plan.assemble`
- `stage2.block.refine`
- `stage2.block.rebuild`
- `virtual_theme.tree.build`
- `virtual_theme.page.build`
- `virtual_theme.page.rebuild`

#### 13.5.2 进度事件协议

建议统一事件体：

```json
{
  "stage": "stage1|stage2|virtual_theme",
  "job_key": "...",
  "job_type": "...",
  "status": "queued|running|done|failed|stale",
  "progress_percent": 45,
  "session_public_id": "...",
  "website_public_id": "...",
  "page_key": "home",
  "block_key": "home.hero",
  "message": "首页方案已完成并装配",
  "context_hash": "...",
  "updated_at": "..."
}
```

- 长流程使用 SSE/轮询同步上述状态体，不推长篇 AI 原始文本。
- 轻量微调/局部重建可以附加短时 `chunk` 文本，但最终仍要落结构化结果。

#### 13.5.3 恢复语义

- `done` 块/任务永不自动重跑。
- `stale` 表示上下文版本过期，需重排。
- `failed` 保留已完成前置块，允许局部重试。
- 恢复时从“第一个非 done 且非 cancelled 的节点”继续。
- 页面已 ready 的预览状态在恢复后必须保留。

### 13.6 API 契约建议（按网站或会话统一寻址）

所有写接口统一支持：

```json
{
  "session_public_id": "optional",
  "website_public_id": "optional",
  "stage": "stage1|stage2|virtual_theme",
  "page_key": "optional",
  "block_key": "optional",
  "action": "refine|rebuild|create|delete|move|confirm",
  "user_prompt": "optional",
  "payload": {}
}
```

建议最少实现：

- `startStageOnePlan(...)`
- `getStageOneStatus(...)`
- `operateStageOneBlock(...)`
- `confirmStageOnePlan(...)`
- `startStageTwoPlan(...)`
- `getStageTwoStatus(...)`
- `operateStageTwoBlockTask(...)`
- `confirmStageTwoPlan(...)`
- `startVirtualThemeBuild(...)`
- `getVirtualThemeStatus(...)`
- `operateVirtualThemeBlock(...)`
- `publishVirtualThemeSite(...)`

### 13.7 自动校验与门禁

#### 13.7.1 第一阶段校验

- 页面覆盖必须与用户选中的页面类型一致。
- 所有共享块与页面块必须有 `implementation_detail + realtime_content + reason + completion_rule`。
- 未提供事实不得写死。
- 页面任务若缺失 `shared_context_hash`，直接视为非法输出。
- **反方向性（与提示词/实现一致）**：若 `plan_json` / Markdown 中大量出现仅含「围绕/突出/说明/完善/优化/待补充」而无具体导航文案、字段样例、可点链接，或 `navigation_plan.header_items` 为空/占位，视为不合格，须重生成或拒收（与 `AiSiteExecutionBlueprintService` 侧校验策略对齐）。

#### 13.7.2 第二阶段校验

- 所有块任务必须可追溯到第一阶段 `block_key`。
- 所有块任务必须带 `stage2_context_hash`。
- 不允许新增未确认正式页面/正式块；新增建议只能以 `suggested_block` 形式存在，需用户确认后入树。
- **反元指令（与提示词/实现一致）**：若 `story_goal` 仅描述写作动作而非可见结果、`content_fill_rule` 未枚举字段与示例、或字段样例为「待补充/突出卖点/详见后文」，视为不合格（与 `AiSiteVirtualThemePlanService` 侧校验策略对齐）。

#### 13.7.3 虚拟主题校验

- 树节点必须完整映射 shared/page/block/component/slot。
- 页面生成顺序与块顺序必须匹配确认版任务树。
- 页面 ready 时必须生成对应的编辑入口与预览入口。

### 13.8 本轮实施落点（建议函数/服务）

- **已实现/对齐中的提示词入口（评审对照）**：`AiSiteExecutionBlueprintService`（阶段一聚合方案：`buildAiPlanPrompt` 等）、`AiSiteVirtualThemePlanService`（阶段二任务方案：`buildTaskPlanPromptBase`、`buildTaskPlanGenerationBatchPrompt` 等）。拆分式 `buildStageOneSharedPlanPrompt` / `buildStageOnePagePlanPrompt` 落地后须继承 **§13.2.6 / §1A** 与 **§13.3.6 / §1A** 契约。
- `StageOnePlanQueueOrchestrator`
- `StageOneSharedPlanPromptBuilder`
- `StageOnePagePlanPromptBuilder`
- `StageOnePlanAssembler`
- `StageOneBlockOperationService`
- `StageTwoTaskQueueOrchestrator`
- `StageTwoSharedTaskPromptBuilder`
- `StageTwoPageTaskPromptBuilder`
- `StageTwoTaskAssembler`
- `StageTwoBlockTaskOperationService`
- `VirtualThemeBuildTreeBuilder`
- `VirtualThemePageBuildOrchestrator`
- `AiSiteProgressStreamService`
- `AiSiteStatusPollingService`
- `AiSiteBlockOperationController`

### 13.9 验收标准（覆盖本次重写要求）

- 验收 A：第一阶段必须先生成主题设计与 Header/Footer，再生成页面类型方案；页面类型任务支持并发且共享同一主题上下文。
- 验收 B：第一阶段方案本身是块树；每个块都能 hover 微调/删除，页面可新增块。
- 验收 C：第一阶段不再依赖方案弹窗，方案直接以内联工作台展示在第一阶段下面。
- 验收 D：第二阶段按块任务细化资料，所有并发块任务使用统一上下文快照，主题连续性不漂移。
- 验收 E：第二阶段页面 Tab 内可微调/删除/新增块任务，确认后再进入虚拟主题后台生成。
- 验收 F：所有长流程都走后台队列；SSE 只负责进度与轻量局部重建；状态可通过轮询恢复。
- 验收 G：所有块操作 API 都支持 `session_public_id|website_public_id` 双寻址。
- 验收 H：虚拟主题按块树生成页面，页面完成即可预览与编辑，最终确认无误后才允许发布。
