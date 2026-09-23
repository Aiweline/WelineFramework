# 施工纪要 · Team:支付开发工程师:

| 项 | 值 |
| --- | --- |
| seat | `Team:支付开发工程师:` |
| slug | `payment-method-incentive-discount` |
| 日期 | 2026-09-22 |
| 波次 | construction（deps [1]+[2]） |
| contracts | `frozen=true` |
| 版本 | `Weline_Payment` → `1.9.102` |

## 交付摘要

壳编排激励报价/快照；PayPal/Fake 渠道映射 breakdown / echo；禁 surcharge；禁混用 `supported_discount_actions`。

### 已落地

1. SystemConfig `incentive_*`：`paypal.phtml` + `fake_card.phtml`（未配=0）
2. SPI `PaymentMethodIncentiveQuoteInterface` + `PaymentMethodIncentiveQuoteService`（`provides` 已注册）
3. Event `Weline_Payment::checkout::available_methods::enrich` + Observer + `doc/event/checkout-available-methods-enrich.md`
4. `PaymentQueryProvider` 列表 enrich → 扁字段 `incentive_savings_minor` 等
5. `PaymentService::createPayment` 应用激励入 `discount_lines` 并下调应付；注入 `amount_breakdown`
6. `PaymentCheckoutSessionPersistenceService` 保留 `source_type`/`funding_source`/`method_code`
7. `AmountBreakdownBuilder` 守恒 DTO
8. PayPal：`amount_breakdown`+`discount_passthrough`；`createOrder`/`patchOrder` 带 breakdown
9. Fake：`echo_breakdown` 对照
10. `PaymentLedger::TYPE_DISCOUNT`；模块 i18n zh+en；契约测 10/10 OK

### 自测证据

```text
php -l （改动 PHP 全过）
php vendor/bin/phpunit \
  app/code/Weline/Payment/Test/Unit/Service/PaymentMethodIncentiveQuoteContractTest.php \
  app/code/Weline/Payment/Test/Unit/Service/PaymentIncentivePassthroughCapabilityContractTest.php \
  --no-configuration
→ OK (10 tests, 46 assertions)
```

### 未做（deps 下游）

- 前端列表「减 X」徽章 / 摘要分列 UI（ready_for_frontend）
- 真 Browser（PM 拉测试；fake + PayPal sandbox）
- 退款比例回退接线（deps [3]）
- SESSION 更新（禁本席改）

## compliance_review（代码面）

| 项 | 结论 |
|----|------|
| 壳不写死 PayPal JSON | pass（映射在 Provider/ApiClient） |
| ≠ supported_discount_actions | pass |
| 无 surcharge 主路径 | pass |
| amount_minor | pass |
| 真 Browser | **pending**（交 PM→测试席） |

## result

`closed`（实现+UT 交付；Browser 待测）
