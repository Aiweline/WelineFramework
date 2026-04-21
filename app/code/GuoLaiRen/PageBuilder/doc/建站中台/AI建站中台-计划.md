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

### 1A.0 全链路状态机与真相源（MUST）

> 本小节用于把后续所有“方案生成 / 任务细化 / 虚拟主题生成 / 发布”的描述收口成同一条可执行运行图。任何旧章节若仍出现“阶段一方案弹窗作为主入口”“自动进入 build”“只改 Markdown 不改结构化数据”等描述，均视为历史口径，必须按本状态机修正。

- MUST 全链路执行顺序固定为：`stage0_input` -> `stage1_shared` -> `stage1_page_fanout` -> `stage1_assemble` -> `stage1_confirm` -> `stage2_shared_task` -> `stage2_page_task` -> `stage2_confirm` -> `virtual_theme_build` -> `virtual_publish_ready` -> `release_gate`。
- MUST `stage0_input` 前置条件：用户一句话需求、页面类型、语言策略、站点/会话 ID 已确定；输出：`source_signature`、`plan_locale`、`page_type_requests[]`。
- MUST `stage1_shared` 前置条件：`source_signature` 存在；输出：`theme_context_snapshot`、`shared:header`、`shared:footer`、`shared_prompt_context`；通过门禁：共享上下文 hash 已持久化。
- MUST `stage1_page_fanout` 前置条件：共享规划完成；输出：每个页面类型的 `page_blocks[]`；通过门禁：每个页面块具备 `implementation_detail/realtime_content/reason/completion_rule/editable_fields/context_hash`。
- MUST `stage1_assemble` 输出：`plan_book.markdown + plan_book.structured + block_index`；失败分支：页面级任务可局部重试，已完成页面不得自动重跑。
- MUST `stage1_confirm` 输出：确认版 `confirmed_plan_signature`；确认后第一阶段草案变只读，后续修改必须生成新版本或操作日志。
- MUST `stage2_shared_task` 与 `stage2_page_task` 继承 `confirmed_plan_signature` 与 `stage2_context_hash`；hash 不一致的任务必须标记 `stale`。
- MUST `stage2_confirm` 输出：确认版 `execution_plan_signature` 与 `page_block_tasks/shared_block_tasks`；虚拟主题只允许消费该确认版。
- MUST `virtual_theme_build` 输出：`site -> shared -> page -> block -> component -> slot` 构建树与页面预览结果；页面 ready 后允许单页预览/编辑，不等待全站完成。
- MUST `release_gate` 同时检查：任务状态、域名门禁、预览/正式 URL、E2E 证据、语言策略、AI 适配器与提示词版本审计。
- MUST 真相源优先级固定为：`build_tasks[*].status`（任务完成真相） > `plan_workbench.confirmed.*` / `execution_plan_signature`（确认版真相） > `scope_json`/`result_ref`（持久化数据真相） > SSE/polling（展示同步） > `build_checkpoint`（恢复索引）。

### 第一阶段：块化方案生成工作台（老板最新口径）

- MUST 第一阶段不再使用方案弹窗；方案工作台直接写在第一阶段主区域下方，包含共享区、页面 Tab、总进度区、队列状态区、AI 操作区。
- MUST 第一阶段的“方案”本身就是块树，而不是先写一篇自由 Markdown 再二次拆块；最小规划单位固定为 `shared block` 与 `page block`。
- MUST 第一阶段必须先生成**具体主题规划**，并在共享 Tab 内展示；主题规划不是方向描述，必须包含已确认/建议的色系、字体/圆角/留白倾向、主题宗旨、品牌语气、适配页面氛围、以及“为什么根据用户一句话需求选择该风格”的依据。
- MUST 主题规划必须给出可被后续页面提示词复用的硬约束，例如：主色/辅色/强调色、背景/文字/按钮色、视觉关键词、禁用风格、CTA 语气、内容密度、首屏情绪、信任表达方式。
- MUST 用户一句话需求先进入 `需求扩展队列`，先生成 `theme_design`、`shared:header`、`shared:footer` 三类共享规划，再派发页面类型方案任务。
- MUST Header/Footer 与主题设计先完成并持久化，页面类型方案任务才允许开始；页面任务粒度固定为“一次请求 = 一个页面类型”，允许并发。
- MUST `shared:header`、`shared:footer` 后台队列完成后，立即通过 Fiber/协程并发派发其他页面类型方案任务；不得等待用户切换 Tab 或手动触发页面任务。
- MUST 页面任务请求必须强制携带：具体主题规划、色系约束、Header 规划、Footer 规划、站点定位、页面目标、反写死约束、当前页面类型上下文，保证主题连续性。
- MUST 每个页面提示词都要反复提醒 AI：所有内容块必须服从共享 Tab 中的主题宗旨、色系、语气、CTA 策略、Header/Footer 信息架构，不得生成与主题规划冲突的块。
- MUST 每个块在第一阶段就补齐：实施方式、实际规划内容、设计理由、完成判定、可编辑字段、内容来源、样式方向、响应式规则。
- MUST 用户看到的 Markdown 不是独立生成的自由文本，而是共享块与页面块按当前排序组合出来的阅读视图；调整块排序必须同步更新 `plan_book.markdown + plan_book.structured + block_index`。
- MUST 页面方案一旦完成就立即插入对应页面 Tab，允许先看先改，不等待全站方案完成。
- MUST 整个第一阶段完整生成都走后台队列；SSE 只负责读取进度、推送页面就绪/块就绪/装配完成状态；轻量微调与局部重建才允许使用短时 SSE 流式返回。

#### 第一阶段的生成顺序与并发规则（MUST）

- MUST 后台任务图固定为：`stage1.requirement_expand` -> `stage1.shared.theme_design` -> `stage1.shared.header_footer` -> `stage1.page_plan:*` -> `stage1.plan_assemble`。
- MUST `stage1.shared.theme_design` 输出统一的 `theme_context_snapshot`，至少包含：站点定位、主题宗旨、具体色系、视觉系统、风格选择原因、共享导航策略、共享 CTA、共享内容语气、SEO 总策略、禁止写死字段。
- MUST `stage1.shared.header_footer` 基于 `theme_context_snapshot` 生成共享块，并输出给页面任务复用的 `shared_prompt_context`。
- MUST 页面类型任务在主题规划与 Header/Footer 共享规划完成后立即 Fiber/协程并发执行，默认一个页面一个队列任务；同一页面内块可顺序或小批并发生成，但页面级任务必须保留共享上下文哈希。
- MUST `shared_prompt_context` 必须把主题规划转成页面提示词的硬性 system/context 条款；页面任务不得只收到“视觉方向”，必须收到具体色值/色系、主题宗旨、语气、禁用风格与选择理由摘要。
- MUST 若共享规划被微调或重建，所有尚未完成的页面任务必须重新比对 `shared_context_hash`；不一致则标记 `stale` 并等待重建。
- MUST 页面级任务完成后立即触发 `stage1.plan_assemble` 增量装配，把新页面并入总方案与页面 Tab。

#### 第一阶段的内联工作台与块级交互（MUST）

- MUST 第一阶段展示三层视图：
  - 全站总览：一句话需求、共享规划进度、页面完成度、总任务进度；
  - 共享规划区/共享 Tab：主题设计、Header、Footer 三个共享块卡片，其中主题设计必须展示色系确认、主题宗旨、风格选择原因、品牌语气、CTA 语气与禁用风格；
  - 页面 Tab 区：每个页面类型一个 Tab，进入后展示该页面的块列表。
- MUST 页面 Tab 顶部提供页面级 AI 操作：`微调当前页面`、`重建当前页面`、`新增块`。
- MUST 每个块 hover 时显示块级操作：`微调块`、`局部重建`、`删除块`；块列表底部提供 `新增块` 按钮。
- MUST 块级操作直接作用于块数据结构，不允许只改 Markdown 字符串而不改结构化数据。
- MUST 用户在页面之间切换 Tab 时保留未提交输入草稿与当前选中块状态。
- MUST 页面块编辑后立即触发该页面局部装配，更新当前页面预览与总方案书对应章节。
- MUST 共享 Tab 与页面 Tab 内的块列表支持排序；排序后的块顺序是 Markdown 阅读视图、结构化方案、第二阶段任务拆分的共同顺序来源。

#### 第一阶段的提示词分层（MUST）

- MUST 把第一阶段提示词拆为两类：
  - `buildStageOneSharedPlanPrompt(...)`：专门负责主题设计 + Header/Footer + 共享规则；
  - `buildStageOnePagePlanPrompt(...)`：专门负责单页面类型方案，输入必须包含 `theme_context_snapshot + shared_prompt_context + page_type_request`。
