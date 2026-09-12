# Weline AI 工程交付流程

> 本文是 Weline 框架仓 AI 客户端执行开发任务的**强制工作流**入口。与 [AI开发治理](./AI开发治理.md) 配套：治理定义权威与门禁，本文定义**按什么顺序做**。

## 适用对象

- Codex、Cursor 及其他通过 `weline_project_intelligence` MCP 接入的客户端。
- **非编码**（闲聊、概念解释、与本仓实现无关说明）：**禁止**调用 MCP（含 ensure / `prepare_project` / 计划门禁）。
- **编码/工程**（改代码、修 bug、补测试、模块文档、诊断/评审、部署规划、验收结论）：必须遵循本文完整工作流。

## 核心原则（Vibe Coding 工程化）

1. **先定义问题，再写代码**——目标、非目标、成功标准不明时不执行。
2. **先计划，再执行**——`plan.md` / `task.md` 或 TaskContract 先于 `apply_compact_edit`。
3. **每步可验证**——「看起来对」不算完成；要有测试、命令或 WebUI 证据。
4. **AI 不能自证正确**——只信可复现命令、测试输出、diff 与 Browser 结果。
5. **规范代码化**——能写成 lint/test/schema/CI 的，不只用自然语言提醒。

## 强制阶段

```text
0 引导与 ready     ensure-project-guidance → prepare_project
1 定位与需求确认   需求.md / 用户确认 / set_session_directives（临时）
2 扩展点选型       扩展点选型.md → 文档索引 / doc/event / Query / Hook
3 计划拆解         plan.md + task.md（或 TaskContract）
4 实现             get_edit_bundle → apply_compact_edit（授权范围内）
5 三维复审         架构 / 缺陷 / 安全
6 分层测试         单测 → 运行时 → WebUI（按变更表面）
7 收口             文档对齐（README/需求/开发日志）+ 门禁表 + 交付证据
```

### 0. 引导与 ready

