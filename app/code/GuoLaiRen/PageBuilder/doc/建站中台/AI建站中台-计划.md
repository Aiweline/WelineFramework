# AI建站中台-计划（PageBuilder唯一版）

> 模块：`GuoLaiRen_PageBuilder`  
> 状态：`in_progress_replanned`  
> 说明：本文件为 `app/code/GuoLaiRen/PageBuilder/doc/建站中台` 目录唯一保留计划文件。

## 1. 目标与范围

- 统一 AI 建站工作台执行口径为“两阶段”：
  - 阶段一：方案生成与确认（plan-first）
  - 阶段二：按确认方案执行构建（build-by-blueprint）
- 统一重入策略：从默认自动续跑改为显式“继续/稍后再说”。
- 统一真相源：`build_tasks[*].status` 是任务完成真相，`build_checkpoint` 仅恢复索引。
- PageBuilder 侧聚焦：工作台、SSE、执行蓝图、双轨构建、物化与发布门禁。

## 1A. 需求细节基线（MUST，不可丢失）

> 本节为实现与评审强约束。若与其他章节冲突，以本节为准。

### 第一阶段（方案）MUST

- MUST 只处理方案流事件：`start/progress/chunk/done/error`。
- MUST 使用独立 `PbAiPlanRunner` 与方案弹窗。
- MUST 在方案弹窗右侧提供 AI SSE 实时交流窗口（仅服务第一阶段方案对话，不承载第二阶段任务日志）。
- MUST NOT 复用工作台右侧 build 日志终端。
- MUST NOT 显示 build guard。
- MUST NOT 更新块级任务进度。
- MUST NOT 参与页面预览切换。
- MUST 令 `chunk` 仅执行 Markdown 追加与实时预览刷新。
- MUST 提供方案交互模式切换：`微调当前方案` / `重建新方案`。
- MUST 在发送用户补充要求时显式携带当前模式，后端据此执行：
  - `微调当前方案`：在当前 `draft` 基础上按用户描述的位置做定点重写，保留 round 上下文；
  - `重建新方案`：基于当前用户输入重新生成整份方案与整份执行蓝图，不复用旧草案内容。

### 第一阶段实时交流窗口（新增细节）

- MUST 支持用户在方案阶段持续输入补充要求，并通过 SSE 实时看到 AI 回复流。
- MUST 在窗口中展示当前模式状态（微调/重建）与本轮生成状态（进行中/完成/失败）。
- MUST 在 `重建` 成功后刷新完整方案预览与任务蓝图草案，不沿用旧草案残留内容。
- MUST 在 `微调` 成功后仅更新用户指定位置对应的章节与任务蓝图差异，并保留可读变更上下文。
- MUST 允许用户在任意一轮完成后再次切换模式并发起下一轮方案交互。

### 方案弹窗右侧 Tab 交互（补充细节）

- MUST 在方案弹窗右侧提供固定 Tab：
  - `微调` Tab
  - `重建` Tab
- MUST 将 Tab 选中态与 `prompt_mode` 强绑定：
  - 选中 `微调` => `prompt_mode=refine`
  - 选中 `重建` => `prompt_mode=rebuild`
- MUST 在每个 Tab 内提供独立的实时输入区与发送按钮，发送后走对应模式的 SSE 会话。
- MUST 在 Tab 内展示该模式最近一轮输入与流式输出，避免两种模式消息混流。
- MUST 在用户切换 Tab 时保留各自未发送草稿输入（避免丢字）。
- MUST 在正在流式生成时限制跨模式并发提交（禁止同时开启 refine/rebuild 两条方案流）。
- MUST 在流式结束后允许继续同模式追加，或切换到另一模式发起新一轮。
- MUST 在 UI 上明确标识当前生效模式，防止用户误把“重建”当“微调”提交。

### 微调/重建按钮与图标规范（新增）

- MUST 采用以下默认按钮语义（首版基线）：
  - 微调按钮：
    - 文案：`微调方案`
    - 图标：`mdi-tune-variant`（可回退 `mdi-tune`）
    - 语义色：信息/中性色
  - 重建按钮：
    - 文案：`重建方案`
    - 图标：`mdi-refresh`（可回退 `mdi-autorenew`）
    - 语义色：警示色（用于提示整案覆盖风险）
  - 确认进入第二阶段主按钮：
    - 文案：`确认方案并开始生成`
    - 图标：`mdi-check-circle-outline`
    - 语义色：主品牌色
- MUST 在右侧 Tab 使用一致命名与图标：
  - `微调` + `mdi-tune-variant`
  - `重建` + `mdi-refresh`
- MUST 显示“当前模式”标识，避免误操作。
- MUST 在 `重建方案` 前提供二次确认提示（文案示例：`将重建整份方案，是否继续？`）。
- MUST 在某一模式流式生成中禁用另一模式提交按钮，避免并发冲突。
- MUST 在输入区上方显示模式提示：
  - 微调：`仅修改指定位置`
  - 重建：`重新生成整份方案`

### Tab 手风琴说明区（新增）

- MUST 在右侧每个模式 Tab（微调/重建）顶部提供“说明”手风琴区域。
- MUST 默认收起手风琴，避免占用输入区空间。
- MUST 在首次进入该 Tab 时可见“查看模式说明”入口，用户手动展开查看细节。
- MUST 为两种模式提供友好提示文案：
  - 微调模式说明：`将根据你指定的位置做定点优化，只改必要联动内容。`
  - 重建模式说明：`将重新生成整份方案与任务蓝图，适合方向变化较大时使用。`
- MUST 在手风琴展开内容中明确“何时用微调 / 何时用重建”的建议，帮助用户快速决策。
- MUST 在流式生成期间保持手风琴可读但不抢焦点，不自动弹开/收起。

### 方案弹窗关闭行为约束（新增）