- MUST `buildStageOneSharedPlanPrompt(...)` 输出必须包含：主题定位、主题宗旨、具体色系与用途、字体/留白/圆角等视觉规则、风格选择原因、Header 规划、Footer 规划、共享 CTA、共享字段、共享设计理由。
- MUST `buildStageOnePagePlanPrompt(...)` 输出必须包含：页面目标、块顺序、每块实施方式、每块实时内容、每块理由、每块完成判定；且必须逐页声明“本页如何服从主题规划”，例如主色如何使用、CTA 语气如何延续、Header/Footer 如何承接。
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
- MUST 第二阶段的本质是“按第一阶段每个块的描述继续规划该块的具体执行任务”，由 AI 根据第一阶段块内容、主题规划、Header/Footer 上下文，为每个单独块拆出可执行任务细节。
- MUST 第二阶段的最小执行单元固定为 `block task`；例如 Header 的 logo 配置、导航项、CTA 配置、移动端折叠规则都属于 Header block task 的细化内容。
- MUST 每个 `block task` 必须说明：该块需要哪些 meta 数据字段、字段默认值/示例内容、内容正文/CTA/链接/素材位、配色/字体/间距/响应式规则、依赖块、完成判定、以及“为什么这样规划”。
- MUST 第二阶段先细化共享块任务，再细化页面块任务；页面块任务可以并发，但每个任务都必须携带同一个 `theme_context_snapshot` 与 `stage2_context_snapshot`，保证主题延续。
- MUST 第二阶段完成后要先组装为每页可阅读的《块任务方案》，用户可继续按页面 Tab 查看、微调、删除、增加块任务。
- MUST 第二阶段确认后，才允许进入“后台生成虚拟主题”；虚拟主题必须严格按照已确认块树与块任务生成页面结构。
- MUST 第二阶段完整生成同样走后台队列 + Fiber/协程异步并发；SSE/polling 只负责进度、轻量微调、局部重建与页面就绪通知。
- MUST 所有阶段的 AI 生成队列都统计 token 数并显示在队列信息列表；第一阶段主要展示队列信息与页面/块就绪，第二阶段在方案确认后还必须展示已确认任务的实时任务进度。
- MUST 第一阶段与第二阶段每个共享块/页面块/块任务卡片都提供“?”原因说明入口；点击后展开 `reason`/`planning_reason`，让用户看到为什么选择该主题、该块顺序、该字段、该配色字体与该内容规划。

#### 第二阶段的生成顺序与并发规则（MUST）

- MUST 后台任务图固定为：`stage2.confirmed_plan_parse` -> `stage2.shared_task_plan` -> `stage2.page_task_plan:*` -> `stage2.plan_assemble` -> `virtual_theme.tree_build` -> `virtual_theme.page_build:*` -> `virtual_theme.publish_ready_check`。
- MUST `stage2.shared_task_plan` 产出共享块任务细化包，至少包含：字段矩阵、素材位、默认值、编辑约束、共享组件依赖。
- MUST 页面块任务并发前，先生成只读 `stage2_context_snapshot`，其中包含：主题上下文、共享块任务摘要、页面级内容语气、统一提示词版本、统一反写死约束。
- MUST 第二阶段按第一阶段确认版块树 fanout：每个共享块/页面块至少生成一个 `stage2.block_task_plan` 队列任务；队列任务可按页面与块 Fiber/协程并发，但必须保留块排序与依赖关系。
- MUST 页面块任务执行时若发现 `stage2_context_hash` 变化，必须中止并重新排队，禁止用旧上下文继续生成。
- MUST 每个页面的块任务完成后立即触发该页面任务方案装配，并刷新该页面 Tab 的块任务视图。
- MUST 第二阶段方案确认后，所有 `block task` 已经确定，工作台应从“队列生成信息”切换为“任务进度信息”，实时显示每个任务的 `todo/queued/running/done/failed/stale/cancelled`。

#### 第二阶段的内联工作台与块任务交互（MUST）

- MUST 第二阶段同样以内联工作台展示，不使用确认弹窗作为主编辑容器。
- MUST 页面 Tab 内展示“设计块任务卡片”，每张卡片至少显示：块名称、任务目标、补充字段、字段示例内容、meta 数据、素材要求、配色字体、依赖、当前状态、token 用量、`?` 原因说明。
- MUST 页面级 AI 操作提供：`微调当前页面任务`、`重建当前页面任务`、`新增块任务`。
- MUST 块任务 hover 操作提供：`微调任务`、`局部重建`、`删除任务`、`编辑字段内容`；页面底部允许 `新增块任务`。
- MUST 第二阶段微调/删除/新增的结果同时更新：结构化块任务、页面装配结果、虚拟主题待生成树。
- MUST 第二阶段预览同样支持块任务排序；排序变化必须同步影响任务方案 Markdown、`page_block_tasks[].sort_order`、虚拟主题待生成树顺序。

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
- `input_tokens`
- `output_tokens`
- `total_tokens`
- `token_cost_meta`
- `updated_at`

状态字典必须统一到语义层：

| 状态 | API/队列语义 | 恢复策略 |
| --- | --- | --- |
| `todo` | 已规划但未开始 | 可入队 |
| `queued` | 已入队等待消费 | 可观察，不重复入队 |
| `running` / `in_progress` | 正在执行 | 通过 heartbeat/lease 判断是否仍活跃 |
| `blocked` | 外部条件或人工确认阻塞 | 只允许用户确认或修复后继续 |
| `done` | 已完成且通过对应门禁 | 永不自动重跑 |
| `failed` | 执行失败 | 保留已完成前置节点，按 `retry_count/max_retries` 重试或转 `blocked` |
| `stale` | 上下文 hash 过期 | 必须重排/重建，不允许继续旧产物 |
| `cancelled` | 用户显式取消 | 不得自动续跑 |

- 若底层队列仍使用 `pending|running|done|error|stop`，状态读取层必须映射到上述语义层，禁止前端混用两套状态。
- `depends_on[]` 只允许字符串 ID 数组；无依赖时必须为 `[]`，不得缺省为 `null`。
- 每个队列任务还应持久化 `idempotency_key`、`attempt_id`、`attempt_no`、`lease_expires_at`、`worker_id`、`heartbeat_ts`、`max_retries`、`stale_reason`，用于重入与失败恢复。
- 每个 AI 队列任务必须统计并展示 token 用量：`input_tokens`、`output_tokens`、`total_tokens`；若 Provider 返回更细粒度信息，写入 `token_cost_meta`，用于队列信息列表与后续成本审计。

## 6. 双轨策略（站点级二选一）

- `workspace_track=html_blocks`：页面区块轨，`blocks[] + ai_html`，按块再生成。
- `workspace_track=virtual_theme`：高级虚拟主题轨。
- 首版同站不混用两轨；默认轨切换与产品口径统一后再落地。

## 7. 接口与交互

### 7.1 第一阶段接口

- `post-start-stage1-plan`
  - 输入：`session_public_id`、一句话需求、`page_types[]`、`plan_locale`、`request_id`
  - 行为：创建阶段一主任务与共享任务，不直接同步生成。
  - 幂等：同一 `request_id` 重放只返回已存在任务图，不得重复入队。
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
  - 成功输出：`confirmed_plan_signature`
  - 失败错误码：`PLAN_NOT_READY`、`SIGNATURE_MISMATCH_STALE`、`SCHEMA_INVALID`

### 7.2 第二阶段接口

- `post-start-stage2-plan`
  - 输入：`session_public_id|website_public_id`、`request_id`
  - 行为：读取第一阶段确认版，创建共享块任务与页面块任务。
  - 前置：必须存在 `confirmed_plan_signature`；否则返回 `PLAN_REQUIRED_BEFORE_BUILD`。
- `get-stage2-plan-status`
- `post-stage2-page-refine`
- `post-stage2-page-rebuild`
- `post-stage2-block-task-refine`
- `post-stage2-block-task-rebuild`
- `post-stage2-block-task-create`
- `post-stage2-block-task-delete`
- `post-confirm-stage2-plan`
  - 成功输出：`execution_plan_signature`
  - 失败错误码：`TASK_PLAN_NOT_READY`、`SIGNATURE_MISMATCH_STALE`、`SCHEMA_INVALID`

### 7.3 虚拟主题与发布接口

- `post-start-virtual-theme-build`
  - 前置：必须存在 `execution_plan_signature`；否则返回 `PLAN_REQUIRED_BEFORE_BUILD`。
- `get-virtual-theme-build-status`
- `post-virtual-theme-block-rebuild`
- `post-virtual-theme-page-rebuild`
- `post-confirm-virtual-theme-and-publish`
  - 发布失败错误码至少包括：`DOMAIN_NOT_READY`、`PREVIEW_UNAVAILABLE`、`RELEASE_GATE_FAILED`。

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
- 同一次状态变更必须满足：至少发出一个 SSE 事件，并且下一轮 polling 能读到同一持久化状态；SSE 与 polling 冲突时以持久化状态为准并记录异常。
- SSE 事件必须支持 `event_id/seq_no/cursor`；断线重连时按 `Last-Event-ID` 或等价 cursor 补推未确认事件。
- `done/error/cancelled` 终态事件后必须完整关闭流；未关闭视为连接泄漏并进入测试失败项。

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

> 本节记录老板口径的历史演进与强制意图。若本节仍出现“方案弹窗/自动进入 build”等旧表达，以 §1A 与 §1A.0 为准：第一阶段主控制面必须是内联块化方案工作台，旧弹窗不得作为结构化决策入口。

### Summary

- 方案书与任务书文案须满足 **§1A 两阶段「提示词具体性契约」**：产出为可落地的标题/正文/CTA/导航/字段样例，禁止通篇方向性描述或元写作说明（与 `AiSiteExecutionBlueprintService` / `AiSiteVirtualThemePlanService` 提示词条款一致）。
- 第一阶段先生成“块化建站方案书”，SSE 组件只负责同步后台方案进度、页面/块就绪与轻量微调结果，不承担任何第二阶段执行职责。
- 第一阶段 `微调` 的语义是“按用户要求修改当前方案”，必须返回修订后的完整方案并替换当前草案，不是把补充说明追加到方案底部。
- 用户确认方案后，先一次性把第二阶段所有块级任务规划完整并持久化，再进入生成主题；第二阶段只能执行已确认方案，不允许再按一句话需求临时拆任务。
- 第二阶段必须具备任务级断点续跑：每完成一个任务就保存一次；下次进入工作台友好提示是否继续；用户确认后从未完成任务继续。

