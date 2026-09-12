# Weline_Checkout::checkout::freeze_quote::enrich - 冻结报价富集

## 事件说明

`CheckoutGroupSubmitService::freezeAndQuote` 生成基础 payload 后触发。

## 边界

- **B2B**：只通过 `payment.asset_policy.*` 声明批发信用**类型**（tob + ROLE_DISCOUNT），不写额度。
- **Payment**：Observer 贡献信用**数据**（CustomerAsset 余额、可抵扣额），回写 `payload['b2b_credit']`。
- **Checkout**：只派发事件并读取贡献结果。