- MUST 禁止通过以下方式关闭方案弹窗：
  - 点击遮罩层（backdrop click）
  - 鼠标移出弹窗区域
  - 外部 hover/blur 导致失焦自动关闭
- MUST 仅允许通过显式操作关闭：
  - 点击右上角关闭按钮
  - 点击底部“取消/关闭”按钮
- MUST 在流式生成进行中关闭弹窗时进行二次确认，防止误中断方案生成。
- MUST 在用户取消关闭时保留当前输入与会话状态，不得清空。
- MUST 默认禁用 `Esc` 直接关闭（或等价走二次确认流程）。

### 确认方案后的页面切换与会话持久化（新增）

- MUST 在用户点击“确认方案并开始生成”后先执行会话持久化，再进入第二阶段。
- MUST 持久化内容至少包含：
  - `plan_workbench.confirmed.markdown`
  - `plan_workbench.confirmed.execution_blueprint`
  - `plan_workbench.confirmed.structured`
  - `plan_workbench.confirmed.derived_scope_patch`
  - `build_tasks`（由 confirmed blueprint 派生）
  - `build_checkpoint` 初始化状态
- MUST 在持久化成功后刷新当前网页（同一 workspace URL），以统一进入第二阶段视图与状态。
- MUST 在刷新后进入第二阶段上下文（可见任务清单、恢复状态、第二阶段操作入口）。
- MUST 在持久化失败时留在第一阶段弹窗，并给出可操作错误提示，禁止假刷新进入第二阶段。

### 第二阶段进入与虚拟主题任务方案生成（老板细节强制版）

> 本节为第二阶段核心执行细节，必须完全按本节实现，不得降级。

#### 进入第二阶段即时行为（MUST）

- MUST 在第二阶段页面加载完成后立即建立 `operation-sse` 连接（进入观察/执行通道）。
- MUST 在 SSE 建立后立即执行“虚拟主题任务方案检测”。
- MUST 若检测到“尚未建立虚拟主题任务方案”，立即根据第一阶段已确认方案生成虚拟主题任务方案。
- MUST 若已存在有效虚拟主题任务方案，则直接进入任务执行/恢复流程。

#### 虚拟主题任务方案生成触发条件（MUST）

- MUST 读取并依赖第一阶段 `plan_workbench.confirmed.*`：
  - `confirmed.markdown`
  - `confirmed.structured`
  - `confirmed.execution_blueprint`
  - `confirmed.derived_scope_patch`
- MUST 以“用户确认方案”为唯一来源，不得用运行期临时口述需求覆盖已确认方案。
- MUST 在 `confirmed` 缺失时阻断第二阶段执行，并返回明确错误（要求先完成第一阶段确认）。

#### 任务方案生成目标（MUST）

- MUST 将第一阶段全部关键信息转化为“虚拟主题建站任务方案”：
  - 页面类型与页面级规划
  - Header/Footer 风格与导航/链接规划
  - 色系、风格、内容方向
  - SEO 意图与关键词结构
  - 响应式策略
  - 可变内容与 meta 字段规划
- MUST 生成“超详细任务计划方案”，粒度到每个共享区块/页面区块可直接执行。
- MUST 让任务内容体现“为什么这样设计”，不是仅给任务标题。

#### 虚拟主题任务方案结构（MUST）

- MUST 在第二阶段建立并持久化 `virtual_theme_plan`（可放入 scope_json 或等价结构），至少包含：
  - `plan_signature`（对应 confirmed 方案签名）
  - `virtual_theme_strategy`（整体主题构建策略）
  - `shared_tasks`（header/footer 及共享模块任务）
  - `page_tasks`（按页面类型分组的区块任务）
  - `meta_field_matrix`（可变内容、meta 字段、默认值与来源）
  - `style_tokens`（颜色、字号、间距、语义色、可覆盖项）
  - `content_rules`（文案方向、CTA、内链、SEO 约束）
  - `responsive_rules`（断点策略与关键布局规则）
  - `execution_order`（严格执行顺序）
  - `risk_notes`（潜在冲突与回退建议）
- MUST 让 `build_tasks` 从该详细虚拟主题任务方案派生，保持字段契约一致。

#### 共享任务与页面任务细节（MUST）

- MUST 为 `shared:header` 生成具体任务内容：
  - 视觉风格、导航结构、品牌位、CTA 位、可变字段与默认值、响应式折叠规则、SEO/内链作用说明。
- MUST 为 `shared:footer` 生成具体任务内容：
  - 信息分组、政策链接、信任区块、社媒/联系位、可变字段与默认值、SEO/爬取友好说明。
- MUST 为每个页面类型生成具体区块任务：
  - 区块顺序、区块目标、设计理由、内容字段、可变 meta、CTA 方向、内链策略、SEO 关键词与锚点。

#### 第二阶段 SSE 与任务方案联动（MUST）

- MUST 在建立/重建虚拟主题任务方案时通过 SSE 输出明确阶段事件（如 `plan_detected`、`virtual_theme_plan_generated`）。
- MUST 在虚拟主题任务方案持久化完成后再发“可执行”事件，禁止先播报后落库。
- MUST 在任务执行时持续引用该虚拟主题任务方案，不得退回弱蓝图推断。

#### 第二阶段生成顺序细节（新增）

- MUST 按规划任务顺序执行，默认顺序固定为：
  1. 先生成共享任务：`shared:header`、`shared:footer`
  2. 再生成首页相关任务（`page:home_*`）
  3. 最后生成其他页面任务（按页面类型与块顺序依次执行）
- MUST 在任务清单构建时显式写入该顺序（通过 `execution_order` 或等价排序字段）。
- MUST 在恢复执行时保持同一顺序规则，不因重连改变任务先后。
- MUST 若发现前置共享任务未完成，禁止推进首页和其他页面任务。

#### 任务进度与恢复语义（超级重要，新增）