### Requirements And Measures

#### 要求 1：第一阶段 SSE 只负责方案进度同步与修订回写

- 第一阶段使用独立 `PbAiPlanRunner` 和内联方案工作台；旧方案弹窗仅作为历史口径，不再作为主入口。
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
- 点击“AI构建方案”后直接在第一阶段主区域下方打开内联方案工作台，不再走旧页面类型确认弹窗或方案弹窗主流程。
- 内联方案工作台职责固定为：
  - 展示共享规划区、页面 Tab、总进度区、队列状态区、AI 操作区
  - 展示确认版/草案版 Markdown 原文与结构化预览（只作为结果视图，不作为唯一真相源）
  - 展示生成进度与状态
  - 接收用户“补充要求”
  - 触发“AI 再次微调方案”、页面级/块级微调、重建、新增、删除
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
  - 删除旧页面类型确认弹窗在“AI构建方案”入口中的强耦合调用，统一改为第一阶段内联方案工作台入口；弹窗仅可作为只读预览/确认辅助层。
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
  - 不自动进入 build；必须弹出“立即生成/稍后生成”或进入显式继续入口
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
  - 默认模板与 `zh-Hans` 模板的内联方案工作台、继续提示、恢复流程一致

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

### A. 第一阶段内联方案工作台验收

- 验收点 A1：方案输出格式
  - 结果必须同时具备 `plan_book.markdown` 与 `plan_book.structured`，二者同源且可追溯到同一 `source_signature`。
  - 必须包含主题设计、Header/Footer、页面块设计、每块实施方式、实际规划内容、设计理由、完成判定与可编辑字段。
- 验收点 A2：内联工作台结构
  - 第一阶段主区域下方必须显示共享规划区、页面 Tab、总进度区、队列状态区、AI 操作区。
  - 页面 Tab 顶部必须有 `微调当前页面/重建当前页面/新增块`；块 hover 必须有 `微调块/局部重建/删除块`。
  - 旧方案弹窗不得作为主控制面；如保留只读预览层，也不得承载结构化决策入口。
- 验收点 A3：重入与生成中保护
  - 生成中离开页面或刷新后，重新进入只提示“继续观察/继续生成/稍后再说”，不得自动续跑。
  - 生成中触发关闭/取消必须二次确认；取消后任务进入 `cancelled`，不得误标 `done/failed`。

### B. 第一阶段确认与持久化验收

- 验收点 B1：确认后持久化
  - `plan_workbench.confirmed.*` 完整落库。
  - `plan_locale` 与 `default_locale` 同步持久化并可追踪。
- 验收点 B2：页面刷新
  - 确认成功后刷新当前 workspace 页面进入第二阶段上下文。
  - 失败时停留第一阶段并给出可操作错误提示。

### C. 第二阶段任务方案确认工作台验收

- 验收点 C1：第二阶段以内联任务方案工作台确认块任务树，Markdown 仅为只读/预览视图。
- 验收点 C2：支持 `微调任务方案/重建任务方案` + SSE/polling 进度同步。
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

- 步骤：进入工作台 -> 打开第一阶段内联方案工作台 -> 触发 `post-start-stage1-plan` -> 观察 SSE/polling。
- 断言：
  - 出现共享规划区、页面 Tab、总进度区、队列状态区、AI 操作区
  - `plan_book.markdown` 与 `plan_book.structured` 同源更新
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

### E2E-04 第二阶段任务方案确认工作台

- 步骤：生成第二阶段任务方案 -> 打开第二阶段内联任务工作台 -> 微调/重建 -> 确认。
- 断言：
  - `shared_block_tasks/page_block_tasks` 显示完整
  - SSE/polling 模式不混流，任务状态以持久化状态为准
  - 工作台离开/取消受保护（不可误触丢失任务草案）

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

### 12.0 智能体原子任务执行法（MUST）

> 本计划细节密集，智能体执行时不得一次性“整体实现第一阶段/第二阶段”。必须把工作拆成可独立完成、可独立验证、可独立回滚的原子任务。每轮智能体只领取一个原子任务，完成后更新证据，再进入下一个任务。

#### 12.0.1 原子任务原则

- MUST 每个原子任务只改变一个明确能力点，例如“新增 `theme_design.color_scheme` 字段校验”或“让队列列表展示 `total_tokens`”，不得混合 UI、Service、队列、测试多类改动。
- MUST 每个原子任务都写明：`输入上下文`、`允许修改文件`、`禁止修改文件`、`完成产物`、`验收方式`、`关联计划条款`。
- MUST 原子任务完成前不得扩大范围；若发现相邻问题，只能新增后续任务，不得顺手大改。
- MUST 每个原子任务至少有一个验证证据：单测、路由检查、模板断言、静态 grep、`git diff --check` 或人工可复核截图/日志。
- MUST 智能体执行任务前先重读该任务的 `关联计划条款`，执行后在任务记录里写“已覆盖条款/未覆盖条款”。
- MUST 若任务涉及用户已有未提交改动，先读 `git diff`，只做增量修改，禁止回滚用户改动。
- MUST 所有任务状态只能按顺序流转：`todo -> in_progress -> test_pending -> done`；失败则进入 `blocked` 或保持 `in_progress`。

#### 12.0.2 原子任务模板

每个任务必须采用以下模板记录，供智能体逐项执行：

```text
Atomic Task ID:
目标:
关联计划条款:
输入上下文:
允许修改:
禁止修改:
实施步骤:
验收方式:
完成证据:
回滚方式:
后续任务:
```

#### 12.0.3 智能体单轮执行协议

1. 选择一个 `todo` 原子任务，不得同时领取多个任务。
2. 将任务状态改为 `in_progress`。
3. 重读关联章节与相关 `dev/ai/skills`。
4. 只修改任务允许范围内的文件。
5. 执行该任务声明的验证。
6. 验证通过后写入完成证据并标记 `done`。
7. 若验证失败，记录失败原因与下一步，不得标记完成。

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

- [x] T01 第一阶段主队列编排器（status=done, progress=100%, owner=backend-ai, note=A09/A10/A11/A12/A13 已完成：stage1 requirement_expand、shared.theme_design、shared.header_footer envelope、Header/Footer 后页面 fanout 与页面并发队列元数据均已建立；证据见 `AiSiteExecutionBlueprintServiceTest`）
- [x] T02 `buildStageOneSharedPlanPrompt(...)` 与共享规划校验器（status=done, progress=100%, owner=prompt-engineering, note=A01~A05 已完成：输出主题设计 + Header/Footer + shared_prompt_context；已补齐 §1A/§13.2.6 具体性契约、色系校验、selection_reason 引用需求校验；证据见 `AiSiteExecutionBlueprintServiceTest`）
- [x] T03 共享规划持久化与上下文哈希管理（status=done, progress=100%, owner=backend, note=A10/A11/A17/A18 已完成 theme_context_snapshot/shared_prompt_context 持久化入口、shared_context_hash 缺失拒收与共享规划变更后的未完成页面任务 stale 标记；证据见 `AiSiteExecutionBlueprintServiceTest`）
- [x] T04 `buildStageOnePagePlanPrompt(...)` 与页面任务输入装配器（status=done, progress=100%, owner=prompt-engineering, note=A14/A15/A16 已完成：页面任务输入注入 theme_design 全量硬约束，页面提示词强制主题延续并输出 theme_alignment_summary；证据见 `AiSiteExecutionBlueprintServiceTest`）
- [x] T05 页面类型并发队列与冲突控制（status=done, progress=100%, owner=backend-queue, note=A13/A17/A18 已完成页面并发队列元数据、shared_context_hash 缺失拒收与共享规划变更 stale 标记；证据见 `AiSiteExecutionBlueprintServiceTest`）
- [x] T06 第一阶段块树装配器（status=done, progress=100%, owner=backend, note=A20/A21/A22 已完成：plan_book.structured 由块树生成、plan_book.markdown 由排序块树组合、block_index 与排序同步；证据见 `AiSiteExecutionBlueprintServiceTest`）
- [x] T07 第一阶段内联工作台 UI（status=done, progress=100%, owner=frontend, note=A06/A07/A27 已完成共享 Tab 主题字段展示、reason 入口、队列状态与 token 展示；证据见 `AiSiteAgentSharedTabTemplateTest` / `AiSiteAgentSseMarkerTest`）
- [~] T08 第一阶段页面级 AI 操作（status=in_progress, progress=45%, owner=frontend+backend, note=A24 已完成微调当前页面只改当前页面块树；页面重建/新增块入口仍待补齐）
- [x] T09 第一阶段块级操作 API（status=done, progress=100%, owner=backend-api, note=A23/A25/A26 已完成块排序写回、块 refine、块 create/delete/local rebuild 并更新装配结果；证据见 `AiSiteExecutionBlueprintServiceTest` / `AiSiteAgentSseMarkerTest`）
- [~] T10 第一阶段块 hover 交互与即时重装配（status=in_progress, progress=70%, owner=frontend, note=A25/A26 已完成结构化块操作与局部装配；hover 菜单/草稿保留交互仍需 UI 冒烟确认）
- [x] T11 第一阶段 SSE + 轮询状态面板（status=done, progress=100%, owner=sse-runtime, note=A27 与既有 SSE/polling 同步完成队列状态、token 用量、页面/任务状态面板展示；operation-sse plan 分支未知操作缺陷已修复）
- [ ] T12 第一阶段确认持久化（status=todo, progress=0%, owner=backend, note=写 confirmed plan + block_index + shared_prompt_context）

