# Team:前端: 对齐冻结 — payment-method-incentive-discount

| 项 | 值 |
|---|---|
| seat | Team:前端: |
| slug | payment-method-incentive-discount |
| 日期 | 2026-09-22 |
| 通道 | `channel/align-freeze.md` |
| 模式 | **对齐冻结 · 不写生产码** |
| fe_be_scope | both（列表「减 X」+ 摘要分列 + 切换重算；与壳侧计价一体） |
| result | delivered |
| notify_pm | true |
| stance | **同意冻结**（附条件：contracts 不得弱化下列数据契约；禁平行 REST） |

---

## 0. 已读权威

| 输入 | 路径 | 结论摘取 |
|---|---|---|
| 规格 | `../../spec/payment-method-incentive-discount.md` | fe_be_scope=both；顾问约束 merged；§7.4 列表 payload 命名待冻；US-1 列表可见可兑现「减 X」+ 切换重算 |
| 顾问 design_brief | `../meetings/电商顾问.md` §design_brief | 一行一方式右侧「减 ¥X / 减 X%（约 ¥Y）」；摘要「支付方式优惠」与券分列；切换即时重算无先高后低；不可用禁可减误导；跟主题 Token |
| align-freeze | `../channel/align-freeze.md` msg-1 | 待冻 open_questions 含「列表 payload 字段正式命名」 |
| 探查 | `../meetings/领域探查.md` §2.8 | `paymentMethodPayload` / `renderPaymentMethodOptions` **今日无**激励字段；须先冻契约 |

仓内现状（只读实证，非施工）：

- 列表真相：`CheckoutQueryProvider::getData` → `payment_methods` + SSR `payment_methods_html`（`CheckoutHtmlRenderer::renderPaymentMethodOptions`）。
- 归一化：`CheckoutPaymentMethodsProvider` 已透出 `cod_fee_amount_minor`（**加价**对照）；**无** incentive。
- FE 切换：`paymentBox` `change` → `renderTotals()`（本地读 method 字段，类 COD）；完整 `loadCheckout`/`api.getData` 在券/地址等变更时刷新。
- 摘要：单行 `data-checkout-discount-row`（券等）+ `data-checkout-cod-fee-row`；**无**支付方式优惠独立行。
- P2E-003：支付选项 DOM **禁止** JS `createElement` 拼装；只注入服务端 HTML。

---

## 1. stance

**同意冻结。**

本席钉死结账支付方式列表「减 X」**展示数据契约**与**切换重算交互**；施工不得另开平行 REST / 平行计价 UI 源。contracts 若弱化「可兑现恒等」「分列摘要」「禁 surcharge 叙事」「禁平行 API」任一条 → 本席改 **否决**。

---

## 2. 数据契约冻结（BinQuery / 既有 checkout API）

### 2.1 禁止项（硬）

| 禁止 | 理由 |
|---|---|
| 新建 `/rest/.../incentive` 或平行 Checkout Controller 专供「减 X」 | 规格非目标 + 探查：复用 session/`discount_lines`/QueryProvider |
| 前端用 float 自算激励额、或硬编码「最高减」文案 | 顾问：「减 X」= 可兑现最终扣减；Money 走 `amount_minor` |
| 信任磁盘缓存跳过带 `payment_method` 的重算结果 | 探查建议；金额不一致 = 硬失败 |
| 复用 `supported_discount_actions` / `surcharge` 表达激励 | 概念冲突 C1/C2 |

### 2.2 列表项字段（正式命名 · 关闭规格 §10.4）

扩展**既有** `payment_methods[]` 元素（经 `PaymentQueryProvider::paymentMethodPayload` → `CheckoutPaymentMethodsProvider` 归一化 → `getData.payment_methods`），**不**另造 endpoint。