- 运行 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php`。
- 调用 `prepare_project`；仅 `status=ready` 且在 `dev` 分支继续。
- **硬约束在 MCP 内**：立即阅读 `agent_guidance.hard_constraints`（`hard-constraints.v1`，由 [AI硬规则索引](./AI硬规则索引.md) 编译）。`session_startup_notices` 只指路，不展开 Theme/Taglib/i18n 等框架细则。
- 交付 URL 机器契约：`agent_guidance.feature_delivery_urls`、`closeout_delivery_reminder`。
- 任务细则：`resolve_task_context` → `workflow_contract.v1` surfaces（如 `frontend_development`）。
- MCP 不可用 / 容量物化失败时的受限原生回退：见 `hard_constraints.mcp_operational` 与 [AGENTS.md](../../../AGENTS.md)；禁止借此绕过 `blocked`。
- 后续工具携带 `readiness_id` + `client_session_id`。

### 1. 定位与需求确认

- 对照归属模块 `doc/需求.md`（REQ-ID、范围、验收、待确认项）。
- 用户已确认的需求优先于文档推断；临时决定用 `set_session_directives`，**不自动写入** `需求.md`。
- 用 `resolve_task_context` 取有界证据；禁止凭通用框架经验发明需求或事件名。
- **每条可执行编码/工程用户需求（硬门槛，`user_requirement_full_workflow` + `requirement_feature_kind_gate` + `requirement_implicit_analysis_skill_decision` + `feature_add_requires_current_ui_review` + `feature_ui_keep_simple_top_tabs` + `requirement_framework_scrutiny` + `architecture_first_for_requirements` + `framework_decoupled_only`）**：提出后须**立即**分析当前环境**隐形需求**（已有 Taglib/API/Model/Provider 表、映射页、拉取/同步、耦合风险等）写入 `implicit_requirements`，并判定 `work_kind=feature|non_feature`。再据分析设 `ui_skill_decision=participate|skip`：**禁止凡 feature 一律强制原型+UI**。`participate`（有布局/交互/CSS 重设计）时须让 `prototype`+`frontend-design` 参与并规划 `type=shentu`；`skip` 须写 `ui_skill_rationale`（≥24 字，如纯标签/API/Provider 接线）。**若功能落在既有 Web UI 表面加功能**：设计前必须先打开/截取**当前页**并按 [审图](../../../../../dev/ai-command/theme/审图.md) 审现图再定位置；若现页已乱，须连同现页一并重设计（`feature_add_requires_current_ui_review`），禁止在混乱信息架构上硬塞控件。**功能页一页宜简**：一屏一个主职责；进度总览与配置/操作、列表与设置等不同职责不得默认堆同一长页，能分段的用**顶部 Tab**切换（`feature_ui_keep_simple_top_tabs`）。再理解意图/范围/非目标/成功标准，并按**框架信息审视**合理性：若字面需求不合理（耦合写法、有 Taglib 仍手写控件、发明事件、错误分层、过度设计等），**禁止原样照做**，须给出更合理做法并写入 `plan.requirement_scrutiny`，同时将 `plan.requirements` 改为纠偏后方案（合理则写「合理」/「无调整」）；再 `submit_task_plan` 建立从**需求分析到验收**的完整会话工作流（`plan.requirements` ≥1 + **`plan.requirement_scrutiny` 必填** + **`plan.architecture` 必填**（按**框架信息**将每条需求映射为**解耦方案**：扩展点/机制、归属模块边界、关键路径/分层；≥40 字；`trivial` 亦必填）+ **`plan.coupling_findings` 必填**（无耦合写「无」/「无耦合」）+ `acceptance` ≥1 + 任务拆解）；**禁止**耦合写法与从需求直接跳到补丁。过程中发现耦合须更新 `coupling_findings`，并向用户汇报时包含「**耦合提示**」小节；有纠偏须包含「**需求纠偏**」小节。**不得**等到写码或 `PLAN_REQUIRED` 才补计划。需求不明时先澄清，禁止边写边猜。非编码任务不进入本阶段。

### 2. 扩展点选型（写代码前硬关）

在改任何业务代码前，必须完成机制选型并记录证据路径：

| 意图 | 优先机制 | 索引入口 |
|------|----------|----------|
| 通知 / 副作用 | Event / Observer | `Framework/doc/event/README.md` |
| 读数据 | Interface / QueryProvider / `w_query()` | 模块 doc / BinQuery 文档 |
| 写 / 命令 | 归属 Interface / Hook / Queue | 模块 doc |
| UI 控件 | Taglib / Hook | 模块 Taglib 文档 |
| 跨模块直调对方 Service/Model | **禁止** | — |

无现成扩展点时：在 TaskContract 中声明「将新建」并补文档，**禁止静默发明事件名**。

Hook 专项：[Hook创建规范.md](../../Hook/doc/Hook创建规范.md)。Event 专项：[事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md)。Taglib 控件：[场景映射表.md](../../Taglib/doc/场景映射表.md)。

详见 [扩展点选型](../Framework/doc/3-开发/扩展点选型.md)（路径：`app/code/Weline/Framework/doc/3-开发/扩展点选型.md`）。

### 3. 计划拆解

- 模块级：`doc/开发/plan.md`（阶段、范围、完成标准）+ `doc/开发/task.md`（可勾选任务）。
- **MCP 会话计划（硬门槛）**：用户每提出可执行需求即须 `submit_task_plan` 提交 `task-plan.v1`（**`requirements`≥1**、`goal`、`extension_point`、**`requirement_scrutiny` 必填**（`requirement_framework_scrutiny`：合理写「合理」/「无调整」；不合理须写问题+更合理做法并改写 requirements）、**`architecture` 必填**（`architecture_first_for_requirements` + `framework_decoupled_only`：按框架/扩展点选型映射为解耦方案；≥40 字；含 `trivial`）、**`coupling_findings` 必填**（≥1；无则「无」/「无耦合」）、`dev_tasks`、≥1 条 `acceptance` 且**至少 1 条 `type=unit`**；可选 `scope_paths` / `forbidden` / `risk` / `workflow_phase`）。未提交或缺少有效 `requirement_scrutiny`/`architecture`/`coupling_findings` 则 `get_edit_bundle` / `apply_compact_edit` 返回 **`PLAN_REQUIRED`**（硬约束 `user_requirement_full_workflow` / `requirement_framework_scrutiny` / `architecture_first_for_requirements` / `framework_decoupled_only` / `task_plan_before_edit` / `plan_then_tdd_required`），响应内带 **`plan_workflow`**（需求分析→**框架审视纠偏**→**框架解耦架构**→…→**TDD 红绿**→实际跑测→审查→收口）——代理须**立即**补计划并 `submit_task_plan`，不得当作完成。实现须 **TDD**：先失败测试再最小实现至绿，再亲自执行测试命令；`unit` 的 `passed` evidence 须像真实跑测输出（含 phpunit/PASS 等），否则 `closeout_allowed=false`。收口汇报须含「**需求纠偏**」（无调整/合理或逐条列出）与「**耦合提示**」（无耦合或逐条列出）。`risk=trivial` 仍须计划与 `requirements` 与 `requirement_scrutiny` 与 `architecture` 与 `coupling_findings` 与 unit，且 `scope_paths` ≤3。计划仅存当前 MCP 进程会话，不写仓库。
- **计划合规审核（硬门槛，`task_plan_compliance_review`）**：`submit_task_plan` / `review_task_plan` 须从以下维度判定计划是否合规——**(1) 架构层映射**、**(2) 解耦**、**(3) 电商合规**（触及站店渠/商品/结账/支付等时须写合规要点；非电商可 N/A）、**(4) 原型设计**（`ui_skill_decision`；participate→prototype+frontend-design+shentu）、**(5) e2e 用例完整性**（feature→**每章独立完整功能通路 `type=e2e`** + **计划级 `e2e-plan-suite` 组套件**；Agent 自动跑测自行闭环，禁止甩人；收口前须组测整条功能链路 PASS）、**(6) 计划体量是否过大**（单次宜 2–4 小时；过大须拆章节/child）、**(7) 逻辑是否严谨闭环**。原则上 `dev_tasks` **须有章节细节**（id/title 含 `chN`/`章节`/`chapter`）；**每一章必须硬绑定 `acceptance_ids`** 到具体 acceptance（feature 章须含**互不共用**的通路 `type=e2e`；组套件单独一条）；多需求章节计划须 `covers_requirements` 覆盖全部 requirements；**仅当绑定验收全部 `passed`+evidence 后**才允许该章 `done` 并开下一章（至多一个 `in_progress`）；**全部章完成后统一跑计划组套件 e2e 才可 closeout**。`review_task_plan` 返回 `compliance_dimensions` + gaps。
- MCP 写码：`get_edit_bundle` 携带完整 **TaskContract**（goal、requirements、known_paths、known_symbols）。
- 原子任务：单次变更宜 2–4 小时可验收；过大则拆 child_requests。

### 4. 实现

- 先有已接受的会话计划，再 `get_edit_bundle` → `apply_compact_edit`（`ready_for_edit=true` 时）。
- 只改任务授权范围；保留用户无关工作区改动。

### 5. 三维复审

- 架构：模块边界、扩展点是否正确。
- 缺陷：边界条件、错误路径。
- 安全：凭据、ACL、输入校验、跨站边界。

### 6. 分层测试与验收

**自行验证（硬门槛，`agent_self_verify_before_done` + `plan_then_tdd_required` + `acceptance_phase_requires_shentu`）**：需求须先 `submit_task_plan`；实现按 **TDD**（红→绿→重构）；结束后 Agent **必须亲自执行**测试命令并按验收层级验证，再标 acceptance / 向用户宣称完成。禁止「只改代码就收口」。`unit` 的 `passed` evidence 须含可识别的真实跑测输出；否则 `review_task_plan.closeout_allowed=false`。未完成只能报告「代码已改，TDD/测试未跑通」。

| 变更表面 | 最低证据 |
|----------|----------|
| 纯函数 / Service 局部 | 聚焦单测 |
| 命令 / API / 持久化 | 真实命令或 API 结果 + 必要单测 |
| Model / Controller / 注册表 | bump `etc/module.php` version + `setup:upgrade` 或 `--route` 成功（见 [模块版本与升级门禁](../Framework/doc/3-开发/模块版本与升级门禁.md)） |
| i18n CSV / 新增可翻译文案 | `zh_Hans_CN.csv` + `en_US.csv` 对齐 + `php bin/w i18n:collect`（见 [模块翻译CSV规范](../I18n/doc/模块翻译CSV规范.md)） |
| 页面 / 交互 / SSE | 真实 WLS + **当前宿主可用的真实 Browser** 操作员路径（**WB-OP**）；**须截图 + 对照模块 `doc/原型设计.md` 视觉清单（WB-VIS）**；多断点 375 / ≈768 / ≥1024 |
| 文档 / 规则 | Diff、链接、渲染检查；**与实现对照无漂移** |

**分章计划**：原则上每章硬绑定 `acceptance_ids` 对应**一个可完整验收的功能通路闭环**（feature 为独立 e2e，覆盖该章前后端/整体逻辑；Agent 自动跑 Playwright 自行闭环）；绑定验收全部 passed+evidence 且 **UT → RT → WB → DL** 四段全 pass 才开下一章（`task_plan_compliance_review` + `chapter_ut_rt_wb_dl` + `plan_full_pathway_e2e_suite`）；须先 `update_task_plan_progress` 标进度再开下一章。含 Web 的章：**WB = WB-OP + WB-VIS**；截图存 `doc/evidence/ch{N}/`；禁止 curl/单测/纯文字替代 Browser 视觉证据。**全部章节完成后**：必须再跑计划级 `e2e-plan-suite` 统一组测整条功能链路；组套件未 PASS 禁止宣称计划完成。禁止只完成一部分不测就汇报，禁止请用户手动测用例闭环。

未完成对应层级时，只能报告「代码已改，测试未完成」或「WebUI 验收未完成」。

**验收阶段审图（硬门槛，`acceptance_phase_requires_shentu`）**：`ui_skill_decision=participate` 或含视觉 Browser/UI 验收时，verify 阶段必须对验收截图执行 [审图](../../../../../dev/ai-command/theme/审图.md)（线稿→原型→UI→主题），`acceptance` 须含 `type=shentu` 且 passed evidence 含审图/线稿/checklist 信号；弱证据则 `closeout_allowed=false`。非功能且无 UI 可省略或 `na` 并写明原因。

**结束汇审（硬门槛，`closeout_requires_huishen`）**：宣称完成前必须写 `huishen_notes`（含「汇审」），对照需求/架构/验收/(功能时)原型·UI·审图结论；用户汇报须含「**汇审**」小节。缺汇审则 `review_task_plan.closeout_allowed=false`。

**收口高压线（凡含页面/UI）**：

1. AI **必须**用**当前宿主可用的真实 Browser**（IDE Browser / Browser MCP / Playwright 等，**不绑定 Cursor**）**亲自按用例自测**（WB-OP）；单测 / curl **不能**替代。
2. **每次打开/导航验收页前必须禁用 HTTP 缓存**（硬，`browser_cache_disabled_on_open`）：Cursor 先 `Network.setCacheDisabled`，失败则 `Page.reload({ignoreCache:true})`；禁止用默认磁盘缓存验本回合 CSS/JS/HTML。
3. 面向用户的完成/阶段性汇报**末尾必须**有「交付地址」小节（探活过的 http(s) Markdown 链接）；禁止省略。
4. 细则见 [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)。

### 前端开发规范（MCP 写死表面 `frontend_development`）

编辑 `*.phtml`、Theme 部件、布局、partial 时，`resolve_task_context` / `get_edit_bundle` 的 `workflow_contract.v1` 会附带 **`frontend_development`（前端开发规范）**表面。这是一套 Theme/前端规范，**不是**名为 `weline-code` 的独立技能；section 身份属性只是其中一条硬约束。

**【高压线 · 自研主题 UI】** 所有前台/后台可视化界面**必须**使用 Weline 自研主题体系（**Weline UI 2.0**）：组件类名（`w-field` / `w-input` / `w-button` / `w-select` 等）+ 主题 CSS 变量 Token（`--color-*` / `--weline-theme-*` / spacing·radius·shadow）。**禁止** Bootstrap / Element / Ant Design 等第三方 UI；**禁止**硬编码 `#hex` / `rgb()` / 随意 `px` 间距；地址/地区级联**必须**用 `<w:theme:address>`，禁止手写国家/省/市 input。权威：`Theme开发总指南.md`、`theme-css-variables-only.md`、`Taglib/场景映射表.md`。本条由 MCP `hard-constraints.v1`（`weline_ui_theme_first`）与 `frontend_development` surface 强制下发，不在宿主引导中复述。