#### C. 第二阶段：块任务细化与任务方案装配

- [x] T13 第二阶段 confirmed plan 解析器（status=done, progress=100%, owner=backend, note=A29 已完成从第一阶段 confirmed plan_book/block tree 恢复共享块与页面块，不再从 Markdown 反推；证据见 `AiSiteVirtualThemePlanServiceTest`）
- [x] T14 `buildStageTwoSharedTaskPrompt(...)` 与共享块任务细化器（status=done, progress=100%, owner=prompt-engineering, note=A28/A32/A33/A34/A35 已完成 block task schema、继承第一阶段块上下文、meta_fields、content_plan、style_plan 约束；证据见 `AiSiteVirtualThemePlanServiceTest`）
- [x] T15 `buildStageTwoPageTaskPrompt(...)` 与页面块任务细化器（status=done, progress=100%, owner=prompt-engineering, note=A28/A32/A33/A34/A35 已完成任务字段/内容/样式输出与第一阶段块 goal/realtime_content/style_direction/reason 继承；证据见 `AiSiteVirtualThemePlanServiceTest`）
- [ ] T16 第二阶段上下文快照版本控制（status=todo, progress=0%, owner=backend, note=context_hash 变化时让旧页面任务失效重排）
- [ ] T17 第二阶段块任务装配器（status=todo, progress=0%, owner=backend, note=生成每页块任务方案 + 任务卡片视图数据）
- [~] T18 第二阶段内联工作台 UI（status=in_progress, progress=45%, owner=frontend, note=A38/A39 已完成任务卡片详情与 planning_reason 展开；任务排序、字段编辑、任务进度切换仍待 A40+ 后续 UI 冒烟）
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

### 12.1A 智能体原子任务队列（按细节执行，新增）

> 本节把 T01~T34 拆成更细的执行任务。智能体执行时优先使用本节任务，不允许只凭 T01/T14 这类大任务直接开工。

#### A. 第一阶段主题规划与共享 Tab

- [x] A01 定义 `theme_design` 最小 schema（status=done, owner=worker-1, covers=§1A/§13.2.3, output=`theme_purpose/color_scheme/typography_spacing_radius/visual_keywords/tone_of_voice/cta_tone/forbidden_styles/selection_reason` 字段定义, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A02 为 `theme_design.color_scheme` 增加字段校验（status=done, owner=worker-2, covers=§1A, output=主色/辅色/强调色/背景/正文/按钮色均非空, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A03 为 `selection_reason` 增加“引用用户一句话需求”的校验（status=done, owner=worker-3, covers=§1A 具体性契约, output=禁止空泛“现代/高级/简洁”, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A04 修改共享提示词，强制输出具体主题规划而非方向描述（status=done, owner=worker-1, covers=§1A/§13.2.3, output=`buildStageOneSharedPlanPrompt` 条款, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A05 修改共享提示词，要求说明“为什么选择该色系/字体/语气”（status=done, owner=worker-5, covers=§1A, output=`selection_reason`, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A06 共享 Tab UI 展示主题宗旨、色系、风格原因、禁用风格（status=done, owner=worker-6, covers=§1A, output=共享 Tab 卡片字段, evidence=AiSiteAgentSharedTabTemplateTest）
- [x] A07 共享 Tab 为主题设计/Header/Footer 增加 `?` 原因说明入口（status=done, owner=worker-2, covers=§1A/§12.0, output=点击展开 reason, evidence=AiSiteAgentSharedTabTemplateTest）
- [x] A08 共享 Tab 支持共享块排序并写回 `sort_order`（status=done, owner=worker-3, covers=§1A, output=排序影响 Markdown 和 block_index, evidence=template/service tests）

#### B. 第一阶段队列、Fiber 并发与页面提示词

- [x] A09 建立 `stage1.requirement_expand` 队列任务 envelope（status=done, owner=worker-4, covers=§13.5, output=job_key/job_type/status/token 字段, evidence=AiSiteExecutionBlueprintServiceTest）
- [x] A10 建立 `stage1.shared.theme_design` 队列任务（status=done, owner=worker-5, covers=§1A, output=持久化 theme_context_snapshot, evidence=AiSiteExecutionBlueprintServiceTest）
- [x] A11 建立 `stage1.shared.header_footer` 队列任务（status=done, owner=worker-6, covers=§1A, output=Header/Footer + shared_prompt_context, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A12 Header/Footer 完成后自动 fanout 页面任务（status=done, owner=worker-1, covers=§1A, output=不依赖用户切 Tab, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A13 页面任务 fanout 使用 Fiber/协程并发（status=done, owner=worker-2, covers=§1A/§13.5, output=一页面一任务并发，evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A14 页面任务输入强制携带 `theme_design` 全量硬约束（status=done, owner=worker-3, covers=§1A, output=色系/宗旨/原因/禁用风格进入 prompt context, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A15 修改页面提示词，反复提醒 AI 遵守共享主题规划（status=done, owner=worker-4, covers=§1A, output=`theme_alignment_summary`, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A16 页面提示词输出 `theme_alignment_summary`（status=done, owner=worker-5, covers=§13.2.4, output=每页说明如何服从主题, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A17 页面任务缺失 `shared_context_hash` 时拒收（status=done, owner=worker-6, covers=§13.7.1, output=非法输出阻断, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A18 共享规划变更时未完成页面任务标记 `stale`（status=done, owner=worker-1, covers=§13.5.3, output=旧 hash 不继续生成, evidence=php-l+AiSiteExecutionBlueprintServiceTest）

#### C. 第一阶段块树、Markdown 组合与内联操作

- [x] A19 定义 `shared block` 与 `page block` 统一字段 schema（status=done, owner=worker-2, covers=§1A, output=implementation_detail/realtime_content/reason/completion_rule/editable_fields, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A20 实现 `plan_book.structured` 由块树生成（status=done, owner=worker-3, covers=§1A, output=结构化真相源, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A21 实现 `plan_book.markdown` 由块树排序组合生成（status=done, owner=worker-4, covers=§1A, output=Markdown 不自由生成, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A22 实现 `block_index` 与块排序同步（status=done, owner=worker-5, covers=§1A, output=sort_order 改变时 index 更新, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A23 页面 Tab 块排序写回结构化数据（status=done, owner=worker-6, covers=§1A, output=排序影响 Markdown/第二阶段拆分, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A24 页面级 `微调当前页面` API 只改当前页面块树（status=done, owner=worker-1, covers=§1A, output=不影响其他页面, evidence=php-l+phpstan+AiSiteExecutionBlueprintServiceTest+AiSiteAgentSharedTabTemplateTest）
- [x] A25 块级 `微调块` API 只改当前块结构化数据（status=done, owner=worker-2, covers=§1A, output=不只改 Markdown, evidence=php-l+AiSiteAgentSseMarkerTest）
- [x] A26 块级 `新增/删除/局部重建` API 更新装配结果（status=done, owner=worker-2, covers=§1A, output=当前页面局部装配, evidence=php-l+AiSiteExecutionBlueprintServiceTest）
- [x] A27 第一阶段队列信息列表展示 job 状态与 token（status=done, owner=worker-3/worker-4, covers=§13.5, output=input/output/total tokens, evidence=php-l+AiSiteAgentSseMarkerTest）

#### D. 第二阶段块任务拆解规划

- [x] A28 定义 `block task` 最小 schema（status=done, owner=worker-5, covers=§1A/§13.3.4, output=task_goal/meta_fields/content_plan/style_plan/planning_reason/sort_order, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A29 第二阶段从第一阶段确认版块树读取任务输入（status=done, owner=worker-5, covers=§13.3.2, output=不从 Markdown 反推, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A30 第二阶段每个 block 至少 fanout 一个 `stage2.block_task_plan`（status=done, owner=worker-6, covers=§13.3, output=按 block_key 生成任务, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A31 第二阶段 block task 通过队列 + Fiber/协程并发生成（status=done, owner=worker-2, covers=§1A/§13.3, output=队列内 shared-first 后按 block task Fiber fanout，保留 sort_order/dependencies, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A32 第二阶段提示词引用第一阶段块的 goal/realtime_content/style_direction/reason（status=done, owner=worker-1, covers=§13.3.3, output=不重新发明任务, evidence=php-l+phpstan+AiSiteVirtualThemePlanServiceTest）
- [x] A33 第二阶段提示词输出 meta 字段类型/默认值/示例内容（status=done, owner=worker-4, covers=§13.3.5, output=meta_fields[], evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A34 第二阶段提示词输出内容正文/CTA/链接/素材位（status=done, owner=worker-5, covers=§1A, output=content_plan, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A35 第二阶段提示词输出配色/字体/间距/响应式规则（status=done, owner=worker-6, covers=§1A, output=style_plan, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A36 第二阶段提示词输出 `planning_reason`（status=done, owner=worker-1, covers=§1A, output=shared_tasks[].planning_reason + block_task.planning_reason, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [x] A37 第二阶段校验器拒绝缺失 meta/content/style/reason 的任务（status=done, owner=worker-3, covers=§13.7.2, output=不合格重生成, evidence=php-l+AiSiteVirtualThemePlanServiceTest）

