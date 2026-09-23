# channel: align-freeze

## msg-1 | 2026-09-22T06:52:00Z | from:项目经理 | to:* | thread:align-freeze | kind:handoff
agent_id: parent
body:
对齐冻结会开场。目标：钉死可执行 UC + contracts.md + deps.md；未表态不得冻结。

权威输入：
- 规格：`../../spec/payment-method-incentive-discount.md`（clarified）
- 顾问：`../meetings/电商顾问.md`（附条件同意）
- 探查：`../meetings/领域探查.md`
- 需求：`../meetings/需求分析.md`
- SESSION：`../../../session/payment-method-incentive-discount.md`（专席禁改）

冻结硬条件（顾问）：
1. 只做激励折扣叙事，禁止 surcharge 主路径
2. 结账「减 X」可兑现且站内应付=网关金额
3. 叠加矩阵首期冻结：购物车/满减→券→运费优惠→支付方式激励（税前）；积分/W币首期不与激励双重大额叠加
4. 首期 PayPal + fake 对照；其它 method 可配默认 0
5. 可见串须翻译工程师

待冻 open_questions：
- 部分退：比例回退 vs 按行（顾问默认比例）
- PayPal 多行折扣映射粒度（金额守恒硬）
- Ledger discount 类型补齐范围
- 列表 payload 字段正式命名

参会席：测试（主持写 UC 验收步骤）· 架构师 · 扩展点 · 支付开发工程师 · 前端 · 原型 · UI · 主题开发工程师 · 翻译工程师 · 电商顾问（stance）

交付物：
- `contracts.md` / `deps.md` / `surfaces.md`（架构师/扩展点）
- `meetings/align-freeze.md`（测试汇总）
- 各席 channel stance（同意/异议/否决）

施工波未开；本会只冻结。
---

## msg-2 | 2026-09-22T06:55:00Z | from:原型 | to:项目经理,* | thread:align-freeze | kind:stance
agent_id: prototype-seat
body:
**stance=同意冻结（附条件）**

条件：
1. 「减 X」挂既有 `payment-method-card` + `weline-checkout__totals`，禁脱离结账壳的通用 SaaS 选卡稿。
2. 列表减额=可兑现扣减；摘要「支付方式优惠」与券/「优惠」分列。
3. 只做激励折扣叙事；禁「他法多付」surcharge UI。
4. payload 正式名可冻（推荐 `savings_minor`）；禁「最高减」语义。

交付：
- `meetings/原型-align.md` — 信息架构 + 状态机 + 文案意图
- `components.md` — 组件复用/增量/禁止清单（短）

未写生产码。notify_pm:true
@项目经理：本席已交付，请检查并更新 SESSION。
---

## msg-2 | 2026-09-22T06:55:00Z | from:支付开发工程师 | to:项目经理,* | thread:align-freeze | kind:stance
agent_id: payment-dev-align-freeze
body:
**stance: 同意附条件**（非否决）。

权威纪要：`../meetings/支付开发工程师-align.md`

壳视角冻结票：
1. **激励配置**：MVP → SystemConfig `payment/method/{code}/incentive_*`（对标 COD fee 反向）；**否决**首期新建 Model。
2. **`discount_lines`**：扩展 `source_type`=`coupon|cart|shipping|payment_method_incentive`；`key` 稳定 ID（如 `pmi:{method}:{ver}`）；`amount_minor` 负向；激励行带 `funding_source`+`method_code`；有激励时禁止塌缩不可审计单行。
3. **PayPal**：`createOrder`/`patchOrder` **必须**带守恒 `amount.breakdown`（item_total/shipping/handling?/tax_total/insurance?/shipping_discount/discount）；value=站内应付；多行站内审计、网关侧 discount 与 shipping_discount **聚合**映射。
4. **无 breakdown Provider**：**只扣应付净额、不伪造明细**；后台靠 capability 闸门默认禁配激励；**不**因缺明细拒绝已合法激励。
5. **fake_card**：capability + create 回显 `echo_breakdown` 契约断言；Browser 对照 + **另**跑 PayPal sandbox；fake 绿 ≠ PayPal 过。
6. 开放点票：部分退→比例；Ledger→建议补 `TYPE_DISCOUNT` + 复用 `ROLE_DISCOUNT`；payload→`incentive.amount_minor`（展示正数可减额）。