**【高压线 · 基础组件只用主题规范变量】** 开发/改主题时，基础组件（`w-button` / `w-input` / `w-select` / `w-textarea` / `w-field` / `w-badge` / `w-alert` / `w-text` / `w-menu` / `w-dialog` / `w-toast` / `w-table` 及 `foundation.css` 同级）**必须**只消费 `--weline-theme-*` / `--color-*` / `--backend-color-*`（及 spacing·radius·shadow）。**禁止**为基础组件私写 hex/rgb 或平行色变量。品牌主题只改 `colors/_*.css` 色盘叶子；默认语义合同继承自 `variables/_colors.css` + `colors/_default.css`。MCP 规则 id：`theme_base_components_token_only`。权威：`theme-semantic-color-matrix.md`。

**【高压线 · CSS/主题必须三技能齐读】** 凡任务/需求提到 **CSS** 或 **主题/theme**，写样式或改主题前**必须**先加载并服从：（1）UI 技能 `frontend-design`；（2）原型技能 `prototype`；（3）主题技能 `weline-theme-development`（MCP `get_skill`）。主题 Token 仍优先；禁止只读其一就动手。MCP 规则 id：`css_or_theme_requires_ui_prototype_theme_skills`。

**【高压线 · UI 技能必须叠加主题技能】** 凡启用宿主 `frontend-design` / 通用 UI / 审美类技能写本仓前台或后台界面，**必须同时**用 MCP `get_skill(weline-theme-development)`（或 surface `frontend_development`）加载主题技能，并服从主题 Token 文档。主题 CSS Token 与 Weline UI 2.0 **优先于**通用 UI 技能的自造色板；**禁止**按 UI 技能另发明 hex/rgb、px 间距阶梯、圆角阴影套件或平行 design token。UI 技能仅可指导构图、层次与文案。宿主 `SKILL.md` 仅为可选薄壳。MCP 规则 id：`ui_skill_requires_theme_skill` / `mcp_skills_fetch_from_mcp`。