#### E. 第二阶段工作台、排序、字段编辑与任务进度

- [x] A38 第二阶段任务卡片展示 meta 字段、字段示例、内容计划、配色字体（status=done, owner=worker-4, covers=§1A, output=任务卡片详情, evidence=php-l+node-check+render assertion）
- [x] A39 第二阶段任务卡片展示 `?` 原因说明（status=done, owner=worker-5, covers=§1A, output=展开 planning_reason, evidence=php-l+node-check+AiSiteVirtualThemePlanServiceTest）
- [x] A40 第二阶段支持任务排序并写回 `page_block_tasks[].sort_order`（status=done, owner=worker-6, covers=§13.3, output=排序影响虚拟主题树, evidence=php-l+AiSiteVirtualThemePlanServiceTest）
- [ ] A41 第二阶段支持编辑字段内容（status=todo, owner=frontend+backend, covers=§1A, output=字段内容改动写结构化任务）
- [ ] A42 第二阶段支持任务微调/局部重建/删除/新增（status=todo, owner=backend-api, covers=§1A, output=结构化任务同步更新）
- [ ] A43 第二阶段确认后切换到任务进度视图（status=todo, owner=frontend, covers=§1A/§13.5, output=`progress_kind=task_progress`）
- [ ] A44 第二阶段任务进度实时显示 todo/queued/running/done/failed/stale/cancelled（status=todo, owner=frontend+sse, covers=§13.5, output=已确认任务进度）
- [ ] A45 第一阶段只展示队列信息不展示“已确认任务进度”（status=todo, owner=frontend, covers=§13.5, output=`progress_kind=queue_info`）

#### F. 通用队列、token、SSE/polling 与测试

- [ ] A46 AI 队列记录 input/output/total tokens（status=todo, owner=backend-queue, covers=§5.4/§13.5, output=token_usage）
- [ ] A47 队列信息列表展示 token 用量（status=todo, owner=frontend, covers=§5.4, output=token columns）
- [ ] A48 SSE payload 增加 `token_usage` 与 `progress_kind`（status=todo, owner=sse-runtime, covers=§13.5.2, output=统一事件体）
- [ ] A49 polling payload 与 SSE payload 对齐（status=todo, owner=backend-api, covers=§13.5, output=同一状态真相源）
- [ ] A50 `done/error/cancelled` 后关闭 SSE 流（status=todo, owner=sse-runtime, covers=§7.4, output=无连接泄漏）
- [ ] A51 第一阶段单测：主题规划字段完整（status=todo, owner=qa, covers=A01-A05, output=单测）
- [ ] A52 第一阶段单测：Header/Footer 后 fanout 页面任务（status=todo, owner=qa, covers=A12-A13, output=队列/服务测试）
- [ ] A53 第一阶段单测：Markdown 由块排序组合（status=todo, owner=qa, covers=A20-A23, output=装配测试）
- [ ] A54 第二阶段单测：每块生成 block task（status=todo, owner=qa, covers=A28-A32, output=任务拆解测试）
- [ ] A55 第二阶段单测：缺 meta/content/style/reason 被拒收（status=todo, owner=qa, covers=A33-A37, output=校验测试）
- [ ] A56 前端/模板断言：`?` 原因说明入口存在（status=todo, owner=qa, covers=A07/A39, output=模板断言）
- [ ] A57 前端/模板断言：第二阶段确认后显示任务进度（status=todo, owner=qa, covers=A43-A44, output=模板/JS 断言）
- [ ] A58 回归测试：第一阶段不自动进入 build（status=todo, owner=qa, covers=§7.5, output=回归用例）
- [ ] A59 回归测试：第二阶段确认前不启动虚拟主题（status=todo, owner=qa, covers=§1A, output=回归用例）
- [ ] A60 文档/计划审计：每个新增细节都有原子任务覆盖（status=todo, owner=architecture, covers=§12.0, output=任务覆盖表）


### 12.1B 代码既有能力补充任务（从当前代码反查，新增）

> 来源：本节由当前 PageBuilder 代码只读反查得到，专门补齐“代码里已有能力/入口，但计划缺少原子任务”的细节。所有任务默认 `status=todo`，执行前仍须按 §12.0 单任务领取、单任务验证。

#### G. 队列观察面板与 operation-sse 增量同步

- [ ] A61 队列观察面板 payload schema（status=todo, owner=backend-sse, source=code-discovered, evidence=`AiSiteAgent::buildQueueObserverPanelPayload`, covers=§5.4/§13.5, output=定义 `queue_info/snapshot/process/result_log` 前后端字段）
- [ ] A62 operation-sse 推送队列详情（status=todo, owner=backend-sse, source=code-discovered, evidence=`AiSiteAgent::forwardObservedQueueSignals`, covers=§7.4/§13.5.2, output=推送 `queue_info/queue_snapshot/queue_process`）
- [ ] A63 前端合并 `queue_result_delta` 到队列日志（status=todo, owner=frontend, source=code-discovered, evidence=`script-phase1-task-progress.phtml::mergePlanQueueInfoFromSsePayload`, covers=§5.4/§13.5, output=增量日志合并不丢行）
- [ ] A64 队列日志截断策略（status=todo, owner=frontend, source=code-discovered, evidence=`appendQueueResultLog`, covers=§13.5, output=日志过长保留末尾且给出截断提示）
- [ ] A65 队列面板同步测试（status=todo, owner=qa, source=code-discovered, evidence=`AiSiteAgentSseMarkerTest`, covers=§12.5, output=覆盖 `queue_info/queue_result_delta` 同步）

#### H. SSE 连接治理、lease 与重复连接回收

- [ ] A66 stream lease 数据结构与 TTL（status=todo, owner=sse-runtime, source=code-discovered, evidence=`STREAM_LEASE_SCOPE_KEY/STREAM_LEASE_TTL_SEC`, covers=§7.4/§13.5, output=lease scope schema 与过期策略）
- [ ] A67 前端 `tab_token` 与 touch lease 心跳（status=todo, owner=frontend+sse, source=code-discovered, evidence=`postTouchStreamLease/touchStreamLeaseState`, covers=§13.5.2, output=页面存活期间定期续约）
- [ ] A68 lease 过期清理当前 stream 关联操作（status=todo, owner=backend-sse, source=code-discovered, evidence=`cancelWorkspaceWorkAfterStreamLeaseExpired`, covers=§13.5.3, output=断连后不遗留无主运行）
- [ ] A69 重复 operation-sse 连接观测与抑制（status=todo, owner=sse-runtime, source=code-discovered, evidence=`observeDuplicateOperationStream`, covers=§7.4, output=重复连接不造成重复队列/重复事件）
- [ ] A70 stale active_operation 回收规则与测试（status=todo, owner=backend-sse+qa, source=code-discovered, evidence=`STALE_ACTIVE_OPERATION_TTL_SEC/shouldReclaimStaleActiveOperation`, covers=§13.5.3, output=陈旧 active_operation 可回收且可验证）

#### I. PageBuilder 与 Websites 工作台镜像/域名购买联动

- [ ] A71 PageBuilder -> Websites 镜像会话创建（status=todo, owner=backend-integration, source=code-discovered, evidence=`ensureLinkedWebsitesMirrorSession`, covers=§12.8/§12.9, output=PageBuilder 会话可映射 Websites 会话）
- [ ] A72 Websites scope -> PageBuilder scope 同步（status=todo, owner=backend-integration, source=code-discovered, evidence=`syncPageBuilderScopeFromLinkedWebsitesSession`, covers=§5/§12.8, output=域名/供应商/推荐结果回写 PageBuilder scope）
- [ ] A73 域名推荐/校验状态在 PageBuilder 工作台展示（status=todo, owner=frontend, source=code-discovered, evidence=`recommend_domain_url/check_domain_url/domain_purchase_state`, covers=§12.9, output=推荐、校验、供应商状态可见）
- [ ] A74 PageBuilder 代理启动域名购买队列（status=todo, owner=backend-integration, source=code-discovered, evidence=`postStartDomainPurchase`, covers=§12.8, output=购买动作从 PageBuilder 入口进入 Websites 队列）
- [ ] A75 域名购买 SSE 状态同步（status=todo, owner=sse-runtime, source=code-discovered, evidence=`domain_purchase_sse_url`, covers=§13.5, output=购买进度可同步回 PageBuilder 工作台）
- [ ] A76 域名购买结果影响发布门禁（status=todo, owner=backend+product, source=code-discovered, evidence=`DomainPurchaseWorkbenchService/buildViewState`, covers=§12.8/§14.6, output=失败阻断发布，成功允许进入发布检查）

#### J. fake_mode / force preset / 确定性预览模式

