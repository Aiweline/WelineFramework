# Event: Weline_Payment::checkout::available_methods::enrich

| 项 | 值 |
| --- | --- |
| 事件名 | `Weline_Payment::checkout::available_methods::enrich` |
| 归属 | `Weline_Payment`（Payment dispatch） |
| 类型 | 旁路注入（列表可减金额） |
| 观察者（首期） | `CheckoutAvailableMethodsIncentiveEnrichObserver` |

## 何时触发

`PaymentQueryProvider` 组装结账可用支付方式列表后、返回给 Checkout 前。

## 事件数据

| 键 | 类型 | 说明 |
| --- | --- | --- |
| `methods` | `list<array>` | 可变；每项为 `paymentMethodPayload` 扁数组 |
| `context` | `array` | Query 入参（可含 `amount_minor` / `currency` / `totals`） |

## 约定字段（写出）

观察者写入 contracts §payload 扁字段：

- `incentive_savings_minor`（≥0）
- `incentive_display`
- `incentive_available`
- `incentive_type` / `incentive_percent`（可选）

## 禁止

- 跨模块 `new` Service / 直调 Controller
- 用本事件承载 surcharge
- 未文档化即另起事件名