- MUST 以“任务单元（块组件）”作为最小进度单位。
- MUST 每当一个任务单元执行成功，即立即将进度推进到该单元并持久化为 `done`。
- MUST 在当前任务单元失败时保留已成功单元的完成状态，不得回滚。
- MUST 在下一次继续执行时按顺序跳过所有已成功单元（`status=done`）。
- MUST 从“按顺序遇到的第一个未成功单元（pending/failed/paused）”继续执行。
- MUST 保证“失败不影响已完成单元”这一语义在重连、刷新、恢复入口下都一致。

#### 页面实时可视化产出（新增重要细节）

- MUST 在每个页面类型完成其当轮可渲染任务后，立即产出该页面的实时可视化编辑页面。
- MUST 在页面产出后立即刷新页面类型 Tab 状态，使该页面 Tab 可点击并可进入编辑。
- MUST 让用户在生成过程即可查看已完成页面，不必等待全站任务全部完成。
- MUST 在 SSE 中输出“页面已可视化可编辑”的事件信号，驱动前端即时更新 Tab 与预览区域。
- MUST 在恢复执行时保留已可视化页面可访问状态，不因后续任务失败而隐藏已完成页面 Tab。

#### 组件级编辑与再生成能力（新增重要细节）

- MUST 在每个页面的可视化编辑中，为每个组件提供三类操作入口：
  - `微调组件`（定点优化当前组件）
  - `重建组件`（重写当前组件内容与结构）
  - `编辑组件文本`（打开编辑面板直接改组件内文案）
- MUST 支持“AI 再次生成内容”入口，用于在组件级快速重写文案/内容块。
- MUST 组件级操作复用并对齐现有 PageBuilder 可视化编辑能力（以当前可用交互作为实现参考，不另起一套心智模型）。
- MUST 在组件编辑面板中读取并利用组件 `meta` 信息作为生成与编辑上下文参考（字段说明、默认值、可变项、约束）。
- MUST 在组件级微调/重建后即时刷新当前页面预览，并保留可撤回/继续编辑入口。
- MUST 将组件级改动写回对应任务与产物映射（`result_ref` / 组件配置），保证恢复后状态一致。
- MUST 在组件级失败时不影响其他已完成组件可编辑状态，允许用户跳过或稍后重试。

#### Header/Footer 媒体资源选择约束（新增重要细节）

- MUST 在 Header/Footer 设计包含 logo 或图片地址时，统一通过框架本地媒体管理器选择文件。
- MUST NOT 让用户直接手填不受控的图片 URL 作为正式资源来源。
- MUST 为 logo 选择器设置默认目录：PageBuilder 站点 logo 目录（可配置），进入媒体管理器时默认定位到该目录。
- MUST 在任务蓝图与组件 meta 中记录媒体资源来源路径，保证恢复与二次编辑一致。
- MUST 在资源缺失或路径失效时给出可操作提示，并允许重新打开媒体管理器选择。

#### 主语言优先生成约束（新增）

- MUST 在组件编辑面板的 AI 生成（微调/重建/再生成）中优先使用会话主语言（`default_locale`）作为内容生成语言。
- MUST 在组件级 AI 请求上下文中显式携带主语言参数，避免模型按界面语言或历史上下文漂移。
- MUST 在虚拟主题生成阶段同样遵守主语言优先策略，包含：
  - 共享任务（header/footer）
  - 页面级区块任务
  - SEO/meta 文案生成
- MUST 在非主语言页面需要内容时采用“主语言基线 -> locale 映射/翻译”流程，不得直接跳过主语言基线。
- MUST 在任务产物中记录语言来源与目标语言（如 `source_locale` / `target_locale`），便于恢复与审计。

#### 方案语言（Plan Locale）统一约束（新增）

- MUST 在第一阶段提供“方案语言”设置项（`plan_locale`），用于控制方案书与方案交流的输出语言。
- MUST 在第一阶段所有方案流输出中使用 `plan_locale`（Markdown 方案、右侧 SSE 交流、微调/重建结果说明）。
- MUST 在用户确认第一阶段方案时持久化 `plan_locale`，并作为第二阶段默认语言基线之一。
- MUST 在第二阶段任务方案生成与确认弹窗中沿用 `plan_locale` 作为说明文档语言（任务 Markdown、友好提示、差异说明）。
- MUST 将 `plan_locale` 与会话主语言（`default_locale`）区分管理：
  - `plan_locale`：方案与任务计划说明语言
  - `default_locale`：页面内容主生成语言
- MUST 在两阶段上下文与提示词构造中显式携带 `plan_locale` 与 `default_locale`，避免语言混用。
- MUST 在只读历史预览中显示语言标识（如“方案语言：xx-XX”），便于用户确认当前查看语言。

#### 任务拆分细节规则（新增）

- MUST 在第二阶段确认前完成“全量任务拆分”，不允许执行时临时补拆。
- MUST 使用统一任务键规范，至少包含层级信息：
  - `shared:{section}`（如 `shared:header`、`shared:footer`）
  - `page:{page_type}:{block_key}`（页面块任务）
- MUST 为每个任务写入：
  - `task_key`
  - `order_index`（全局顺序）
  - `group_key`（shared/page 分组）
  - `dependencies`（前置任务）
  - `retry_count` / `max_retry`（重试计数与上限）
  - `status`（`pending|running|done|failed|paused|skipped`）
  - `started_at` / `finished_at`
  - `error_code` / `error_message`（失败时）
- MUST 显式区分三类拆分结果：
  - 共享任务（header/footer）
  - 首页任务（home 优先）
  - 其他页面任务（按页面与块顺序）
- MUST 在任务拆分阶段输出“任务总览摘要”，包含总数、分组数、依赖关系简述。

#### 任务进展状态实时更新规则（新增）

- MUST 以任务状态机驱动进展更新，最小单位为任务单元（块组件）。
- MUST 状态流转满足：
  - `pending -> running -> done`
  - `running -> failed`
  - `running -> paused`
  - `failed/paused -> running`（恢复后）
