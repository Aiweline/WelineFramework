# 合规复审 · Team:支付开发工程师:

| 项 | 值 |
| --- | --- |
| seat | `Team:支付开发工程师:` |
| slug | `payment-method-incentive-discount` |
| 轨 | 合规复审（施工 closed 后） |
| 日期 | 2026-09-22 |
| 对照 | `contracts.md` + `payment-shell.md` + 施工纪要 |
| 模块版本 | `Weline_Payment` 1.9.102 |
| **verdict** | **pass** |

## 审查清单

| ID | 检查项 | 证据 | 结论 |
|----|--------|------|------|
| R1 | 壳未写死网关 JSON | `PaymentService` / Controllers 无 `purchase_units`/`/v2/checkout/orders`；仅注入中性 `amount_breakdown` DTO；PayPal JSON 仅在 `PayPalApiClient`/`PayPalProvider` | **pass** |
| R2 | breakdown 守恒路径存在 | `AmountBreakdownBuilder` 以 `value` 为准微调 discount/item_total；契约测 `testBreakdownConservesValue` / PayPal createOrder 断言 breakdown；`createOrder`/`patchOrder` 均接 breakdown | **pass** |
| R3 | capability ≠ `supported_discount_actions` | PayPal/Fake 新增 `amount_breakdown` + `discount_passthrough` + `passthrough_formats`；营销仍用原 `supported_discount_actions`；Stripe **未**声明透传能力 | **pass** |
| R4 | 无伪造明细路径 | Stripe 无 breakdown capability 且 create 忽略 context breakdown（仅净额）；PayPal 仅在有 DTO/优惠时映射；Fake 回显壳 DTO 非假造网关字段 | **pass** |
| R5 | PCI / 金额 minor | 激励路径无卡号/CVV；报价与应付核心为 `amount_minor` int；百分比配置解析后 `round` 为 int 减免（非 float 核心入账） | **pass** |
| R6 | 禁 surcharge 主路径 | 激励配置/行仅减免；未接线 surcharge 字段作本 feature 主路径 | **pass** |
| R7 | 壳编排 / 渠道映射 | SPI+Event+Session 在壳；网关映射在 Provider/ApiClient（`shell_provider_business_isomorph`） | **pass** |

## 抽检路径

- `Service/PaymentService.php` — 激励 apply + 中性 breakdown
- `Service/AmountBreakdownBuilder.php` — 守恒
- `Service/PayPalApiClient.php` — `formatAmountBreakdown`
- `PaymentProvider/PayPalProvider.php` / `FakeProvider.php` / `StripeProvider.php` — capability 分界
- `Test/Unit/Service/*Incentive*ContractTest.php` — 复跑 **OK (10 tests, 46 assertions)**

## 观察（非否决）

1. `PaymentService` 对所有 method 注入中性 `amount_breakdown`；无透传能力的 Provider（如 Stripe）忽略之，符合「只扣净额、不伪造」。
2. 真 Browser 支付通路仍属测试席门禁（ACC-GATE）；本复审覆盖**代码合规面**，不替代 Browser pass。
3. 退款比例回退（deps [3]）不在本施工单元过签范围。

## 小缺陷

本回合**未改码**（无必须修的阻断缺陷）。

## verdict

**pass** — 满足 contracts 支付席条款 PAY-1…7 与 payment-shell 同构边界；可进入/继续测试席真 Browser 验收。

## notify_pm

**true**

@项目经理：本席合规复审已交付（verdict=pass），请检查并更新 SESSION。禁改 SESSION。
