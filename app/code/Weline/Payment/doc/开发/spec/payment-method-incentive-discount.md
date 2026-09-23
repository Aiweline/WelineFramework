# 规格：支付方式激励折扣 + 优惠明细透传支付商

---
status: clarified
work_kind: feature
feature_slug: payment-method-incentive-discount
module: Weline_Payment
updated: 2026-09-22
session_path: ../session/payment-method-incentive-discount.md
fe_be_scope: both
align_freeze_eligible: true
顾问约束: merged
advisor_meeting: ../team/payment-method-incentive-discount/meetings/电商顾问.md
explore_meeting: ../team/payment-method-incentive-discount/meetings/领域探查.md
---

## 0. 范围声明

| 项 | 值 |
|---|---|
| work_kind | `feature`（增强结账/支付金额语义 + Provider 透传） |
| fe_be_scope | **前后端都有（MVP 一体，不可砍半）**：结账列表/摘要展示「减 X」+ 后台激励配置 + 壳侧计价/session 快照 + Provider（首期 PayPal）透传。顾问明确：展示与透传缺一不可。 |
| 归属模块 | `Weline_Payment`（壳拥有激励编排与快照；Provider 映射网关字段；Payable/Checkout/Marketing 提供券等业务折扣行） |
| align_freeze | **可进入对齐冻结讨论**：顾问已附条件同意；冻结会须把本节「顾问约束」写入 contracts，不得偷改意图 |

### 顾问约束（merged · 来源 `meetings/电商顾问.md`）

对齐冻结前必须写入 contracts，施工不得违背：

1. **只做「激励折扣」叙事**；禁止「选卡/选某方式加价」式 surcharge 作为本 feature 主路径（尤其 GB/EU 附加费限制；PayPal 协议禁对 PayPal 单独 surcharge）。激励与 surcharge **反向分字段**（领域探查同结论）。
2. **结账展示的「减 X」= 最终应付扣减额**，与网关/Provider 实收及 breakdown **金额恒等**；禁止「最高减」等不可兑现文案。
3. **首期必须定界叠加矩阵**（见 §6）；默认税前；退款按原分摊快照反向。
4. **首期支付方式范围**：PayPal 激励配置 + 透传；本机 `fake_card`（或等价测试方式）对照验收；配置模型可支持任意 method，但**默认未配=0**；其它 Provider 仅在 capability 声明后配置启用，禁止默认全开。
5. **可见文案**（结账「减多少」、摘要行、若触及 FAQ/政策）须走翻译工程师默认站全语种闭环，禁止只改中英 CSV。
6. **两块一体**：A 支付方式激励折扣 + B 优惠明细透传支付商；缺一不可。
7. **合规红线**：禁止虚假划线/隐瞒实质 surcharge；站内最终应付 ≠ 网关金额为硬失败；误导性折扣文案禁止。

顾问 stance：**附条件同意推进**（非否决）。

---

## 1. 目标 / 非目标

### 目标

1. 运营可按支付方式配置「选用该方式可减免」的激励折扣（固定额或百分比；可开关/有效期/funding source）。
2. 结账支付方式列表展示「选此减 ¥X / 减 X%（约 ¥Y）」；未选中时仍可见「可选减」提示；选中后订单摘要分列「支付方式优惠」与「优惠券」等，即时重算应付。
3. 激励与优惠券等业务优惠一并进入统一 `discount_lines` 快照，并在 Provider 能力允许时透传（首期 PayPal `amount.breakdown.discount` 等，金额守恒）。
4. 切换支付方式重算；intent/attempt 使用新快照；退款按快照反向。
5. 审视/对齐冻结后由 `Team:支付开发工程师:` 施工（含真浏览器支付通路）。

### 非目标

- 新建平行计价引擎或绕过 Payable / checkout_session 的第二套金额真相源。
- 本 feature 主路径上的支付方式 surcharge /「选其它方式加价」叙事。
- 信用/积分/W币 与激励默认「双重大额减」（默认关；须显式运营开关才可叠加讨论）。
- Provider 商户后台促销中心/补贴谈判对接（非壳侧 `discount_lines` 透传）。
- 首期默认全开所有支付方式激励；购物车/PDP 过度承诺（可选后续）。
- 另开平行税务引擎（跟随现有税务模块口径）。

---

## 2. 角色

| 角色 | 诉求 |
|---|---|
| 买家 | 列表可见可选减免；选中后应付与「减多少」一致；支付商侧可见减项（能力允许时）。 |
| 运营 | 按 method 配置激励；默认不全开；可停用；历史单保留快照。 |
| 支付商 | 收到与站内一致的应付 + 可审计优惠明细。 |
| 财务/审计 | funding source + discount 行可回放；退款反向正确。 |

