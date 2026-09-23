# channel: kickoff-clarify

## msg-1 | 2026-09-22T06:44:20Z | from:项目经理 | to:* | thread:kickoff-clarify | kind:handoff
agent_id: parent
body:
立项波任务（并行）：支付方式「选择可减多少钱」激励折扣 + 优惠明细（含优惠券）透传支付商（PayPal 等）。

用户原话要点：
1. 选择某支付方式可减免金额，引导客户选用该方式。
2. 该折扣必须传到对应支付商（例 PayPal 可传优惠信息）。
3. 除支付方式优惠外，优惠券等优惠也要能传给支付商。
4. 团队审视后由支付开发工程师开发。

仓内线索（仅索引，勿当结论）：
- `Payment/doc/需求.md` §24：支付方式活动折扣叠加顺序矩阵未定界。
- `PaymentCheckoutSessionPersistenceService` 已有 `discount_lines`。
- PayPal `PayPalApiClient::createOrder` 当前仅 `amount.value`，未见 breakdown/discount。
- Provider `supported_discount_actions` 与「支付方式本身激励折扣」可能不是同一概念。

交付路径根：`app/code/Weline/Payment/doc/开发/`
- SESSION：`session/payment-method-incentive-discount.md`（专席禁止改 SESSION）
- team：`team/payment-method-incentive-discount/`
- 规格目标：`spec/payment-method-incentive-discount.md`

各席回报须含：`result` / `notify_pm: true` / `@项目经理：本席已交付/上报，请检查并更新 SESSION` / `paths_changed`。
---

## msg-2 | 2026-09-22T06:48:00Z | from:需求分析 | to:项目经理,电商顾问 | thread:kickoff-clarify | kind:reply
agent_id: req-clarify-payment-method-incentive-discount
body:
本席已落规格草稿（EARS/UC + 框架映射），但同波电商顾问尚未落盘。

- result: waiting_peer
- peer_to: 电商顾问
- notify_pm: true
- @项目经理：本席已交付/上报，请检查并更新 SESSION
- align_freeze_eligible: false（顾问约束 pending；UC-1 电商主路径意图不可冻）
- 规格：`../../spec/payment-method-incentive-discount.md`（文首 `顾问约束: pending`）
- 纪要：`../meetings/需求分析.md`
- 要点：复用 discount_lines / checkout_session 快照 / ledger discount / Provider capabilities；区分 supported_discount_actions ≠ method incentive；§24 叠加矩阵仅草拟未冻。
- 请电商顾问落盘后 @需求分析 或 channel 追加，本席再合并顾问约束并重报。
paths_changed:
- app/code/Weline/Payment/doc/开发/spec/payment-method-incentive-discount.md
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/需求分析.md
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/kickoff-clarify.md
---

## msg-2 | 2026-09-22T06:46:18Z | from:领域探查 | to:项目经理 | thread:kickoff-clarify | kind:reply
agent_id: domain-explore
body:
result=closed；notify_pm:true。

@项目经理：本席已交付/上报，请检查并更新 SESSION（clarify-explore → closed；本席禁改 SESSION）。

探查摘要：
1. `discount_lines`/amount snapshot 已存在，仅回站展示；未进 PayPal/Stripe 请求。
2. `supported_discount_actions` = 营销 Action×支付方式兼容，≠「选此可减」激励；勿混用。
3. PayPal createOrder/patchOrder 仅 `amount.value`；Stripe 单行合计 line_item；无 breakdown/discounts 透传。
4. 后台无激励/surcharge 配置；仅 COD `config.fee` 加价；assets 为积分信用折扣角色。
5. Ledger 需求有 `discount`/`surcharge` 类型，代码仅 payment/refund/adjustment；asset enrich Event 不可当激励折扣。
6. §5.2 surcharge（加价）与激励（减免）须分字段；§24 叠加矩阵仍待定界。
7. 列表 UI/payload 无「选此可减」位。

建议扩展点：Event enrich 列表价 / QueryProvider 字段 / IncentiveQuote SPI / Provider capability `amount_breakdown` / SystemConfig method incentive_*。

纪要：`meetings/领域探查.md`

paths_changed:
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/领域探查.md
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/kickoff-clarify.md
---

## msg-2 | 2026-09-22T06:50:00Z | from:电商顾问 | to:项目经理 | thread:kickoff-clarify | kind:escalate
agent_id: ecommerce-advisor
body:
result=escalate · notify_pm:true

@项目经理：请立刻组队解决。领域决策已定稿（stance=附条件同意推进）。

要开发什么：
1) 支付方式激励折扣（选某方式减固定额/百分比）
2) 优惠明细（券+支付方式激励等）透传支付商；首期 PayPal breakdown 金额恒等

成功标准：客户感知「选 PayPal 减 X」且摘要分列；站内应付=网关金额；对账可回放 discount_lines。

叠加建议：购物车/满减 → 券 → 运费优惠 → 支付方式激励（最后一档，税前默认）；积分/W币首期默认不与激励双重大额叠加。

首期：PayPal+fake 对照；配置模型可挂任意 method 但默认未配=0。后续按 capability 逐个开。

supported_countries：Shipping `data/default-markets/countries.tsv` 默认可售市场种子（热门含 CN/HK/MO/TW/US/CA/GB/DE/JP/AU/FR…）；政策深挖 CN+US+GB+EU 热门+PayPal 无 surcharge 条款。

条件：禁止 surcharge 主叙事；展示减额可兑现；叠加与退款快照进规格；可见串含翻译工程师。

suggested_seats：支付开发工程师（必含）、需求分析、架构师、前端/原型/UI/主题、翻译工程师、测试；电商顾问对齐冻结再表态。

纪要：`meetings/电商顾问.md`

@项目经理：本席已交付/上报，请检查并更新 SESSION。
paths_changed：meetings/电商顾问.md；channel/kickoff-clarify.md（本条）
---

## msg-3 | 2026-09-22T06:52:00Z | from:需求分析 | to:项目经理 | thread:kickoff-clarify | kind:handoff
agent_id: req-clarify-payment-method-incentive-discount
body:
电商顾问已落盘，本席已合并「顾问约束」并升规格 status=clarified。

- result: delivered
- notify_pm: true
- @项目经理：本席已交付/上报，请检查并更新 SESSION
- align_freeze_eligible: true
- 规格：`../../spec/payment-method-incentive-discount.md`
- 纪要：`../meetings/需求分析.md`
- 要点：fe_be_scope=both（列表减X+透传一体）；叠加矩阵用顾问默认表；首期 PayPal+fake；禁 surcharge 主路径；UC-1…4 可进对齐冻结；§10 仍有部分退/PayPal映射粒度/ledger/payload命名待冻。
- suggested_seats_for_align_freeze：支付开发工程师、架构师、前端/原型/UI/主题开发工程师、翻译工程师、测试、电商顾问（表态）
paths_changed:
- app/code/Weline/Payment/doc/开发/spec/payment-method-incentive-discount.md
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/需求分析.md
- app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/kickoff-clarify.md
---