- MUST 每次状态变化都立即：
  1) 持久化任务状态与时间戳
  2) 更新 `build_checkpoint` 聚合计数
  3) 发送对应 SSE 事件
- MUST SSE 事件至少包含：
  - `task_key`
  - `prev_status`
  - `next_status`
  - `completed_count`
  - `total_count`
  - `progress_percent`
  - `updated_at`
- MUST 前端在收到 SSE 后实时刷新：
  - 总进度条
  - 任务列表状态标签
  - 当前执行任务高亮
  - 页面类型 Tab 完成态
- MUST 在断线重连后通过服务端快照对齐状态，防止前端显示回退或跳变。

#### 预览内链接与可视化编辑跳转（新增重要细节）

- MUST 在页面预览（未发布可视化编辑态）中处理内部 `a` 标签跳转。
- MUST 将站内页面类型链接映射为对应页面类型的“可视化编辑预览链接”，点击后仍留在可视化编辑上下文。
- MUST 在未发布状态下优先使用预览链接而非正式发布链接，避免跳出编辑链路。
- MUST 支持从当前页面内直接跳转到对应类型页面的可视化编辑（例如从首页跳到 about/contact 的编辑预览）。
- MUST 保留外部链接原语义（新开/离开）与站内编辑跳转语义分离，避免误重写外链。
- MUST 在页面类型 Tab 与预览内链接之间保持一致路由规则，确保两种跳转方式到达同一编辑目标页。

#### 第二阶段任务方案确认弹窗（新增强制细节）

- MUST 在“虚拟主题任务方案生成完成后、正式执行前”弹出第二阶段确认弹窗。
- MUST 使用 Markdown 作为任务方案展示格式（原文 + 预览双视图）。
- MUST 在该弹窗右侧提供 AI SSE 实时交流区，并支持模式切换：
  - `微调任务方案`（定点修改）
  - `重建任务方案`（全量重建）
- MUST 为两种模式提供友好提示文案：
  - 微调：`仅调整你指定的任务位置，适合局部优化。`
  - 重建：`重新生成整份任务方案，适合方向变化较大场景。`
- MUST 令第二阶段弹窗交互规则与第一阶段一致：
  - 右侧 Tab 模式切换
  - 默认收起手风琴说明
  - 禁止误触关闭（仅显式关闭）
  - 生成中跨模式并发禁用
- MUST 将帮助信息放在手风琴区域，默认保持收起；用户不操作时不自动展开、不打断输入流程。
- MUST 允许用户按需点击展开查看帮助，关闭后保持收起状态，避免频繁打扰。
- MUST 在用户确认第二阶段任务方案后，才允许进入真实任务执行。
- MUST 在用户未确认前，禁止推进 `build_tasks` 执行。

#### 第二阶段任务方案微调/重建语义（新增）

- 微调任务方案：
  - MUST 基于用户指定页面/区块/共享任务位置做定点修订；
  - MUST 保留其余已确认任务不变；
  - MUST 输出任务差异清单（变化任务键、变化字段、变化原因）。
- 重建任务方案：
  - MUST 重新生成完整虚拟主题任务方案 Markdown 与结构化任务清单；
  - MUST 使上一版未确认任务方案失效；
  - MUST 输出新旧版本摘要差异（任务总量、关键任务变化）。

#### 第二阶段微调/重建提示词设计（新增）

- MUST 与第一阶段一致，按模式分离提示词模板，不得混用：
  - `prompt_mode=refine_task_plan`
  - `prompt_mode=rebuild_task_plan`
- MUST 在生成代码实现时设计并固化两套提示词构造器，按语义路由调用。

##### 第二阶段微调提示词（`refine_task_plan`）MUST

- MUST 目标：仅调整用户指定的任务位置（页面/区块/shared/meta 字段）及必要联动项。
- MUST 禁止：重写无关任务、重排全量执行顺序、覆盖全部任务描述。
- MUST 输出：
  - `task_plan_markdown_patch`（更新后的完整 Markdown，但仅目标范围变化）
  - `task_blueprint_patch`（受影响任务字段差异）
  - `change_scope_report`（任务键级别改动清单与原因）

##### 第二阶段重建提示词（`rebuild_task_plan`）MUST

- MUST 目标：重新生成完整虚拟主题任务方案（Markdown + 全量任务清单）。
- MUST 禁止：默认继承上一版未确认任务方案的局部结果。
- MUST 输出：
  - `task_plan_markdown_full`
  - `task_blueprint_full`
  - `rebuild_summary`（任务总量、共享任务变化、页面任务变化、风险提示）

##### 第二阶段提示词输入上下文（MUST）

- MUST 包含：
  - 第一阶段 `confirmed.*` 全量信息
  - 当前第二阶段任务方案草稿（若为微调）
  - 用户最新要求
  - 模式参数（微调/重建）
  - 微调目标位置（`target_scope`）
  - 不可变约束（任务契约、执行顺序、恢复语义）

##### 第二阶段提示词落点函数（MUST）

- MUST 在代码中提供：
  - `buildTaskPlanPromptCommonContext(...)`
  - `buildTaskPlanRefinePrompt(...)`
  - `buildTaskPlanRebuildPrompt(...)`
  - `resolveTaskPlanPromptByMode(...)`
- MUST 将提示词快照与模式写入会话记录，支持审计和复现。

#### 第二阶段确认后的持久化（新增）

- MUST 将第二阶段确认结果写入独立确认区（如 `virtual_theme_plan.confirmed` 或等价结构）。
- MUST 以“第二阶段已确认任务方案”作为 `build_tasks` 派生唯一来源。
- MUST 在确认成功后刷新当前工作台页面并进入执行态；失败则停留弹窗并提示可操作错误。

#### 已确认方案的只读预览与复制（新增）