| 字段 | 类型 | 语义（冻结） |
|---|---|---|
| `incentive_savings_minor` | `int` ≥ 0 | 若选中该方式，相对「无该激励」的**可兑现**扣减额（正数表示省多少）；未配/不可用 = `0` |
| `incentive_type` | `string` | `none` \| `fixed_amount` \| `percentage` |
| `incentive_percent` | `number\|null` | 仅 `percentage` 时有值；展示「约 ¥Y」的 Y 仍以 `incentive_savings_minor` 为准 |
| `incentive_display` | `string` | **服务端已 i18n** 的短文案（例：「减 ¥5」「减 5%（约 ¥12）」「可选减 ¥5」）；`savings_minor=0` 时为空串 |
| `incentive_available` | `bool` | 配置有效且 method `enabled`；`false` 时不得渲染可点击「可减」徽章 |

**命名裁定**：不用顶层模糊名 `incentive_discount`；不用与 `discount_lines.amount_minor`（负向）同符号的裸字段。列表用 **正数 `incentive_savings_minor`** + **`incentive_display`**，避免 FE 猜符号。

对称参考：既有 `cod_fee_amount_minor`（加价）；激励为减免，**分字段、分摘要行**，禁止塞进 `cod_fee_*` 或 `surcharge`。

### 2.3 SSR HTML 契约（与 JSON 同真）

`payment_methods_html`（`CheckoutHtmlRenderer::renderPaymentMethodOptions`）须在服务端输出激励展示位，使：

- 首屏 / adopt / continue-pay 注入后**无需** JS 拼徽章 DOM；
- 建议结构（施工细节交主题，数据位冻结）：在既有 `weline-checkout__payment-title-row` **右侧**增加激励节点，例如：
  - `span.weline-checkout__payment-incentive` + `data-payment-incentive`
  - `data-incentive-savings-minor="{n}"`
  - 文案节点内容 = `incentive_display`（已转义）
- `incentive_available=false` 或 `savings_minor=0`：不输出可减徽章（不可用 method 可另用既有 disabled 样式，**禁止**灰态仍写「可减」）。

JSON `payment_methods[]` 与 HTML 徽章金额/文案必须同源同值。

### 2.4 摘要 / totals 契约（分列）

在既有 `getData` 响应上扩展（仍走 Checkout BinQuery worker，**不**新 REST）：

| 字段 | 位置建议 | 语义 |
|---|---|---|
| `discount_lines` | `cart` 或与 amount snapshot 对齐的顶层/totals | 多行；含 `source_type=coupon` 与 `source_type=payment_method_incentive`；`amount_minor` **负向**（与 `PaymentCheckoutSessionPersistenceService` 约定一致）；激励行带 `method_code` |
| `payment_method_incentive_amount_minor` | totals / cart 便捷字段 | 当前**已选**方式激励扣减的绝对值（≥0），便于 FE 驱动独立行；无激励 = 0 |
| `grand_total` / `grand_total_minor` | 既有 | 已含激励后的最终应付；与网关恒等责任在壳/支付席，FE 只展示服务端值 |

**摘要 UI 行为冻结**：

1. **优惠券**等业务折扣：继续走既有 `data-checkout-discount-row` / marketing `checkout-summary-discount` slot（可按 `discount_lines` 聚合券行）。
2. **支付方式优惠**：新增独立行（建议 `data-checkout-payment-incentive-row`，对标 `data-checkout-cod-fee-row`），文案 source「支付方式优惠」（翻译工程师全语种）；金额展示为「−¥X」。
3. **禁止**把券与激励合并成单一模糊「优惠」行（顾问硬约束）。
4. 未选激励 / `payment_method_incentive_amount_minor=0`：激励行 `hidden`，且不得残留上一方式金额。

### 2.5 切换重算交互（冻结）

| 步骤 | 行为 |
|---|---|
| 1. 用户切换 `input[name=payment_method]` | **即时** `renderTotals()`：读新选 method 的 `incentive_savings_minor` + 激励行显隐 + 重算应付展示；**禁止**整页刷新；**禁止**先显示无激励高价再闪到低价（可先用列表预计算值，避免空白跳动） |
| 2. 服务端核对 | 切换后触发既有 `api.getData({ payment_method })`（与续付/worker 已声明参数对齐），用返回的 `grand_total*`、`discount_lines`、`payment_methods*` 覆盖本地展示；失败则 toast/可观察错误，**不得**静默用不一致金额去 submit |
| 3. 列表未选中项 | 仍展示各自 `incentive_display`（「可选减」），切换不清除其它行徽章 |
| 4. 激励变化后的支付 | submit / intent 必须带当前选中 method；旧 attempt 金额不得用于新 method（壳侧；FE 不得缓存过期 quote 强提） |
| 5. 券/地址/运费变更 | 走既有 `loadCheckout`；刷新后各 method 的 `incentive_*` 全量替换 |