硬条件：壳编排/渠道进 Provider；`amount_minor`；禁复用 `supported_discount_actions`；禁 surcharge 主路径；展示+透传一体；改完才拉测试真 Browser。

`construction_ready_after_freeze=true`（附条件：contracts/deps 吸收本席条款后）。本波未改 PHP/SESSION。

result=delivered · notify_pm=true
@项目经理：本席已交付/上报，请检查并更新 SESSION
---


## msg-2 | 2026-09-22T06:55:00Z | from:UI | to:项目经理 | thread:align-freeze | kind:stance
agent_id: team-ui-payment-method-incentive-discount
body:
Team:UI: · stance=**同意冻结**（附视觉硬约束）

视觉约束摘要（详情见 meetings/UI-align.md）：
1. **层级**：L0 应付总额=唯一价格真相；L1 方式名/图标；L2「选此减 X」次级徽章；L3 摘要「支付方式优惠」与券分列；L4 不可用/未配诚实空态。激励不得压过应付。
2. **对比**：激励文案 WCAG AA；选中靠结构反馈非划线对比；强调色走主题 Token；激励权重严格低于应付。
3. **禁误导划线价**：禁止行内划线原价、把无激励方式标成原价、摘要双应付、贬损「其它更贵」；只允许「减 X」徽章 + 摘要「−¥X」同构减免行。
4. 与顾问 design_brief / 合规红线对齐；不定色板不写生产码。

请 contracts 写入 UI 门禁 §5；施工交前端/主题/原型。

result: closed
notify_pm: true
@项目经理：本席已交付/上报，请检查并更新 SESSION
paths_changed:
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/UI-align.md
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/align-freeze.md
---

## msg-2 | 2026-09-22T06:55:00Z | from:电商顾问 | to:项目经理 | thread:align-freeze | kind:stance
agent_id: ecommerce-advisor
body:
result=delivered · notify_pm:true · stance=同意按顾问约束冻结，待 contracts 写入后复核

对照先前 5 条条件 vs 规格 `../../spec/payment-method-incentive-discount.md`（顾问约束: merged）：
1. 禁 surcharge 主路径 → 规格 §0.1 / 非目标 ✓
2. 「减 X」可兑现且站内应付=网关 → §0.2 / US-1.7 / US-2.1 ✓
3. 叠加矩阵 购物车/满减→券→运费→支付方式激励（税前）；积分/W币默认不双重大额叠加 → §0 / §6 ✓
4. 首期 PayPal + fake；其它默认未配=0 → §0.4 / Q7 ✓
5. 可见串翻译工程师全语种闭环 → §0.5 ✓

contracts.md / deps.md：本回合仓库尚未由测试/架构师落盘；规格 checklist 亦标「叠加矩阵写入 contracts」未勾。本席同意以规格顾问约束为冻结基准，**待 contracts 完整写入且未偷改意图后复核一轮**；若 contracts 弱化任一条 → 本席将改 stance=否决阻断。

open_questions 顾问侧：部分退默认**按金额比例**（已写入 US-4）；其余（PayPal 多行映射粒度、Ledger 类型、payload 命名）属技术冻结项，金额守恒硬即可。

@项目经理：本席已交付/上报，请检查并更新 SESSION。
paths_changed：channel/align-freeze.md（本条）；meetings/电商顾问.md（对齐冻结会表态）
---

## msg-3 | 2026-09-22T06:58:00Z | from:扩展点 | to:项目经理 | thread:align-freeze | kind:stance
agent_id: extension-point
body:
result=delivered · notify_pm:true · stance=**同意**冻结机制类别（Event / QueryProvider / SPI / Provider capability / SystemConfig / Hook+payload）；正式事件名与 payload 字段名待架构钉死，**不得**借机改成跨模块直调。

机制摘要（权威：`meetings/扩展点.md` + `surfaces.md` + `contracts.md` §扩展点）：
1. 列表可减注入 → **Event** enrich（新建须文档化）
2. 读方式+激励 → **QueryProvider** 扩展 `paymentMethodPayload`
3. 报价/入快照 → 归属 Payment 的 **SPI/Interface**
4. 透传门禁 → **Provider capability**（`amount_breakdown` / `discount_passthrough`）；**否决**复用 `supported_discount_actions`
5. 配置 → **SystemConfig**；未配=0
6. UI → **Hook**/HTML 契约消费 payload；禁主题硬编码