- MUST 在第二阶段任务方案确认后，将“确认版任务方案”持久化为只读快照（含 Markdown 与结构化数据）。
- MUST 在工作台提供“历史确认方案预览入口”（位置可在侧栏、详情抽屉或专用弹窗）。
- MUST 在该预览中只允许查看与复制，不允许编辑或再次微调该确认快照。
- MUST 提供复制能力：
  - 复制第二阶段确认版任务方案 Markdown
  - 复制第二阶段确认版结构化任务数据（JSON）
- MUST 同步展示第一阶段确认版方案（`plan_workbench.confirmed.markdown`）并支持复制。
- MUST 对两个阶段的确认版内容加只读标识（例如“已确认，只读”），避免误解为可继续编辑。
- MUST 若用户需要变更，必须走“新一轮微调/重建 -> 再确认”的流程，而不是直接修改历史确认快照。

#### 第二阶段确认后的友好提示与启动选择（新增）

- MUST 在用户点击“确认第二阶段任务方案”且保存成功后，弹出友好提示框。
- MUST 提示用户：方案已保存，并询问是否立即进行 AI 生成。
- MUST 提供两个明确按钮：
  - `立即生成`
  - `稍后生成`
- MUST 在用户选择 `立即生成` 后才启动第二阶段执行入口并连接执行 SSE。
- MUST 在用户选择 `稍后生成` 后保持当前页面与进度状态，不自动触发生成。
- MUST 在提示框中明确“稍后仍可从工作台继续生成”，降低用户焦虑。
- MUST 默认不自动开跑（即使保存成功，也需用户明确选择 `立即生成`）。

#### 执行一致性与恢复（MUST）

- MUST 以任务边界推进并保存，与第一阶段确认方案保持签名一致。
- MUST 若 `confirmed` 方案签名变化，则判定旧虚拟主题任务方案失效并触发重建。
- MUST 在恢复时优先校验“当前任务方案签名 = confirmed 签名”；不一致时先重建任务方案再恢复执行。

### 右侧 Tab + SSE 事件处理约束（补充细节）

- MUST 将右侧 Tab 的输入事件与 `PbAiPlanRunner` 的模式路由对齐，不得复用第二阶段 `PbAiOperationRunner`。
- MUST 在 SSE 连接参数中携带：`public_id`、`prompt_mode`、`round`、`target_scope`（微调目标位置）。
- MUST 在 `chunk` 到达时：
  - 左侧方案区更新 Markdown/预览；
  - 右侧当前 Tab 更新该轮 AI 流输出；
  - 非当前 Tab 不做内容写入。
- MUST 在 `done/error` 时将状态回写当前 Tab，会话可追踪“最后一次微调/重建结果”。

### 微调与重建语义细化（新增）

- 微调（定点重写）：
  - MUST 基于用户明确描述的目标位置执行修改（如页面、区块、SEO 段、Header/Footer、配色段）。
  - MUST 仅改目标位置及其必需联动字段，不得无关改写整份方案。
  - MUST 输出“本轮修改范围”清单，便于用户确认微调边界。
- 重建（整案重生）：
  - MUST 视为重新为用户生成一份新方案（`draft.markdown` + `draft.execution_blueprint` 全量重写）。
  - MUST 清空旧草案的局部增量上下文，不把旧方案内容作为默认继承项。
  - MUST 输出新的方案摘要与任务总量，便于与上一版对比是否满足预期。

### 微调/重建提示词设计（新增）

- MUST 在第一阶段按模式生成不同提示词模板：`prompt_mode=refine|rebuild`。
- MUST 以“模式语义”决定提示词目标与约束，不允许同一提示词同时混用两种目标。

#### 提示词输入上下文（通用）

- MUST 输入：站点基础信息、当前 round、用户最新补充要求、当前方案草稿（如有）、当前蓝图草稿（如有）。
- MUST 输入：用户声明的目标修改位置（页面/区块/SEO/Header/Footer/配色等）。
- MUST 输入：不可变约束（两阶段边界、任务字段契约、先落库后发 SSE、显式继续策略）。

#### 微调提示词（refine）MUST

- MUST 明确目标：仅修改用户指定位置与必要联动项。
- MUST 明确禁止：不得重写无关章节、不得重建整份方案。
- MUST 要求输出：
  - 更新后的 `draft.markdown`（仅目标段落发生变化）
  - 更新后的 `draft.execution_blueprint`（仅受影响任务变化）
  - `change_scope`（本轮改动位置清单）
  - `change_reason`（每处修改与用户要求的对应关系）

#### 重建提示词（rebuild）MUST

- MUST 明确目标：重新为用户生成整份方案与整份蓝图。
- MUST 明确禁止：不得沿用旧方案局部内容作为默认继承。
- MUST 要求输出：
  - 全量新 `draft.markdown`
  - 全量新 `draft.execution_blueprint`
  - `rebuild_summary`（新方案摘要、任务总量、关键差异点）

#### 提示词模板落地点（实现约束）

- MUST 在计划中预留并实施两套提示词构造入口：
  - `buildPlanRefinePrompt(...)`
  - `buildPlanRebuildPrompt(...)`
- MUST 由模式开关显式路由到对应入口，禁止在单入口中用 if/else 拼接成弱区分提示词。

#### 提示词结果校验（生成后）

- 微调模式下 MUST 校验：改动范围不超出 `change_scope` 及必要联动字段。
- 重建模式下 MUST 校验：输出为完整新方案，不依赖旧草案残片。
- 任一模式下 MUST 校验：`execution_blueprint.tasks[]` 字段契约完整，缺字段则判失败并返回可读错误。

### 方案生成提示词模板（实现细节，新增）

> 目标：提示词由系统设计并固化模板，严格承载老板定义的业务要求，不依赖临场自由发挥。

#### A. 通用系统提示词骨架（两模式共享）

