# 返工2纪要 · Team:支付开发工程师:

| 项 | 值 |
| --- | --- |
| seat | `Team:支付开发工程师:` |
| slug | `payment-method-incentive-discount` |
| 日期 | 2026-09-22 |
| 波次 | 返工2（Browser retest fail） |
| 版本 | `1.9.104` → **`1.9.105`** |

## 根因

`CheckoutAvailableMethodsIncentiveEnrichObserver` 用 `??` 链：

```php
($context['totals']['grand_total_minor'] ?? 0) ?? round(amount * 100)
```

中间 `?? 0` 恒有值 → 永不落到 major `amount` 换算 → 结账只传 `amount=10.13` 时 `baseMinor=0` → 无激励徽章。

## 修复

- 重写 `resolveBaseAmountMinor`：显式 `isset` 优先 `amount_minor` / `grand_total_minor` / `totals.grand_total_minor`；否则 major ×100（JPY 等零小数 ×1）。对齐 `PaymentQueryProvider::amountMinor`（复制，非跨模块私有调用）。
- 契约测：仅 `amount=10.13` → 1013；配合固定激励 500 → savings>0。
- 升版 **1.9.105**；`server:reload`。

## ready_for_retest

**true**

## notify_pm

**true**

@项目经理：本席返工2已交付，请检查并更新 SESSION；请重拉测试席 Browser。禁改 SESSION。