- [ ] A77 fake_mode 使用边界说明（status=todo, owner=architecture, source=code-discovered, evidence=`fake_mode`, covers=§12.0/§12.5, output=仅用于调试/演示/测试，不污染正式确认版）
- [ ] A78 第一阶段 fake_mode 块新增/删除/微调行为（status=todo, owner=backend, source=code-discovered, evidence=`applyFakeModePreviewMutation/annotateFakeModePlanBlock/removeFakeModePlanBlock/buildFakeModePlanBlock`, covers=§1A, output=假模式下块操作可预测）
- [ ] A79 第二阶段 fake_mode 任务新增/删除/微调行为（status=todo, owner=backend, source=code-discovered, evidence=`applyFakeModeTaskPlanPreviewMutation/annotateFakeModeTaskPlanTask/removeFakeModeTaskPlanTask`, covers=§1A/§13.3, output=假模式下任务操作可预测）
- [ ] A80 `force_plan_rebuild` 队列预设（status=todo, owner=backend-queue, source=code-discovered, evidence=`applyForcePlanRebuildPreset`, covers=§13.5, output=强制重建方案的调试入口受控）
- [ ] A81 `force_task_plan_rebuild` 队列预设（status=todo, owner=backend-queue, source=code-discovered, evidence=`applyForceTaskPlanRebuildPreset`, covers=§13.5, output=强制重建任务方案的调试入口受控）
- [ ] A82 fake_mode 不得污染正式 confirmed 数据测试（status=todo, owner=qa, source=code-discovered, evidence=`fake_mode/confirmed.*`, covers=§12.5, output=假模式结果不写入正式确认版或有显式隔离标记）

#### K. Weline_Ai 适配器与 Agent 注册

- [ ] A83 PageBuilder AI adapter code 清单（status=todo, owner=ai-integration, source=code-discovered, evidence=`extends/module/Weline_Ai/Adapter/*Adapter.php`, covers=§14.1, output=记录 `PlanGenerationAdapter/TaskPlanGenerationAdapter/ComponentGenerationAdapter/ContentGenerationAdapter` code）
- [ ] A84 PlanGenerationAdapter 对接第一阶段方案生成（status=todo, owner=ai-integration, source=code-discovered, evidence=`PlanGenerationAdapter`, covers=§13.2/§14.2, output=第一阶段场景使用稳定 adapter code）
- [ ] A85 TaskPlanGenerationAdapter 对接第二阶段任务方案（status=todo, owner=ai-integration, source=code-discovered, evidence=`TaskPlanGenerationAdapter`, covers=§13.3/§14.2, output=第二阶段场景使用稳定 adapter code）
- [ ] A86 ComponentGenerationAdapter 对接组件生成/重建（status=todo, owner=ai-integration, source=code-discovered, evidence=`ComponentGenerationAdapter`, covers=§13.4, output=虚拟主题/组件生成链路记录 adapter 关系）
- [ ] A87 PageBuilderAgent / RefineAgent 场景与工具清单（status=todo, owner=ai-integration, source=code-discovered, evidence=`PageBuilderAgent/PageBuilderRefineAgent`, covers=§14.2, output=记录 agent scenario/tools/max_iterations）
- [ ] A88 adapter scan 入库与后台可见性验证（status=todo, owner=qa, source=code-discovered, evidence=`dev/ai/skills/ai-module-development`, covers=§14.1, output=`ai:adapter:scan` 后 adapter 可见可调用）

#### L. scope 兼容层与旧数据水合

- [ ] A89 scope_json 兼容层输入/输出 schema（status=todo, owner=backend-schema, source=code-discovered, evidence=`AiSiteScopeCompatibilityService::normalizeScope`, covers=T27/§5, output=兼容层字段说明）
- [ ] A90 旧 page_types 归一化（status=todo, owner=backend, source=code-discovered, evidence=`normalizePageTypes/defaultPageTypes/legacyDefaultPageTypes`, covers=T27, output=历史 page_types 可归一化）
- [ ] A91 旧 virtual_pages_by_type 归一化（status=todo, owner=backend, source=code-discovered, evidence=`normalizeVirtualPagesByType/buildVirtualPagesByType`, covers=T27/§13.4, output=历史虚拟页面结构可恢复）
- [ ] A92 legacy blocks 水合 editable metadata（status=todo, owner=backend, source=code-discovered, evidence=`hydrateEditableBlockMetadata`, covers=§13.4/§10B-E2E-09, output=旧块补齐可编辑元数据）
- [ ] A93 layout_config 归一化（status=todo, owner=backend, source=code-discovered, evidence=`normalizeLayoutConfig/normalizeLegacyRegionsLayout`, covers=§13.4, output=旧 layout 可进入可视化编辑）
- [ ] A94 scope 兼容层回归测试（status=todo, owner=qa, source=code-discovered, evidence=`AiSiteScopeCompatibilityService`, covers=§12.5, output=覆盖旧 scope 到新 schema）

#### M. 站点 Profile / Draft Website 生成

- [ ] A95 站点 profile 生成与复用签名（status=todo, owner=backend, source=code-discovered, evidence=`AiSiteProfileGenerationService::buildGenerationSignature/canReuseGeneratedProfile`, covers=§5/§12.8, output=profile 可复用且签名可审计）
- [ ] A96 title/tagline/logo/icon/palette 手动锁定规则（status=todo, owner=backend+frontend, source=code-discovered, evidence=`normalizeManualFlags/hasManualOverride`, covers=§1A/§12.8, output=手动值不被 AI 覆盖）
- [ ] A97 AI profile 生成失败 fallback（status=todo, owner=backend, source=code-discovered, evidence=`generateManagedProfile/buildFallbackLogoDataUri/buildFallbackIconDataUri`, covers=§13.5.3, output=AI 失败时有确定性 profile）
- [ ] A98 draft website 创建/复用规则（status=todo, owner=backend, source=code-discovered, evidence=`AiSiteDraftWebsiteService::ensureDraftWebsite`, covers=§12.8, output=草稿站点创建/复用不重复）
- [ ] A99 draft website 与发布门禁关系（status=todo, owner=backend+product, source=code-discovered, evidence=`AiSiteDraftWebsiteService/AiSitePublishService`, covers=§12.8/§14.6, output=草稿站点、正式发布状态边界清晰）

#### N. QueueDbWriter 与队列事件落库

- [ ] A100 QueueDbWriter 事件 envelope schema（status=todo, owner=sse-runtime, source=code-discovered, evidence=`QueueDbWriter::resolveWorkspaceEventEnvelope`, covers=§13.5.2, output=队列事件落库统一 envelope）
- [ ] A101 raw AI stream chunk 缓冲与落库（status=todo, owner=sse-runtime, source=code-discovered, evidence=`recordRawAiStreamChunk/flushAiRawStreamBuffer`, covers=§13.5, output=raw chunk 有界缓冲并可审计）
- [ ] A102 queue heartbeat 写入规则（status=todo, owner=sse-runtime, source=code-discovered, evidence=`sendHeartbeat/maybeHeartbeat`, covers=§13.5.3, output=长任务心跳可观测）
- [ ] A103 队列日志与 workspace event 对齐测试（status=todo, owner=qa, source=code-discovered, evidence=`appendQueueLog/normalizeWorkspaceLogPayload`, covers=§12.5, output=queue log 与 workspace event 一致）

#### O. 可视化布局保存与虚拟预览 URL

- [ ] A104 虚拟页面 preview URL 解析规则（status=todo, owner=backend, source=code-discovered, evidence=`AiSiteVisualUrlService::resolveVirtualUrls`, covers=§10B-E2E-08/§13.4, output=预览 URL 与 page_type/virtual_theme_id 绑定）
- [ ] A105 resolved layout 保存规则（status=todo, owner=backend, source=code-discovered, evidence=`AiSiteVirtualLayoutService::saveResolvedLayout`, covers=§13.4, output=可视化编辑布局可保存）
- [ ] A106 virtual page patch 保存规则（status=todo, owner=backend, source=code-discovered, evidence=`AiSiteVirtualLayoutService::saveVirtualPagePatch`, covers=§10B-E2E-09, output=页面 patch 可持久化）
- [ ] A107 可视化编辑保存后同步任务/块状态（status=todo, owner=backend+frontend, source=code-discovered, evidence=`AiSiteVirtualLayoutService/AiSiteBuildTaskService`, covers=§13.4/§12.5, output=编辑结果与任务/块状态一致）
- [ ] A108 preview URL 与 production URL 分离测试（status=todo, owner=qa, source=code-discovered, evidence=`AiSiteVisualUrlService/AiSitePublishService`, covers=§14.6, output=预览地址与正式地址不会混用）
### 12.1C 任务-细节对齐规则（新增）

- MUST 任何新增计划细节都在 12.1/12.1A 追加至少一条可执行任务，不允许“有细节无任务”。
- MUST 任务标题可直接映射到代码改动点与验收用例，不使用抽象空任务。
- MUST 在任务状态更新前，先检查该任务是否覆盖对应细节条款；未覆盖不得标记完成。
- MUST 提示词相关任务（T02/T04/T14/T15 等）完成定义须与 **§1A、§13.2.6、§13.3.6** 可逐条对照；交付物含可评审的 GOOD/BAD 维度或等价自动化校验（与 `AiSiteExecutionBlueprintService` / `AiSiteVirtualThemePlanService` 行为一致）。
- MUST 智能体优先执行 12.1A 的 Axx 原子任务；只有当一组 Axx 全部完成并验证后，才允许把对应 Txx 大任务标记为 done。

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
   - 定义站点定位、主题宗旨、具体色系、品牌语气、视觉系统、CTA 方向、SEO 总策略，并解释为什么这些选择匹配用户一句话需求。