- 角色定义（System）MUST 包含：
  - 你是 PageBuilder AI 建站方案架构师，只产出“第一阶段方案书 + 第二阶段任务蓝图草案”。
  - 你不得执行第二阶段任务，不得输出执行日志，不得触发 build 行为。
  - 输出必须是可渲染 Markdown 方案与结构化蓝图数据，且两者一致。
- 不可违背约束（System）MUST 包含：
  - 两阶段边界约束
  - 任务字段契约约束
  - Header/Footer 显式任务约束
  - “先完整规划后执行”约束

#### B. 重建模式提示词（`prompt_mode=rebuild`）

- 重建系统指令 MUST 强调：
  - 基于当前用户需求重新生成完整方案，不继承旧草案局部结论。
  - 方案必须使用 Markdown，并包含固定章节（风格、色系、Header/Footer、页面类型块级设计、执行顺序）。
  - 每个页面类型下每个块必须写“怎么设计 + 为什么这样设计”。
  - 同步生成完整 `draft.execution_blueprint.tasks[]`（含 shared 和页面块任务）。
- 重建输出格式 MUST 为两段：
  - `MARKDOWN_PLAN`：完整 Markdown 文本
  - `STRUCTURED_PLAN`：结构化 JSON（含 execution_blueprint）

#### C. 微调模式提示词（`prompt_mode=refine`）

- 微调系统指令 MUST 强调：
  - 仅修改用户指定位置（`target_scope`）及必要联动项。
  - 禁止全局重写，不得改动无关页面/区块结论。
  - 仍需保证 Markdown 章节结构完整，不得破坏文档可读性。
  - 同步输出受影响任务的蓝图差异。
- 微调输出格式 MUST 为三段：
  - `MARKDOWN_PLAN_PATCH`：更新后的完整 Markdown（但仅指定位置发生变化）
  - `BLUEPRINT_PATCH`：受影响任务列表与字段变更
  - `CHANGE_SCOPE_REPORT`：本轮改动范围与原因映射

#### D. 提示词参数化变量（构造器输入）

- `site_profile`: 站点基础信息、行业、受众、语言区域
- `page_types`: 页面类型清单
- `workspace_track`: 轨道偏好
- `user_requirements`: 用户最新补充要求
- `target_scope`: 微调目标位置（重建时为空）
- `current_markdown`: 当前草案 Markdown（重建可仅作参考，不继承）
- `current_blueprint`: 当前草案蓝图
- `hard_constraints`: MUST 约束清单（本计划提炼）

#### E. 提示词实例文本（计划固化稿）

```text
[SYSTEM]
你是 PageBuilder AI 建站方案架构师。你的职责仅限第一阶段：生成方案书与任务蓝图草案。
严禁触发第二阶段执行行为。输出必须是 Markdown 方案 + 结构化蓝图，且内容一致。
方案必须清晰解释“为什么这样设计”。

[MODE]
当前模式：{prompt_mode}
- refine: 仅修改 target_scope 指定位置与必要联动，不得全局重写。
- rebuild: 重新生成完整方案，不继承旧草案局部内容。

[INPUT]
站点信息：{site_profile}
页面类型：{page_types}
用户最新要求：{user_requirements}
微调目标：{target_scope}
当前草案Markdown：{current_markdown}
当前草案蓝图：{current_blueprint}
硬约束：{hard_constraints}

[OUTPUT REQUIREMENTS]
1) 方案必须为 Markdown，包含：
- 风格总览
- 颜色色系与选型原因
- Header 设计
- Footer 设计
- 页面类型设计总览
- 分页面块级设计（逐块说明设计目标与理由）
- 执行顺序与任务蓝图摘要
2) 同步输出 execution_blueprint.tasks[]，覆盖 shared:header/shared:footer 与每页每块任务。
3) 每个任务必须包含字段：task_key/page_type/region/block_key/component_kind/dependencies/status/field_plan/style_brief/palette_usage/content_brief/seo_brief/result_ref。
4) refine 模式必须附带 change_scope_report；rebuild 模式必须附带 rebuild_summary。
```

#### F. 提示词构造与调用落点

- MUST 在后端实现：
  - `buildPlanPromptCommonContext(...)`
  - `buildPlanRebuildPrompt(...)`
  - `buildPlanRefinePrompt(...)`
- MUST 由模式路由器统一调度：
  - `resolvePlanPromptByMode(prompt_mode, context)`
- MUST 将最终提示词快照写入 `plan_workbench.conversation`，用于审计与复现。

### 第一阶段输出物 MUST

- MUST 产出 `draft.markdown`（用户可读方案书）。
- MUST 产出 `draft.execution_blueprint`（第二阶段任务蓝图草案）。
- MUST 使用 Markdown 作为方案展示格式（左侧原文 + 实时预览一致来源于同一 Markdown 文本）。
- MUST 覆盖用户所选的全部页面类型（第一阶段方案必须囊括所有选中页面，不得遗漏）。
- MUST 让方案书包含：
  - 色系与选型原因
  - 整体主题风格
  - Header / Footer 风格与导航/link 规划
  - 首页与其他页面 SEO 结构和内容布局
  - 响应式策略
  - 第二阶段完整执行顺序
  - 每个页面块级规划

### 方案 Markdown 展示结构 MUST（补充细节）

- MUST 在方案 Markdown 中包含以下固定章节（允许扩展，不允许缺失）：
  - `风格总览`
  - `颜色色系与选型原因`
  - `Header 设计`
  - `Footer 设计`
  - `页面类型设计总览`
  - `分页面块级设计`
  - `执行顺序与任务蓝图摘要`
- MUST 在“分页面块级设计”中按页面类型展开（如：首页、关于页、联系页、政策页等）。
- MUST 对每个页面类型写清：
  - 块列表（按顺序）
  - 每个块的设计目标
  - 每个块的设计理由（为什么这样设计）
  - 关键内容方向（文案/CTA/SEO/内链）