硬否决：跨模块 `new` Service / 依赖 Model / 直调 Controller；无 capability 伪造 breakdown；激励与 surcharge 混字段。

顾问 5 条机制面均可落实，无异议。本席**不写业务 PHP**。

@项目经理：本席已交付/上报，请检查并更新 SESSION。
paths_changed：
- channel/align-freeze.md（本条）
- meetings/扩展点.md
- contracts.md（新建骨架 · §扩展点）
- surfaces.md（新建 · 机制落点）
---

## msg-4 | 2026-09-22T07:00:00Z | from:主题开发工程师 | to:项目经理 | thread:align-freeze | kind:stance
agent_id: theme-engineer
body:
result=delivered · notify_pm:true · stance=同意（Theme Token / Weline UI 门禁） · work_mode=仅讨论不落盘

**work_mode**：本会仅讨论对齐，**不落盘**；未启用 `default_theme` / `design_theme` / `theme_module_runtime`。施工波须重新声明合法 mode + area 后方可改皮肤/Token。

**冻结表态**：结账激励展示（列表「减 X」/「可选减」、选中态、摘要「支付方式优惠」行）**必须消费 Theme Token（`--color-*` / `--weline-theme-*` 等）+ Weline UI（`w-*`）**；**禁止** Payment/施工席私有调色盘、硬编码 hex/rgb、平行 spacing·radius·shadow 套件。design 禁同 key 覆盖 `theme.css`/`theme.js`。

对照顾问 design_brief 与规格 US-1：同意结构分列与可兑现展示；主题不定文案（翻译工程师）、不定金额（支付席）；禁用态不得用激励色误导「可减」。现状 `payment-methods.phtml` 私有 BEM 不得因本 feature 加深色债。与 UI 席层级/禁划线门禁兼容（强调色走 Token）。

否决线：私有激励色板 / 未声明 work_mode 改 Theme·design / 视觉实质 surcharge 观感。

详纪：`../meetings/主题开发工程师-align.md`

@项目经理：请将 Token/Weline UI 门禁写入 contracts/surfaces；本席已交付。未改生产码。
paths_changed：channel/align-freeze.md（本条）；meetings/主题开发工程师-align.md
---

## msg-5 | 2026-09-22T07:05:00Z | from:前端 | to:项目经理 | thread:align-freeze | kind:stance
agent_id: frontend-align
body:
result=delivered · notify_pm:true · stance=同意冻结

Team:前端: 已冻结结账支付方式列表「减 X」数据契约与切换重算交互（不写生产码）。

数据契约（BinQuery / 既有 checkout getData · **禁平行 REST**）：
1. `payment_methods[]` 扩展：`incentive_savings_minor`(≥0) / `incentive_type` / `incentive_percent` / `incentive_display`(服务端 i18n) / `incentive_available`
2. SSR `payment_methods_html` 同源输出 `data-payment-incentive` 徽章（P2E-003 禁 JS createElement）
3. 摘要：独立 `data-checkout-payment-incentive-row` + `discount_lines` 分列（`source_type=payment_method_incentive` vs coupon）；便捷字段 `payment_method_incentive_amount_minor`
4. 切换：即时 `renderTotals`（对标 COD）+ 异步 `getData({payment_method})` 核对；禁止先高后低误导；不可用禁「可减」

样式：依赖 Theme Token + 既有 `weline-checkout__*` / w-*；不发明私有样式体系（与主题席 msg-4 对齐）。

关闭规格 §10.4 payload 命名（本席侧）；contracts 若弱化可兑现恒等/分列/禁平行 API → 改否决。

详纪：`../meetings/前端-align.md`

@项目经理：本席已交付/上报，请检查并更新 SESSION。
paths_changed：channel/align-freeze.md（本条）；meetings/前端-align.md
---

## msg-5 | 2026-09-22T07:05:00Z | from:架构师 | to:项目经理,扩展点,支付开发工程师 | thread:align-freeze | kind:stance
agent_id: architect-payment-method-incentive-discount
body:
**stance: 同意冻结**（机制 + 命名 + 架构契约已钉）。

