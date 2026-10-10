# 支付尝试入口归因（payment_entry）

## 背景

同一订单可先「快捷支付」失败，再从个人中心「继续支付」成功。订单列 `checkout_entry` 只记首次建单入口，无法描述每笔支付历史。运营与客服需要在支付记录上逐行看到入口类型。

## EARS

1. When 万能支付 `createPayment` 落库，系统 shall 将解析后的 `payment_entry` 写入 `request_data`（及 `metadata.payment_entry`）。
2. When Express 发起支付，系统 shall 记 `express`。
3. When `resumePaymentV2` / 续付模式发起支付，系统 shall 记 `continue_pay`（不得改写订单 `checkout_entry`）。
4. When HelpPay 快捷购买 / 找朋友代付发起支付，系统 shall 分别记 `quick_buy` / `helppay`。
5. When 后台订单支付记录列表渲染，系统 shall 对每一行展示 `payment_entry` 徽章（含失败行）。
6. If 历史行无信号，系统 shall 展示「未标记」，不得伪造入口。

## 非目标

- 不把 `continue_pay` 并入订单 `checkout_entry`。
- 不强制回填历史交易（仅推断已有 `express_checkout` / tags / mode 信号）。

## 归属

- owning_module: `Weline_Payment`（`PaymentEntry` + 记录投影 + 部件）
- callers: `Weline_Checkout`、`Weline_HelpPay` 在发起支付时提供上下文信号