- MUST 对 Header/Footer 单独说明：
  - 视觉风格定位
  - 导航/链接信息架构
  - 与主体页面风格的一致性关系
- MUST 让“为什么这样设计”成为必填说明，不得只给块名称而无理由。
- MUST 在方案中提供“页面覆盖清单”（selected pages checklist），明确标注每个已选页面均有对应设计计划。

### 第一阶段到第二阶段页面映射约束（新增）

- MUST 以第一阶段已确认方案中的“全页面覆盖清单”作为第二阶段任务计划的唯一页面来源。
- MUST 让第二阶段任务方案严格按这些页面生成任务，不允许临时增删页面范围。
- MUST 若页面覆盖清单与任务计划页面集合不一致，阻断第二阶段确认并提示修正。

### 方案确认后执行门禁 MUST

- MUST 在确认时一次性生成并持久化完整 `execution_blueprint.tasks[]`。
- MUST 覆盖 `shared:header`、`shared:footer`、每页每块任务。
- MUST 让第二阶段 build 只消费 `plan_workbench.confirmed.execution_blueprint.tasks`。
- MUST NOT 允许边执行边临时拆任务。

### 任务结构 MUST（最低字段）

- MUST 包含：`task_key/page_type/region/block_key/component_kind/dependencies/status`。
- MUST 包含：`field_plan/style_brief/palette_usage/content_brief/seo_brief/result_ref`。
- MUST 让 `field_plan[*]` 至少具备 `field/generation_mode/instruction`，且 `generation_mode` 支持 `ai|fixed|derived`。

### 持久化模型 MUST

- MUST 使用并维护 `scope_json.plan_workbench`：
  - `status/round/source_signature/conversation/draft/confirmed`
- MUST 使用并维护 `scope_json.build_checkpoint`：
  - `plan_signature/status/current_task_key/last_completed_task_key/next_task_key/completed_count/total_count/updated_at`
- MUST 以 `build_tasks[*].status` 为任务完成真相。
- MUST NOT 以 `build_checkpoint` 覆盖任务完成真相。

### 第二阶段执行与恢复 MUST

- MUST 固定执行顺序：读取任务 -> 找首个未完成 -> 标记 running -> 执行 -> 保存产物 -> 标记 done -> 更新 checkpoint -> 发 SSE -> 下一任务。
- MUST 遵守“先落库，后发 SSE”。
- MUST 在“任务间断线”时从下一未完成任务继续。
- MUST 在“任务内断线”时从该任务重跑。
- MUST NOT 做块内半成品续写恢复。
- MUST 在 lease/连接失效后停止启动新任务；可安全收尾则收尾并将 `build_checkpoint.status` 置为 `paused`。

### API 语义 MUST

- MUST 提供：`post-start-plan`、`post-confirm-plan`、`post-resume-build`。
- MUST 让 `post-resume-build` 仅做续跑，不重置任务。
- MUST 让 `post-start-build` 在无 confirmed 方案时返回 `PLAN_REQUIRED_BEFORE_BUILD`。
- MUST 让 `post-start-build` 在同 `plan_signature` 存在未完成任务时默认拒绝重置，并提示使用恢复入口。

### 工作台重入交互 MUST

- MUST 移除默认自动续跑。
- MUST 在“有 confirmed 方案 + 有 pending/running/paused 任务”时弹继续提示。
- MUST 固定按钮为：`继续` / `稍后再说`。
- MUST 使用两类文案：
  - 有活跃 build：`检测到上次生成仍在进行，是否立即继续查看并接管进度？`
  - 无活跃但有未完成：`检测到上次未完成的生成任务，是否从上次进度继续？`
- MUST 在点击 `继续` 后：
  - 旧执行 `queued/running`：直接重连旧 `execution_token` 的 `operation-sse`。
  - 旧执行已停但有未完成：调用 `post-resume-build` 后连接新 `operation-sse`。
- MUST 在点击 `稍后再说` 后不自动执行 build，并保留当前进度与已有结果可见。

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

### 4.1 阶段一：方案（Plan）

- 事件流固定：`start/progress/chunk/done/error`。
- `chunk` 仅用于 Markdown 方案追加和预览刷新。
- 阶段一不触发 build，不更新构建任务状态。

### 4.2 阶段二：执行（Build）

- 仅消费 `plan_workbench.confirmed.execution_blueprint.tasks[]`。
- 无 confirmed plan 时，`post-start-build` 返回 `PLAN_REQUIRED_BEFORE_BUILD`。
- 任务按边界落库与播报，顺序固定为“先落库，再发 SSE”。

## 5. 数据契约

### 5.1 scope 持久化

- `scope_json.plan_workbench`
  - `draft`：方案草稿
  - `preview`：预览快照
  - `confirmed`：确认版方案与 `execution_blueprint`
- `scope_json.build_checkpoint`
  - `cursor`：恢复索引
  - `last_task_id`：最近任务
  - `reason`：暂停原因
  - `resume_hint`：恢复提示信息

### 5.2 执行蓝图任务字段（最低要求）

- `task_id`
- `task_type`（含 `shared:header`、`shared:footer`）
- `title`
- `workspace_track`
- `page_type` / `locale` / `block_id`
- `field_plan`
- `style_brief`
- `palette_usage`
- `content_brief`
- `seo_brief`

## 6. 双轨策略（站点级二选一）

- `workspace_track=html_blocks`：页面区块轨，`blocks[] + ai_html`，按块再生成。
- `workspace_track=virtual_theme`：高级虚拟主题轨。
- 首版同站不混用两轨；默认轨切换与产品口径统一后再落地。

## 7. 接口与交互

### 7.1 关键接口

- `post-start-plan`
- `post-confirm-plan`
- `post-resume-build`
- `post-start-build`（需 confirmed plan 前置校验）

### 7.2 重入交互（禁止默认自动续跑）

