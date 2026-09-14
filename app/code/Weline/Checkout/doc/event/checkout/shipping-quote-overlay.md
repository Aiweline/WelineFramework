# Weline_Checkout::checkout::shipping_quote::overlay

`CheckoutGroupSubmitService::freezeAndQuote` 在本站 Shipping 报价成功后、allocate/tax/discount 之前触发。

## 边界

- **Checkout**：派发事件；`error` → 阻断结账；`degraded` → 保留本站运费并标记。
- **Dropship**：识别 listing 后试算；失败时读各 Provider `freightOnFailure()`：
  - `fallback_local`：回退本站运费，允许结账
  - `block_checkout`：阻断结账
- **Shipping 核**：不改。
- 策略配置在各供应商自己的 SystemConfig（如 CJ：`dropship/channel/cj/freight_on_failure`），壳不写死。