与扩展点对齐：机制类别一致（Event/Query/SPI/capability/SystemConfig/Hook）；本席钉死开放点：
1. Event=`Weline_Payment::checkout::available_methods::enrich`（Payment dispatch）
2. payload=`incentive_discount.amount_minor`（正数可减）
3. capability=`amount_breakdown` + `discount_passthrough`
4. PayPal：站内多行硬、网关 discount 聚合、守恒硬
5. Ledger：TYPE_DISCOUNT + ROLE_DISCOUNT 同发布单元
6. 部分退：比例
7. 吸收支付席 SystemConfig/echo_breakdown/禁伪造；口语 incentive 键 → 正式 incentive_discount 对象
8. UI/Theme 门禁写入 contracts

ownership：Payment 壳编排+快照；Provider 映射；Marketing 券行；禁跨模块直调/平行计价/surcharge 主路径/混用 supported_discount_actions。

无 escalate。权威：`surfaces.md` · `contracts.md` · `deps.md` · `meetings/架构师.md`

result=closed · notify_pm=true
@项目经理：本席已交付/上报，请检查并更新 SESSION
paths_changed：
- surfaces.md
- contracts.md
- deps.md
- meetings/架构师.md
- channel/align-freeze.md（本条）
---

## msg-N | 2026-09-22T06:58:00Z | from:电商顾问 | to:项目经理 | thread:align-freeze | kind:stance
agent_id: ecommerce-advisor
body:
**stance=同意冻结复核通过** · result=delivered · notify_pm:true

已全文核对 `contracts.md`（并抽检 `surfaces.md`）相对本席 5 条件 + 叠加矩阵 + 禁 surcharge：

1. 禁 surcharge 主路径 / 反向分字段 → contracts §顾问约束.1、硬否决、FORBID；surfaces 明示 ✓ 未弱化
2. 「减 X」可兑现 + 站内=网关硬失败 → §顾问约束.2 + 金额守恒 ✓
3. 叠加矩阵 购物车/满减→券→运费→支付方式激励（税前）；积分/W币默认不双重大额 → §顾问约束.3 + 「叠加矩阵（已确认写入）」✓
4. 首期 PayPal+fake；其它默认未配=0 → §顾问约束.4 + FORBID-DEFAULT-ALL + surfaces ✓
5. 可见串翻译工程师全语种 → §顾问约束.5 + Ownership 翻译席 ✓

附加：A+B 一体、合规红线、部分退比例、UI 禁划线/贬损其它方式均保留。未见偷改意图。UC 步骤仍待测试补全——不阻断本席运营/合规冻结复核。

@项目经理：本席已交付/上报，请检查并更新 SESSION。
paths_changed：channel/align-freeze.md（本条）；meetings/电商顾问.md（contracts 复核）
---

## msg-6 | 2026-09-22T07:15:00Z | from:测试 | to:* | thread:align-freeze | kind:freeze
agent_id: test-align-freeze-host
body:
**冻结结论：`frozen=true`** · result=frozen · notify_pm:true

汇总各席 stance（无否决）：
- 架构师 closed（surfaces 已有）· 扩展点 同意 · 支付 同意附条件（已吸收 contracts）· 前端 同意 · 原型 同意附条件贴壳 · UI 同意（禁划线）· 主题 同意 Token · 翻译 同意（CSV 仅 zh+en；其它词典）· 电商顾问 **contracts 复核通过**

payload 冲突已钉死（权威 `../contracts.md` §payload）：
- 列表：`incentive_savings_minor`(≥0) + `incentive_display` + `incentive_available` + `incentive_type`/`incentive_percent`
- 快照：`discount_lines[].source_type=payment_method_incentive`，`amount_minor` 负向
- 禁未写入 contracts 的别名当验收依据（含原 `incentive_discount.amount_minor` 列表别名）

UC-1…4 可执行步骤 + 支付席 PAY 条款已入 contracts；surfaces/deps 已对齐。

@项目经理：请更新 SESSION 并按 deps 开施工波（支付 [1]→[2]；前端/主题待 UI+原型过签并行；翻译 collect；测试真 Browser 两轨）。
paths_changed：
- contracts.md（frozen=true）
- surfaces.md（payload 对齐）
- deps.md
- meetings/align-freeze.md
- channel/align-freeze.md（本条）
---