与 COD 同构：**列表预计算字段供即时 UI**；**getData 为金额真相**。激励比 COD 更依赖叠加矩阵，故步骤 2 为硬门禁（非可选）。

### 2.6 文案与 a11y（FE 边界）

- 可见串中文 source；模块 zh+en CSV；其它默认站 locale → 翻译工程师（本席不截断语种）。
- 语气：奖励感「选此再减」；禁止「用卡多付」等贬损。
- 徽章/摘要行须可被读屏感知（与 title-row 关联或 `aria-describedby`；具体交 UI/主题，本席要求不得仅靠颜色表达「可减」）。

---

## 3. 主题 Token / 既有组件依赖（不发明私有样式体系）

| 依赖 | 用法 | 禁止 |
|---|---|---|
| 结账页既有 BEM：`weline-checkout__option`、`weline-checkout__payment-*`、`weline-checkout__totals` | 激励徽章与摘要行挂接现有结构 | 新建 `pm-incentive-*` 平行 CSS 命名空间 |
| Theme CSS 变量：`--color-text-*`、`--color-primary`、`--checkout-*`（`index.phtml` 已映射） | 激励强调色/字重仅用 Token | 字面色散落、私有 palette |
| 既有 radio `label.weline-checkout__option--payment` | 选中态沿用；选中可加既有 selected 类若主题已有 | 自造 card 体系 / 新组件库 |
| `w:hook` / `w:slot`（payment-methods-before/after、summary-discount） | 仅旁路扩展；主路径激励进 HTML renderer + totals | 用 slot 再塞一套平行支付列表 |
| Taglib / 货币格式 | 金额展示跟结账既有 `format`/Money 辅助 | FE 手写币种符号表 |

视觉定色板 / 字阶 → **Team:主题开发工程师:** / UI / 原型；本席只冻结「跟 Token + 既有 weline-checkout / w-* 族，不发明私有样式体系」。

---

## 4. 与他席接口（deps 意向 · 供架构写入 deps.md）

| 依赖席 | 本席需要 |
|---|---|
| 架构师 / 扩展点 / 支付开发工程师 | enrich 列表字段写入方；`discount_lines` 生产者；getData 透出；禁平行 REST |
| 主题 / UI / 原型 | title-row 右侧徽章布局、摘要新行样式、选中态；Token 落地 |
| 翻译工程师 | `incentive_display` 模板串、「支付方式优惠」、不可用提示等全语种 |
| 测试 | UC-1/UC-2：列表见减 → 选中摘要分列 → 切换无脏激励；Browser 断言 DOM `data-incentive-savings-minor` 与应付 |

本席 **不**拥有：激励报价算法、PayPal breakdown、退款快照（支付席）；SystemConfig 后台表单（支付/配置）。

---

## 5. 开放点（本席裁定 / 移交）

| # | 项 | 本席裁定 |
|---|---|---|
| payload 命名 | `incentive_savings_minor` + `incentive_display` + … | **已冻**（见 §2.2） |
| 摘要分列 DOM | 独立 `data-checkout-payment-incentive-row` | **已冻** |
| 切换是否必须 getData | 即时本地 + 异步 getData 核对 | **已冻** |
| 部分退 / PayPal 多行 / Ledger | — | **非 FE**；金额守恒硬即可 |

---

## 6. 收口

- stance = **同意冻结**
- result = `delivered`
- notify_pm = `true`
- 未写生产 PHP/JS/CSS；专席禁改 SESSION

**@项目经理：本席已交付/上报，请检查并更新 SESSION。**