权威总览：`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`。机器可读摘要见 `workflow_contract.v1.frontend_development`（`template_surface_rules` 仅为兼容别名）。

强制要点：

0. **自研主题 UI 优先（高压线）**：Weline UI 2.0 组件 + 主题 CSS 变量；禁止第三方 UI / 硬编码视觉字面量 / 手写地址级联。提到 CSS/主题时必须齐读 `frontend-design` + `prototype` + MCP `get_skill(weline-theme-development)`，不得自造色距。
1. 先判定改动层：layout / partial / component / widget；禁止直接改 `generated/`、`view/tpl`。
2. **禁止**在 `w:*` / Taglib **标签属性**里写 `<?=`、`<?php`；动态文案用 `@lang`、Hook，或在 PHP 块赋值后再写到 **HTML 元素**属性（须 `htmlspecialchars`）。
3. **禁止**在会经 `data-wslot` 注入的 **部件模板**里写含 `<?=` 的内联 `<script>`；脚本放 `view/statics/js/widgets/{code}.js`，模板用 `@static(...)` + `defer` + `data-no-extract="true"`。
4. **禁止**在 Taglib `callback()` / `runtime_callback()` 返回的 HTML 里写裸 `@static(...)`（不会二次编译，浏览器会 404 `.../@static(Module::css/foo.css)`）；须用 `Template::fetchTagSource(DataInterface::dir_type_STATICS, ...)`，见 [如何自定义Tag.md](../../Taglib/doc/如何自定义Tag.md) §静态资源。
5. **禁止**在布局 slot 的 `<else/>` 写业务/demo 占位 UI；空 slot + 部件 `default_injections` 负责开箱内容。
6. **硬规则（布局内嵌归属）**：Theme `layouts/` / `partials/` 仅允许归属 `Weline_Theme` 的 `<w:widget>` / `fetch(...Weline_Theme::.../widgets/...)`；其他模块必须空 slot + `default_injections`。改后跑 `php bin/w frontend:check-theme-layout-widgets`。
7. **必须**为前台字面 `<section>` 与 `w:slot wrapper="section"` 配置非空语义 section 身份（属性名 `weline-code`；部件根节点用 `WidgetUiScope`）；改模板后跑 `php bin/w frontend:check-section-code`。
8. 视觉值优先主题 CSS 变量；浏览器业务请求走 `Weline.Api.*`。
9. **内容区宽度（高压线·统一版心，`frontend_unified_content_container`）**：必须遵守 `theme-layout-content-width.md`——**禁止自写一套页面/模块容器**。已包 `.w-container` 的页面只能 `width:100%` + `padding-inline:0`（禁止再写 `max-width`/`padding-inline`）；未包容器的 checkout/cart 等独立壳须用 `--weline-layout-content-max-width` 与 `--weline-layout-content-padding-inline`（或 `.w-theme-content-width`），禁止 `1440px`/`1200px` fallback 与双重 gutter。特质 Hero/CTA 色可在局部 scope 自定义，宽度无例外。
10. **响应式**：设计阶段纳入平板（≈768）与 PC（≥1024），兼顾 375；验收收集多断点证据。
11. **Taglib / i18n**：写 HTML 控件前读 [场景映射表.md](../../Taglib/doc/场景映射表.md)；前台文案用 `<lang>`/`@lang()`，禁止 HTML 内 `<?= __() ?>`。**`@lang()`/`@lang{}` 源文含逗号须加引号或改用 `<lang>`**（未加引号的逗号会被当成参数分隔，编译成 `<?=__('a', .b)?>` 触发 ParseError）。
12. 专项细则按任务再读：`部件开发指南.md`、`frontend-section-weline-code.md`、`theme-css-variables-only.md`、`theme-layout-content-width.md`。

