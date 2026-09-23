# 施工纪要 · Team:前端:

| 项 | 值 |
| --- | --- |
| seat | `Team:前端:` |
| slug | `payment-method-incentive-discount` |
| 日期 | 2026-09-22 |
| 波次 | construction（deps 前端展示） |
| contracts | `frozen=true` · `ready_for_frontend=true` |
| 版本 | `Weline_Checkout` → `1.5.41` |
| work_mode | **Checkout 模块运行时模板/JS**（未改 `Theme/view/theme` / `app/design`） |
| 技能 | MCP `get_skill` DISABLED → Read `Theme开发总指南` + `模块翻译CSV规范` |

## 交付摘要

结账支付方式列表「减 X」徽章 + 摘要「支付方式优惠」分列 + 切换即时重算/getData 核对。消费支付席已出站扁字段；禁平行 REST；禁 JS `createElement` 造激励文案。

### 已落地

1. **透传**：`CheckoutPaymentMethodsProvider::normalizeIncentiveFields` → `incentive_savings_minor` / `incentive_display` / `incentive_available` / `incentive_type` / `incentive_percent`
2. **SSR 徽章**：`CheckoutHtmlRenderer::renderPaymentMethodOptions` 输出 `span.weline-checkout__payment-incentive[data-payment-incentive]`，文案 = 服务端 `incentive_display`；`available=false` 或 savings=0 不渲染
3. **摘要行**：`data-checkout-payment-incentive-row` + `data-payment-incentive-amount`；源串「支付方式优惠」
4. **切换**：`change` → 即时 `renderTotals()`（扣 `incentive_savings_minor`）→ `schedulePaymentIncentiveReconcile` → `getData({payment_method})`；`loadCheckout` 普通模式亦传当前选中 method
5. **样式**：`weline-checkout__payment-title-meta` / `__payment-incentive`；色用 `--color-success` / `--color-primary` 等 Token
6. **i18n**：Checkout `zh_Hans_CN` + `en_US` + `i18n:collect`
7. **契约测**：`CheckoutHtmlRendererTest` 增徽章断言 + index 源扫描（data-row / reconcile / 禁 fetch）

### 自测证据

```text
php -l CheckoutPaymentMethodsProvider.php / CheckoutHtmlRenderer.php → OK
php vendor/bin/phpunit app/code/Weline/Checkout/test/Unit/Service/CheckoutHtmlRendererTest.php \
  --bootstrap app/code/Weline/Checkout/test/Unit/bootstrap.php --no-configuration
→ OK (14 tests, 135 assertions)
模块 CSV：已写入「支付方式优惠」→ zh 同字 / en 「Payment method discount」
php bin/w i18n:collect Weline_Checkout：本机有并行 collect/cron 争用，执行偏长；
  var/cache/phrase 已可见 source「支付方式优惠」↔「Payment method discount」映射。
```

### 未做（下游）

- 真 Browser UC（交 PM→测试；须 UI+原型过签门禁）
- Theme 私有 `payment-method-card*` 债收束（非首期；本席未加深硬编码色）
- SESSION 更新（禁本席改）

## compliance

| 项 | 结论 |
|----|------|
| 消费正式扁字段名 | pass |
| 禁平行 REST / 禁 JS 拼徽章文案 | pass |
| 摘要与券分列 | pass |
| Theme Token / 既有 weline-checkout | pass |
| 模块 CSV 仅 zh+en + collect | pass |
| 禁改 Payment Provider 核心 | pass |
| 真 Browser | pending（测试席） |

## result

`closed`（FE 代码+契约测+collect 交付；Browser 待测）

`notify_pm: true`

**@项目经理：本席已交付/上报，请检查并更新 SESSION。**