---

## 3. 澄清记录

| # | 问题 | 答案 | 来源 |
|---|---|---|---|
| Q1 | 是否允许「选支付方式减钱」？ | 附条件同意；禁 surcharge 主路径与虚假划线 | 电商顾问 |
| Q2 | 首期是否含结账 UI？ | **必须含**列表「减 X」+ 摘要分列 | 电商顾问 |
| Q3 | 叠加顺序？ | §6 顾问默认矩阵；对齐冻结写入 contracts | 电商顾问 + §24 |
| Q4 | 不支持 breakdown 的 Provider？ | 首期主验 PayPal；其它仅 capability 后启用；不支持则不得伪造字段，但仍须壳侧金额正确 | 顾问 + 本席 |
| Q5 | `supported_discount_actions` = method incentive？ | **否**；须拆开命名（探查：严重混淆风险） | 领域探查 + README |
| Q6 | 税前税后？ | 首期默认税前折扣；含税价站跟现有税务口径 | 电商顾问 |
| Q7 | 首期 method 范围？ | PayPal + fake 对照；其它默认未配 | 电商顾问 |

---

## 4. 用户故事与 EARS

### US-1 选用激励（含展示）

> As a 买家, I want 在支付方式列表看到并获得可兑现的减免, so that 我信任地选择商家引导的支付方式。

**EARS**

1. WHEN 某支付方式已发布激励配置且当前可用 THEN 系统 SHALL 在支付方式列表展示可兑现的「减 ¥X」或「减 X%（约 ¥Y）」（未选中亦可见「可选减」提示）。
2. WHEN 买家选中该方式 THEN 系统 SHALL 将激励计入应付快照，并在订单摘要以独立行展示「支付方式优惠」，与优惠券行分列。
3. WHEN 买家切换到无激励或其它激励方式 THEN 系统 SHALL 同步重算列表提示、摘要行与应付总额，不得残留上一方式激励行。
4. IF 激励为固定额 THEN 系统 SHALL 减免不超过配置额与可激励基数/上限的较小者，且应付不得为负。
5. IF 激励为百分比 THEN 系统 SHALL 按约定基数计算，应用上限（若配），舍入遵循 Money `amount_minor`。
6. IF 支付方式本身不可用 THEN 系统 SHALL NOT 展示可点击的「可减」误导；可标注不可用。
7. WHILE 展示「减 X」THEN 该值 SHALL 等于选中后最终应付相对无该激励时的真实扣减额（禁止最高减话术）。

### US-2 透传支付商

> As a 商户, I want 优惠券与支付方式激励明细传到支付商, so that 网关订单与站内对账一致。

**EARS**

1. WHEN 使用首期支持透传的 Provider（PayPal）创建支付 THEN 系统 SHALL 将 `discount_lines`（含券与激励等分列）映射为网关 breakdown/discount，且 `amount.value`（或等价应付）= 站内最终应付（币种与精度一致）。
2. WHEN 同一订单存在优惠券行与支付方式激励行 THEN 系统 SHALL 在透传/快照中保持可区分来源（禁止合并成无法审计的单行模糊「优惠」）。
3. IF Provider 未声明优惠明细透传能力 THEN 系统 SHALL NOT 伪造 net 不支持的 breakdown；SHALL 仍保证壳侧扣减后总额正确；该 method 的激励透传验收不作为首期必过项除非已声明能力。
4. IF 切换支付方式导致激励变化 THEN 后续 intent/attempt SHALL 使用新快照；禁止旧金额调用新 method。

### US-3 配置、发布与范围

> As a 运营, I want 按 method 配置激励并默认不全开, so that 激励可运营且合规可控。

**EARS**

1. WHEN 激励配置已保存未发布/未在有效期内 THEN 新 checkout session / 新 intent SHALL 不应用该激励。
2. IF method 未配置激励 THEN 系统 SHALL 视同减免 0。
3. WHEN 运营停用激励 THEN 新单不再享有；历史成功支付 SHALL 保留当时快照。
4. IF funding source 已配置 THEN 快照 SHALL 记录商家/Provider/平台/共同之一，供退款与对账。

### US-4 退款

> As a 买家/财务, I want 退款按支付成功时的折扣快照处理, so that 不吞折扣、不重复退。

**EARS**

1. WHEN 全额退款 THEN 系统 SHALL 按快照回退激励折扣记账（与券分列）。
2. WHEN 部分退款 THEN 系统 SHALL 按对齐冻结选定的规则回退（顾问默认：按金额比例）；券与激励分列。
3. IF 退款说明展示 THEN SHALL NOT 承诺「退回客户未实付的折扣现金」等不准确话术。