### 7. 收口

- **规划 + TDD（硬门槛，`plan_then_tdd_required`）**：先 `submit_task_plan`（含 ≥1 `unit`）；红→绿→实际跑测 PASS evidence 才算完。
- **自行验证（硬门槛，`agent_self_verify_before_done`）**：实现后须亲自跑 UT/RT/WB（按表面）；acceptance 无 evidence 不得标 passed，亦不得宣称完成。
- **计划 / todo 诚实收口（硬门槛，`plan_todo_evidence_closeout`）**：多 todo 计划不得在未逐项举证时宣称「已完成 / done / 主链路完成」。每个 todo 须有可复核证据（代码路径、DB 行数/表状态、命令输出、Browser）。部分完成必须明确报告「部分完成」并附**未完成清单**；同步写入归属模块 `doc/开发日志.md`（禁止把 Cursor todo 无证据标为 completed）。虚报完成属硬违规。
- **汇审（硬门槛，`closeout_requires_huishen`）**：收口前写 `huishen_notes` 并在用户汇报含「汇审」小节；缺则不得宣称完成。
- **Browser 自测（硬门槛，含 Web 时）**：按约定用例用**当前宿主可用的真实 Browser**跑完操作员路径；**每次打开/导航前禁用 HTTP 缓存**（`browser_cache_disabled_on_open`）；未跑或宿主无 Browser 只能报「代码已改，WebUI 验收未完成」，禁止宣称完成。见 [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)。
- **交付后关闭 Browser（硬门槛，`browser_release_after_delivery`）**：面向用户写出「交付地址」小节之后，**立即关闭**本回合打开的全部验收 Browser 标签/webview（Cursor：`unlock` 后 `browser_tabs` close；其它宿主结束操作员会话）。禁止留下空转 Renderer。仅当用户明确要求保留时可例外并注明。从未打开过 Browser 记 `N/A`。
- **文档对齐（硬门槛）**：打开归属模块 `doc/README.md`、`doc/需求.md`、`doc/开发日志.md` 及本次触及的专题文档，对照刚交付行为；有差异则改文档或回改代码，二者必须一致。
- **交付地址清单（硬门槛）**：在面向用户的交付汇报**末尾**列出本功能涉及的全部入口，按表面分组：
  - **前台 / 后台主验收**：每行一条**可直接打开的 http(s) Markdown 链接**，格式 `[名称](http(s)://完整URL)`；链接文字用页面名（如「愿望清单」），**禁止**把 `command:simpleBrowser.api.open` 等宿主私有伪协议当作**唯一/主链**；禁止仅写不可点的「打开」变色字。
  - **本机默认 Host（硬）**：`{project_hash}.test.weline.com`（例：`http://p05113ef3.test.weline.com:9555/...`）。**禁止**把 `*.weline.test`（例：`p05113ef3.weline.test`）当作主验收 Host（即使 `/etc/hosts` 也解析）；仅当不存在可用的 `*.test.weline.com` 时才用 `127.0.0.1`。本机常为 http，勿伪造 https。权威：`WebUI浏览器验收与交付地址门禁.md`；MCP：`feature_delivery_urls.default_local_host` / `forbidden_primary_hosts`、`closeout_delivery_reminder`。
  - **API / Query**：`w_query` 资源名、路由或 `php bin/w http:request` 可复现示例。
  - **纯逻辑**：对应表面写 `N/A`，并给出 CLI 命令或接口入口。
  - 禁止臆造路由；交付前须探活；探活失败不得交付可点击死链。
  - 写完本小节后执行 **交付后关闭 Browser**（上条），再结束收口。