3. `shared_plan`
   - 生成 Header/Footer/共享 CTA/共享信任位，产出 `shared_prompt_context`。
4. `page_plan_fanout`
   - Header/Footer 与主题规划完成后立即 Fiber/协程并发生成单页块化方案；一页面一个任务，每个任务强制携带主题规划。
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
  - 必含：`theme_purpose`（主题宗旨）、`color_scheme`（主色/辅色/强调色/背景/正文/按钮色及用途）、`typography_spacing_radius`（字体/留白/圆角倾向）、`visual_keywords`、`tone_of_voice`、`cta_tone`、`forbidden_styles`、`selection_reason`（基于用户描述的选择原因）。
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
- `theme_design.color_scheme`
- `theme_design.theme_purpose`
- `theme_design.selection_reason`
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
- `theme_alignment_summary`：说明本页面每组块如何遵守共享主题规划（色系、语气、CTA、信任表达、Header/Footer 承接）。

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
- Header/Footer 与主题共享规划完成后，页面任务立即 Fiber/协程并发；页面块完成一个就装配一个，不等待全部页面。
- 装配结果同时维护：
  - 用户阅读版 Markdown；
  - 机器消费版 `structured_plan`；
  - 块级索引 `block_index`；
  - 页面 Tab 数据源 `page_tabs_state`。
- 装配逻辑必须是结构化优先，Markdown 只作为展示结果，不是事实源。
- 用户看到的 Markdown 必须由当前块树按排序组合生成；共享 Tab 和页面 Tab 的排序变化必须同步影响 Markdown 章节顺序、`structured_plan.blocks[].sort_order` 与 `block_index`。

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
   - 按第一阶段确认版页面块 fanout，并通过队列 + Fiber/协程并发细化块任务；同页内块可小批并发，但必须共享同一上下文快照。
4. `task_plan_assemble`
   - 组装为每页可阅读任务方案书与 `page_block_tasks`。
5. `task_inline_edit`
   - 用户继续按页面/按块任务微调、重建、删除、新增、排序、编辑字段内容，并可点击 `?` 展开规划原因。

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
- 每个页面块任务提示词必须显式引用第一阶段对应 `block_key` 的 `goal/realtime_content/style_direction/reason/completion_rule`，不得脱离第一阶段块描述重新发明任务。

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
    "fields": ["headline", "subheadline", "cta_primary", "cta_secondary", "hero_visual"],
    "meta_fields": [
      {"key": "headline", "type": "text", "sample": "把发票、收入、税务一次理清"},
      {"key": "cta_primary.href", "type": "url|route|anchor", "sample": "#lead-form"}
    ]
  },
  "content_plan": {
    "headline": "把发票、收入、税务一次理清",
    "body_points": ["导入银行流水与发票后自动归类", "报税前快速核对缺失凭证"],
    "cta": [{"label": "免费试用 30 天", "target": "#lead-form"}]
  },
  "style_plan": {
    "color_usage": "主 CTA 使用主题强调色，背景保持浅色降低财务焦虑",
    "typography": "首屏标题使用大号粗体，说明文字控制在两行内",
    "spacing": "桌面端左右分栏，移动端纵向堆叠"
  },
  "dependencies": ["shared:header", "shared:footer"],
  "materialize_policy": "page",
  "reason": "Hero 是首页第一个必须可见且可编辑的高权重模块",
  "planning_reason": "该块承担用户进入首页后的第一判断，因此优先规划标题、CTA、信任短句和主视觉素材位；配色沿用主题强调色以保证转化动作连续。",
  "completion_rule": "字段完整、素材位完整、预览可见且可编辑",
  "sort_order": 10,
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
- 共享块的 meta 字段、字段示例内容、排序、配色/字体/间距规则、`planning_reason`

`buildStageTwoPageTaskPrompt(...)` 负责补齐：

