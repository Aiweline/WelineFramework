# Weline_Checkout::checkout::shipping_methods::enrich

结账/Express 将 `shippingInfo.listQuoteOptions` 映射为 methods 后触发。

## 边界

- 入参：`methods`、`lines`（须含 `offer_id`）、`address`、`scope`、`currency`。
- Observer 可改写 `methods` 的 `amount_minor`。
- 货源试算失败时 **保留本站 methods**（软降级），不得清空列表卡死结账。
- 与 `shipping_quote::overlay` 规则同源。