- 同步 `doc/开发日志.md`：门禁表、阶段变化、证据路径（含响应式断点证据路径与 URL 清单）。
- 需求变更写入 `需求.md`（需用户确认）。
- commit / push / 部署仅在有明确授权时执行。

## MCP 工具映射

| 阶段 | MCP 工具 |
|------|----------|
| 0 | `prepare_project` |
| 1–2 | `resolve_task_context`、`search_project_knowledge`、`get_indexed_document` |
| 3 | `submit_task_plan`、`get_task_plan`、`update_task_plan_progress`、`review_task_plan`（无计划则 PLAN_REQUIRED + plan_workflow；收口须 closeout_allowed） |
| 3–4 | `get_edit_bundle`、`apply_compact_edit` |
| 临时决定 | `set_session_directives` |
| 部署计划（只读） | `resolve_deploy_plan` |
| MCP 确认不可用 | 记录 `HOST_MCP_NOT_ATTACHED` / `MCP_TARGET_UNAVAILABLE`，按“0. 引导与 ready”的受限原生回退执行 |

`resolve_task_context` 与 `get_edit_bundle` 返回的 `workflow_contract.v1` 为本流程的**机器可读摘要**；`pinned_fragments` 为固定附带的规范切片。

## 文档索引

快速查找权威文档见 [文档索引](./文档索引.md)。

## 与外部 Vibe Coding 资料的关系

社区 [vibe-coding-cn](https://github.com/tradecatlabs/vibe-coding-cn) 强调：人负责目标与验收，AI 负责执行与证据，机器门禁拦截幻觉。本文将其**收敛为 Weline 仓库的可执行门禁**，不以社区文档替代本仓 `doc/` 权威正文。