---

## 5. 配置维度（MVP）

| 维度 | 要求 |
|---|---|
| Scope | SystemConfig Website→Store 链；禁止 Payment 自造隐式来源 |
| 绑定 | `method_code` |
| 形态 | `fixed_amount` \| `percentage`（二选一或按配置）；币种跟结账币 |
| 上限 | 建议支持封顶，防异常 |
| 生命周期 | 开关、起止时间、发布语义 |
| Funding source | 商家 / Provider / 平台 / 共同 |
| 默认 | 未配 = 0；禁止默认全开所有 method |
| 文案 | 中文 source；模块 zh+en CSV；其它已选 locale → 词典/翻译工程师 |

---

## 6. 叠加、税、重算（顾问默认矩阵 · 对齐冻结须确认写入 contracts）

| 顺序 | 优惠类型 | 规则 |
|------|----------|------|
| 1 | 购物车/商品级促销、满减 | 先算 |
| 2 | 优惠券 | 可券基价；冲突以券模块既有规则为准 |
| 3 | 运费优惠/包邮 | 仅运费行；不与商品折扣混同一行；PayPal 侧 `shipping_discount` vs `discount` 分字段 |
| 4 | **支付方式激励** | **现金应付前最后一档**；基数 = 已含券/满减后的应付（不含不可用资产抵扣）；同一订单仅一种 method 激励 |
| 5 | 信用/积分/W币 | 既有支付/折扣互斥；**默认不与激励混推双重大额减** |

**税**：首期默认税前折扣；含税价站按现有税务模块，禁止平行税逻辑。

**切换支付方式**：重算激励、摘要、应付、透传 payload；新 attempt / 必要时新 intent。

**Intent**：激励规则版本与配置发布版本进快照；旧 intent 不被新配置改写。

> 注：关闭 `需求.md` §24「叠加矩阵未定界」对本 feature 的阻塞，以本表（经对齐冻结确认）为准；全站其它折扣类型若未列入首期，保持现状不扩大。

---

## 7. 透传字段契约与框架映射

### 7.1 复用（禁止平行计价）— 结合领域探查

| 既有抽象 | 用途 |
|---|---|
| `PaymentCheckoutSessionPersistenceService` + `discount_lines` / amount snapshot | 扩展行字段（`source`/`funding`/`method_code`），展示与 Provider 映射的壳侧真相源 |
| Payable snapshot / Checkout 写入结构化多行 | 探查缺口：当前 Checkout/Order 链路少见多行写入 → 施工须补齐写入方 |
| Allocation `ROLE_DISCOUNT` 等 | 复用角色常量；勿造平行模型 |
| Provider capabilities | 新增/使用「明细透传」类能力位（探查：现无 `amount_breakdown`）；**勿复用** `supported_discount_actions` 表达 method incentive |
| Event `freeze_quote::enrich` 范式 | 跨模块贡献 quote 的旁路参考 |
| COD fee 配置形态 | 反向参考（fee=加价；本 feature=减免） |

### 7.2 `discount_lines` 行约定

至少：`key`、`label`、`amount_minor`（与现有负向约定一致）、`source_type`（`coupon` \| `payment_method_incentive` \| `cart` \| `shipping` \| …）、可选 `funding_source`、`method_code`（激励行）。

### 7.3 Provider（首期）

| 目标 | 行为 |
|---|---|
| PayPal | `createOrder`/`patch` 使用 breakdown，使 value = 分项守恒；discount 含券+激励可审计明细或等价聚合策略（冻结会定：优先可区分） |
| fake_card | 对照：壳快照与「模拟透传/日志」可断言金额一致 |
| 其它 | 默认不启用激励；声明能力后方可配置 |

**探查现状**：PayPal/Stripe 今日仅传合计 value；Ledger 运行时类型尚不全 — 施工须补齐，规格不指定类名补丁步骤。

### 7.4 结账 payload / UI 契约意图

列表项需可观察激励字段（如 `incentive_discount` / `savings_minor` + 展示文案）；摘要分列。具体字段名对齐冻结定；QueryProvider 今日无该字段（探查）。

---

## 8. 用例

### UC-1 主路径：列表见减 → 选中 → 支付成功（金额恒等）

