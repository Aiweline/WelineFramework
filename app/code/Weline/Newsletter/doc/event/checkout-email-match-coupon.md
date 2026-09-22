# 结账邮箱匹配自动用券（T2）

| 项 | 值 |
|----|-----|
| 事件 | `Weline_Checkout::checkout::identity::resolve::after` |
| 观察者 | `Weline\Newsletter\Observer\CheckoutEmailMatchCouponObserver` |
| 模块 | `Weline_Newsletter` |
| 配套 | 同观察者亦挂 `Weline_Checkout::checkout::guest::validate::after` |

## 意图

当结账身份已解析且可识别邮箱（`guest_email`）或登录 `customer_id` 时，查询 Newsletter 台账：

- `gift_status = issued`
- `coupon_code` 非空

→ 经 `MarketingCheckoutCouponSession::applyCoupon` 写入 toc 结账券会话（best-effort）。

## 输入（事件 data）

- `identity.guest_email` / `identity.customer_id`
- `context.email` / `context.guest_email` / `context.shipping_address.email`
- `checkout_data.*`（guest validate 路径）

## 禁令

- **禁止**读 Checkout / Cart / Coupon Model
- 批发 / `tob` / `disablesStorefrontDiscounts` → skip

## 可选 Interface

`Weline\Newsletter\Api\NewsletterCheckoutCouponAutoApplyInterface::applyForRecognizedEmail($context)`  
供 Checkout 在其它邮箱确定点直接调用（本波主路径用现有 Event，无需改 Checkout）。