- 检测到 running：提示“继续观察执行流”。
- 检测到未完成任务：提示“继续执行剩余任务”。
- 统一按钮：`继续` / `稍后再说`。

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

- 第一阶段先生成“建站方案书”，SSE 组件只负责把方案流追加到弹窗并实时预览，不承担任何第二阶段执行职责。
- 用户确认方案后，先一次性把第二阶段所有块级任务规划完整并持久化，再进入生成主题；第二阶段只能执行已确认方案，不允许再按一句话需求临时拆任务。
- 第二阶段必须具备任务级断点续跑：每完成一个任务就保存一次；下次进入工作台友好提示是否继续；用户确认后从未完成任务继续。

### Requirements And Measures

#### 要求 1：第一阶段 SSE 只负责追加方案流

- 第一阶段使用独立 `PbAiPlanRunner` 和方案弹窗。
- 不复用工作台右侧日志终端、不显示 build guard、不更新块级任务进度、不参与页面预览切换。
- plan SSE 只处理 `start/progress/chunk/done/error` 五类事件。
- `chunk` 只做 Markdown 追加和实时预览刷新。

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
  - `draft.markdown`
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
  - `draft.markdown` 与 `draft.execution_blueprint` 在 done 后存在
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

#### 第一阶段：方案（Plan）

- T1 方案弹窗基础（打开/关闭保护/Markdown 原文+预览布局）
- T2 右侧模式 Tab（微调/重建）与提示文案/手风琴说明（默认收起）
- T3 方案右侧 SSE 交流窗口（仅 plan SSE：start/progress/chunk/done/error，不混入 build）
- T4 `plan_locale` 设置与落库（方案语言）+ 与 `default_locale` 语义分离
- T5 方案 Markdown 结构与章节完整性校验器（缺失章节判失败）
- T6 页面覆盖清单生成与校验（用户所选全部页面必须在方案内）
- T7 第一阶段提示词构造器：重建（`buildPlanRebuildPrompt`）+ 输出校验
- T8 第一阶段提示词构造器：微调（`buildPlanRefinePrompt`，含 target_scope）+ 输出校验
- T9 第一阶段方案微调（定点重写）SSE 流与 change_scope_report 落库
- T10 第一阶段方案重建（整案重生）SSE 流与 rebuild_summary 落库
- T11 第一阶段确认接口（post-confirm-plan）：写 confirmed.* + 初始化 build_tasks/build_checkpoint + 刷新进入第二阶段

#### 第一阶段：域名选择（标签区）

- T12 域名选择标签区 UI（独立区域，不与方案输入混杂）
- T13 可选域名筛选（可建站但未建站域名列表）
- T14 AI 推荐域名弹窗（正式环境）+ 供应商绑定选择
- T15 推荐域名可用性检测（按供应商策略）+ 本地供应商简化校验
- T16 域名选择结果落 scope（域名+供应商），供后续阶段使用

#### 第二阶段：虚拟主题任务方案（Task Plan）

- T17 第二阶段进入即连 SSE + 检测是否已存在虚拟主题任务方案
- T18 虚拟主题任务方案生成器（由第一阶段 confirmed.* 派生超详细任务方案）
- T19 任务拆分与排序器（shared->home->other；dependencies/order_index/group_key）
- T20 第二阶段任务方案确认弹窗（Markdown 原文+预览 + 关闭保护）
- T21 第二阶段右侧模式 Tab（微调/重建）+ 手风琴说明（默认收起）
- T22 第二阶段任务方案 SSE 交流窗口（refine_task_plan/rebuild_task_plan，不混流）
- T23 第二阶段提示词构造器：重建任务方案（`buildTaskPlanRebuildPrompt`）+ 输出校验
- T24 第二阶段提示词构造器：微调任务方案（`buildTaskPlanRefinePrompt`，含 target_scope）+ 输出校验
- T25 第二阶段确认保存（virtual_theme_plan.confirmed）+ 刷新页面 + 弹“已保存，是否立即生成”
- T26 “立即生成/稍后生成”提示框与行为（默认不自动开跑）

#### 第二阶段：执行器、进度、恢复

- T27 执行器任务循环（先落库后 SSE；result_ref 回写；按任务单元推进）
- T28 任务状态机与 SSE 进度事件（prev/next/completed/total/percent/updated_at）
- T29 断点恢复引擎（跳过 done；从首个未成功继续；任务内失败重跑该任务）
- T30 页面完成即实时可视化渲染 + 页面类型 Tab 即时可编辑（SSE 事件驱动）

#### 页面内跳转与组件级能力

- T31 预览内链接重写器（仅站内页面类型链接 -> 可视化编辑预览路由）
- T32 组件级操作入口（微调/重建/文本编辑/AI 再生成）与 meta 上下文注入
- T33 组件级改动持久化与即时预览刷新（失败不影响其它组件）

#### 共享组件媒体资源（Header/Footer）

- T34 Header/Footer 媒体管理器接入（logo/图片选择）+ 默认 PageBuilder 站点 logo 目录定位
- T35 媒体资源路径落库与恢复一致性（组件 meta/任务蓝图）+ 失效重选提示

#### 历史预览、最终落库、验收

- T36 历史确认方案只读预览中心（第一阶段+第二阶段同屏展示与复制）
- T37 最终创建站点阶段展示（预览地址/正式地址/PageBuilder 页面管理入口）
- T38 最终落库门禁（正式环境域名联通性未通过禁止建站）
- T39 E2E 用例补齐与收官门禁（含 E2E-13：域名可访问站点）

### 12.1C 任务-细节对齐规则（新增）

- MUST 任何新增计划细节都在 12.1/12.1B 追加至少一条可执行任务，不允许“有细节无任务”。
- MUST 任务标题可直接映射到代码改动点与验收用例，不使用抽象空任务。
- MUST 在任务状态更新前，先检查该任务是否覆盖对应细节条款；未覆盖不得标记完成。

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
