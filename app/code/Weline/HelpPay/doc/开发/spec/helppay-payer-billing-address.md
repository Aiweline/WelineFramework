# 规格：代付页付款账单地址（结账同款）

slug: `helppay-payer-billing-address`  
模块：`Weline_HelpPay`（代付页）+ `Weline_Shipping`（结账地址部件）+ `Weline_Checkout`（`CheckoutPaymentMethodsProvider` / `CheckoutHtmlRenderer`）+ `Weline_Payment`（`getCheckoutPaymentMethods`）

## work_kind

`feature`（代付人账单地址：仅卡等需账单的支付方式展示结账同款选/改/增；支付方式与结账统一）

## plan_skip

复用万能结账支付目录 + HtmlRenderer + 结账地址部件；无新架构面。

## FE / BE 范围

| 面 | 范围 |
|---|---|
| FE | `/h/` 支付方式卡片（图标+标题+简介）与结账同款；仅 `requires_billing=1` 展示账单；确认付款按门禁校验 |
| BE | Payer 经 `CheckoutPaymentMethodsProvider` 取列表（禁止自扫 Provider / 硬编码兜底）；`CheckoutHtmlRenderer` 出 HTML |

## EARS

1. When 代付人打开有效 `/h/{token}`，the system shall 展示与万能结账同源的支付方式列表（含 `icon_url` 图标）；空列表展示「暂无可用支付方式」，**不**伪造 PayPal/银行卡。
2. When 所选支付方式 `requires_billing=0`（如 PayPal），the system shall **不**展示「付款账单地址」表单，确认付款不得因账单缺失失败。
3. When 所选支付方式 `requires_billing=1`（卡 / `fake_card` / 能力声明），the system shall 展示结账同款地址部件（选/换/增），`session-isolation`，不得写回收货。
4. When 需账单且地址不完整时点「确认付款」，the system shall 就地提示并阻止；不需账单时跳过账单校验。
5. The system shall 继续 `shipping_redacted`。
6. The system shall 支付方式 UI 使用结账同类名（`weline-checkout__option--payment` / `weline-checkout__payment-logo`）。

## 用例

### UC1 选 PayPal

1. 打开 `/h/` → 选 PayPal → 无账单表单 → 确认付款不拦账单。

### UC2 选银行卡 / 测试卡

1. 选卡类方式 → 见结账同款账单地址 → 填完整 → 确认付款通过账单校验。

## 非目标

- 本期不做真实支付网关代付闭环
- 不改 Shipping 内核

## 验收

- 契约：`payer.phtml` 含 `data-helppay-payment-method` / `data-requires-billing`；账单区可 `hidden`
- Browser：默认非卡方式时账单不可见；切到卡后可见