- 每个页面块需要的文案字段
- 每个页面块需要的 meta 数据字段、字段类型、默认值和示例内容
- 每个块需要的素材位
- 每个块的 CTA 与内链
- SEO/响应式/组件配置
- 每个块的配色、字体、间距、交互状态与“为什么这么规划”
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
  "event_id": "...",
  "seq_no": 12,
  "attempt_id": "...",
  "correlation_id": "...",
  "cursor": "stage/page/block",
  "source": "queue|poller|manual",
  "progress_percent": 45,
  "session_public_id": "...",
  "website_public_id": "...",
  "page_key": "home",
  "block_key": "home.hero",
  "message": "首页方案已完成并装配",
  "context_hash": "...",
  "state_fingerprint": "hash(scope_json/result_ref)",
  "is_replay": false,
  "retryable": true,
  "token_usage": {
    "input_tokens": 0,
    "output_tokens": 0,
    "total_tokens": 0
  },
  "progress_kind": "queue_info|task_progress",
  "updated_at": "..."
}
```

- 长流程使用 SSE/轮询同步上述状态体，不推长篇 AI 原始文本。
- 轻量微调/局部重建可以附加短时 `chunk` 文本，但最终仍要落结构化结果。
- `seq_no` 在同一 job 内必须单调递增；前端不得展示回退进度。
- 断线重连必须以 `Last-Event-ID` 或 `cursor` 补推未确认事件；`is_replay=true` 的事件不得二次触发业务动作。
- `state_fingerprint` 与持久化状态不一致时，前端必须重新拉取 polling 状态并提示刷新。
- `token_usage` 必须随 AI 队列任务完成或阶段性返回时更新；队列信息列表至少展示 `input/output/total tokens`。
- `progress_kind` 用于区分展示层：第一阶段默认展示 `queue_info`（队列状态、token、页面/块就绪），第二阶段方案确认后展示 `task_progress`（已确认任务的实时进度、依赖、失败/重试）。

#### 13.5.3 恢复语义

- `done` 块/任务永不自动重跑。
- `stale` 表示上下文版本过期，需重排。
- `failed` 保留已完成前置块，允许局部重试。
- 恢复时从“第一个非 done 且非 cancelled 的节点”继续。
- 页面已 ready 的预览状态在恢复后必须保留。
- `failed` 重试必须受 `max_retries` 与 `retry_backoff_ms` 控制；超过上限转 `blocked` 并返回用户可见恢复原因。
- 共享上下文或确认签名变化时，未完成任务必须转 `stale`，不得继续旧产物。

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

## 14. dev/ai 技能路由与执行闭环补强（2026-04-21）

> 目的：把框架已有 `dev/ai/skills` 能力显式纳入本计划，避免 AI 建站中台脱离框架规范单独演进。本节不替代 §1A 的 MUST 基线；若发生冲突，优先级为：§1A MUST 基线 > 本节技能路由 > 其他建议性章节。

### 14.1 技能命中清单与计划落点（MUST）

| 技能 | 本计划中的约束含义 | 必须落到的章节/实现面 |
| --- | --- | --- |
| `planning` | 计划必须形成“前置分析 -> 任务拆分 -> 实施跟踪 -> 完成后审计”闭环，不能只列目标。 | §8、§9、§12.1、§12.2、§12.5、§12.7 |
| `documentation-standards` | 文档必须同时说明功能是什么、如何使用、关键参数、示例、禁忌与变更记录。 | §5、§9A、§10、§10A、本 §14 |
| `ai-module-development` | AI 能力必须通过场景适配器/可扫描入口纳入 `Weline_Ai` 体系，不能散落在 Controller 或临时脚本中。 | §7.1、§7.2、§13.2、§13.3、§13.8 |
| `queue-usage` | 第一阶段、第二阶段、虚拟主题构建等长流程必须入队并持久化状态、进度与结果引用。 | §5.4、§7.4、§12.2、§13.5 |
| `sse-streaming` | SSE 只做进度、就绪、轻量 chunk 与错误通知；完整结果以结构化持久化数据为准。 | §7.4、§13.5.2、§13.7、§10B |
| `pagebuilder-style-templates` | AI 输出块必须能映射到 PageBuilder 模板字段、组件配置、颜色与下载/跳转约定。 | §5.3、§13.2.4、§13.3.4、§13.4 |
| `frontend-components` | Block/Taglib/Widget/DataTable 与块级编辑边界必须清晰；块操作要改结构化数据，不只改 Markdown。 | §1A、§7.1、§7.2、§13.4、§13.6、§10B-E2E-09 |
| `theme-development` | 虚拟主题生成必须遵守主题目录、layout/partial/widget、变量、色盘、作用域样式与无 CDN 约束。 | §13.4、§13.7.3、§10A-F |
| `service-development` | Controller 只负责请求编排与响应；阶段编排、校验、装配、状态读取必须沉到 Service。 | §3、§4、§7、§13.8 |
| `database-model-standards` | 持久化结构用模型字段声明与索引表达；状态、hash、结果引用、重试字段必须可查询。 | §5.1、§5.2、§5.4、§12.2 |
| `i18n-internationalization` | 用户可见文案、按钮、错误、SSE 消息、AI 样例字段必须可国际化。 | §5、§7.4、§10A-G、§13.7 |
| `testing` | 每个可交付任务必须绑定冒烟或 E2E 证据；真实浏览器链路默认走 `php bin/w e2e:run`。 | §10、§10A、§10B、§12.5、§12.7 |
| `runtime-and-process` | WLS/Worker 长连接与队列进程必须可回收、可心跳、可恢复，禁止阻塞式 `sleep/die/exit`。 | §7.4、§13.5、§13.6、§12.7 |
| `weline-framework-skill-router` | 多领域任务必须先路由到 1～3 个相关技能，再落到代码/文档章节。 | §11、§13.8、本 §14 |

### 14.2 执行链路按技能拆层（MUST）

1. **需求与计划层（`planning` + `documentation-standards`）**
   - 进入研发前必须先确认：范围、依赖、缺口、不可做项、完成标准。
   - `plan.md`/本计划中的每个任务必须有状态、证据、阻塞原因、测试入口。
   - 完成后必须做“计划 vs 实现 vs 验收”审计，输出 `完成/部分/未实现`。

2. **AI 能力层（`ai-module-development`）**
   - 阶段一共享方案、阶段一页面方案、阶段二任务方案、虚拟主题构建至少应抽象为可识别的 AI 场景。
   - 每个场景必须有稳定 `scenario_code`、输入 schema、输出 schema、提示词版本、适配器/Provider 选择策略。
   - 若新增或迁移适配器，必须验证“文件存在 -> 扫描入库 -> 后台可见 -> 运行可调用”闭环。

3. **异步执行层（`queue-usage` + `runtime-and-process`）**
   - `stage1.*`、`stage2.*`、`virtual_theme.*` 必须是可持久化的 queue job 或 queue-backed job。
   - 每个 job 至少持久化：`job_key`、`job_type`、`status`、`progress_percent`、`attempt_no`、`worker_id`、`heartbeat_ts`、`result_ref`、`last_error_code`、`context_hash`。
   - 队列执行进程内禁止依赖 stdout 表达结果；进度与结果必须写入状态表/结果表。
   - WLS 长连接与后台等待必须使用协作式等待/调度能力，禁止在长循环中使用阻塞式 `sleep/usleep/die/exit`。

4. **状态同步层（`sse-streaming`）**
   - SSE 事件必须最少包含：`event_id`、`job_key`、`job_type`、`status`、`seq_no`、`progress_percent`、`message_key|message`、`context_hash`、`updated_at`。
   - 客户端断线重连必须携带 `Last-Event-ID` 或等价 `cursor`，服务端按 `seq_no/cursor` 补推未确认事件。
   - `done`/`error` 事件后服务端必须完整关闭流；未关闭视为连接泄漏，必须进入测试失败项。
   - SSE 与 polling 读取同一状态真相源；二者结果冲突时以持久化状态为准并记录异常。

5. **结构化输出与渲染层（`pagebuilder-style-templates` + `frontend-components` + `theme-development`）**
   - 阶段一/二输出的每个 block/task 都必须能映射到：组件类型、可编辑字段、默认值、样式 token、响应式规则、预览入口。
   - 虚拟主题建树固定映射：`site -> shared -> page -> block -> component -> slot`，其中 `component/slot` 必须能被 PageBuilder 可视化编辑器定位。
   - 新增组件/模板时必须遵守：组件根作用域、主题前缀、`@fields_start/@fields_end`、配置输出转义、颜色变量、下载跳转统一注册。
   - 用户可见文案必须走 i18n 机制；自定义标签属性内禁止 PHP 表达式。

6. **测试与发布层（`testing` + `database-model-standards` + `i18n-internationalization`）**
   - 每个任务转 `done` 前必须具备：`smoke_passed=true`、`evidence_present=true`、`no_stale_data=true`。
   - 数据模型新增字段必须有字段声明、索引策略、迁移/升级验证，不允许只改临时 payload。
   - E2E 默认使用 `php bin/w e2e:run`；禁止在仓库根目录直接裸跑 `npx playwright test tests/e2e/...`。
   - 发布前必须同时通过：队列恢复、SSE/polling 一致性、语言策略、组件级编辑、域名成功/失败路径、正式站点可访问性。

### 14.3 队列/SSE/恢复协议补强（MUST）

#### 14.3.1 状态枚举统一

计划、队列、SSE、任务面板统一使用下列语义层状态：

- `todo`：已规划但未入队/未开始；
- `queued`：已入队，等待执行；
- `in_progress`：执行中；
- `blocked`：需要用户或外部条件解除阻塞；
- `done`：已完成并通过对应冒烟/结构校验；
- `failed`：执行失败，可按策略重试；
- `stale`：上下文 hash 过期，必须重排或重建；
- `cancelled`：用户显式取消，不得自动续跑。

若底层队列已有 `pending/running/error/stop` 等枚举，必须在状态读取层做兼容映射，禁止前端同时暴露两套含义冲突的状态。

#### 14.3.2 幂等与重入

- `startStageOnePlan`、`confirmStageOnePlan`、`startStageTwoPlan`、`confirmStageTwoPlan`、`startVirtualThemeBuild` 必须支持 `request_id` 或等价幂等键。
- 同一 `request_id` 重放不得创建重复队列任务、重复块树或重复虚拟主题。
- 用户点击“继续”只恢复未完成节点；`done` 节点永不自动重跑。
- `refine/rebuild/delete/create/move` 类块操作必须写入新版本号或操作日志，禁止无痕覆盖历史确认版。

#### 14.3.3 事件游标与一致性

进度事件体在 §13.5.2 基础上追加：

```json
{
  "event_id": "stage1.home.hero:42",
  "seq_no": 42,
  "attempt_id": "attempt-...",
  "correlation_id": "request-...",
  "cursor": "stage1/page/home/block/hero",
  "source": "queue|poller|manual",
  "state_fingerprint": "hash(scope_json/result_ref)",
  "is_replay": false,
  "retryable": true
}
```

- `seq_no` 在同一 job 内单调递增；前端不得展示回退进度。
- `state_fingerprint` 与持久化 `scope_json/result_ref` 不一致时，前端必须提示刷新/重新拉取状态。
- `is_replay=true` 的事件只用于补齐 UI，不得二次触发业务动作。

### 14.4 数据与 API 补强（MUST）

- 持久化字段必须覆盖：`session_public_id`、`website_public_id`、`stage`、`job_key`、`job_type`、`page_key`、`block_key`、`status`、`progress_percent`、`context_hash`、`prompt_version`、`result_ref`、`last_error_code`、`retry_count`、`updated_at`。
- 高频查询字段至少要有索引策略：`website_public_id + stage + status`、`session_public_id + stage`、`job_key`、`context_hash`。
- `plan_book.structured`、`block_index`、`page_block_tasks`、`shared_block_tasks` 必须有最小 schema 校验；缺少 `block_key/task_key/completion_rule/editable_fields/context_hash` 不允许进入下一阶段。
- Controller 返回只表达请求结果与下一步动作；复杂编排必须在 Service/Orchestrator 内完成。
- AI 适配器调用必须记录：`scenario_code`、`adapter_code`、`model/provider`、`prompt_version`、`input_hash`、`output_hash`，便于复现与审计。

### 14.5 验收与 E2E 补强（MUST）

在 §10B 现有用例基础上补齐下列断言：

- **E2E-14 幂等启动**：同一 `request_id` 连续请求阶段一/阶段二/虚拟主题启动，只产生一组任务；状态接口能返回同一个 job 集合。
- **E2E-15 SSE 断线重连**：生成中断开 SSE，再用 `Last-Event-ID/cursor` 重连，页面补齐进度且不重复执行任务。
- **E2E-16 SSE 与 polling 一致性**：同一 job 的 `status/progress_percent/updated_at/context_hash` 在 SSE 与 polling 中一致，进度不回退。
- **E2E-17 并发块操作冲突**：同一 block 同时触发 `refine` 与 `rebuild`，系统按服务器序列号或明确优先级收敛，保留操作日志。
- **E2E-18 取消与恢复**：用户取消后任务进入 `cancelled`，再次进入工作台不得自动续跑，只能由显式“重新开始/恢复”触发。
- **E2E-19 组件映射可编辑**：虚拟主题生成后，至少验证 Header、Footer、一个 Hero、一个表单/CTA 块能在可视化编辑器定位并编辑字段。
- **E2E-20 i18n 与语言策略**：工作台按钮、错误提示、SSE 文案、AI 输出样例字段符合所选语言策略，不能混用默认语言。
- **E2E-21 发布门禁双路径**：域名校验失败路径阻断发布并显示可恢复文案；域名校验成功路径生成正式站点并通过关键页 HTTP 探活。

执行口径：

- 真实浏览器/UI/冒烟统一走 `php bin/w e2e:run --case-id=<CASE_ID> --headless`。
- 每个用例必须产出可定位证据：case id、运行时间、失败截图/日志或关键断言。
- E2E-13 全链路收官不得替代 E2E-14～E2E-21；它只作为最终组合冒烟。

### 14.6 发布前最终硬门禁（MUST）

发布或标记本计划完成前必须同时满足：

- §1A 所有 MUST 无回归；
- §14.1 技能命中清单中的相关技能均已在实现/测试/文档中有落点；
- 所有 `Txx` 任务状态为 `done` 或明确 `cancelled`，不得停留在无解释的 `todo/in_progress/failed/stale`；
- 所有 `done` 任务都有 `smoke/e2e evidence`；
- `stage1/stage2/virtual_theme` 三段队列均支持断点恢复；
- SSE 断线重连、polling 兜底、队列状态真相源一致；
- 结构化块树可追溯到 PageBuilder 组件/slot/字段；
- 语言策略、域名门禁、预览/正式 URL 分离均通过 E2E 验证；
- AI 场景适配器、提示词版本、输入/输出 hash 可审计。