| 字段 | 内容 |
|---|---|
| id | UC-1 |
| 名称 | 支付方式激励折扣主路径 |
| 角色 | 买家 |
| 前置 | 默认站某 Store 已发布 PayPal（或 fake）激励；购物车可结算；可有优惠券 |
| 主成功步骤 | 1 打开结账 2 列表见目标方式「减 X」 3 选中后摘要出现「支付方式优惠」且应付下降 4 提交支付成功 5 断言站内应付=Provider 订单应付；快照含分列 discount_lines |
| 备选/异常 | A1 切换无激励方式 → 应付回升；A2 激励+券后低于最小金额 → 可用性禁用或阻断可观察 |
| 期望结果 | 展示可兑现；金额守恒；无假折扣 |
| 映射 acceptance | e2e + Browser WB-OP 真支付通路 |
| **冻结状态** | **ready_for_align_freeze** |

### UC-2 券+激励透传与切换重算

| 字段 | 内容 |
|---|---|
| id | UC-2 |
| 名称 | 优惠明细透传与切换 |
| 角色 | 买家 / 支付验收 |
| 前置 | 订单含券行；PayPal sandbox 或 fake 对照 |
| 主成功步骤 | 1 选激励方式查看分列明细 2 创建支付核对 PayPal breakdown（或 fake 断言）3 换方式重算后再付 |
| 备选/异常 | 网关金额不一致 → 不得标支付成功 |
| 期望结果 | 分列可审计；切换无脏激励 |
| 映射 acceptance | Provider contract + Browser |
| **冻结状态** | ready_for_align_freeze |

### UC-3 未配置/未发布不生效

| 字段 | 内容 |
|---|---|
| id | UC-3 |
| 名称 | 默认未配为零 |
| 角色 | 运营 + 买家 |
| 前置 | method 无激励或未发布 |
| 主成功步骤 | 结账选该方式 → 无激励减免 |
| 期望结果 | 发布语义正确 |
| 映射 acceptance | 配置对照 API/Browser |
| **冻结状态** | ready_for_align_freeze |

### UC-4 退款按快照

| 字段 | 内容 |
|---|---|
| id | UC-4 |
| 名称 | 激励折扣退款反向 |
| 角色 | 买家/客服 |
| 前置 | 含激励的成功支付 |
| 主成功步骤 | 全额或部分退 → 激励与券分列按规则回退 |
| 期望结果 | 无吞折扣、无重复退 |
| 映射 acceptance | 退款 e2e / 后台断言 |
| **冻结状态** | ready_for_align_freeze |

---

## 9. 隐形需求摘要

- SystemConfig 模板与发布语义；壳/Provider 同构（网关映射在 Extends Provider）。
- Money：`amount_minor`；禁 float 核心计算。
- 用户可见串：翻译工程师默认站全语种；模块 CSV 中英。
- 真浏览器支付通路（支付开发工程师硬闭环）。
- 合规触达国优先：CN/US/GB/EU 热门收货国等（见顾问 supported_countries）；工程按站可售市场收窄，不按全球地址库全开。
- 查询/验收默认本机。

---

## 10. 待对齐冻结确认的开放点（非阻塞顾问合并）

1. 部分退款：比例回退 vs 按行规则 — 顾问默认比例，冻结会二选一写入 contracts。
2. PayPal 透传：多行 discount 如何映射 API（单 `discount` 总额 + description vs 多 item）— 金额守恒硬，展示粒度可冻。
3. ledger 运行时类型补齐范围（探查：当前仅 payment/refund/adjustment）— 架构+支付席定方案。
4. payload 字段正式命名（`incentive_discount` vs `savings_minor` 等）。

---

## 11. MVP 范围（顾问表 · 规格采纳）

| | MVP |
|--|-----|
| 方式 | PayPal + fake 对照；模型可配任意 method，默认 0 |
| 形态 | 固定额 + 百分比 |
| 透传 | 券 + 激励 → discount_lines → PayPal breakdown |
| 展示 | 结账列表「减 X」+ 摘要分列 |
| 退款 | 按快照；默认部分退比例 |

后续：其它 Provider、国家/客群限定、PDP 轻提示、争议话术等。

---

## 12. 就绪检查

- [x] `status` ≥ `clarified`
- [x] ≥1 用户故事 + 每故事 ≥2 条 EARS
- [x] ≥1 用例（含主成功路径）
- [x] 非目标明确
- [x] feature：e2e / Browser 支付通路验收意图已点名
- [x] 未把实现补丁步骤写成唯一 how
- [x] 顾问约束已合并
- [ ] 叠加矩阵经对齐冻结写入 contracts（下一步 PM）
- [ ] `ready-for-plan`：建议对齐冻结通过后由 PM/架构升格

**结论**：澄清完成；`align_freeze_eligible=true`；建议 PM 召集对齐冻结（支付开发工程师 + 架构师 + 前端/主题 + 翻译工程师 + 测试）。
