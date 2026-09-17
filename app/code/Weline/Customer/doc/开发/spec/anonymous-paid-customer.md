# 支付成功匿名客户与通知邮箱兜底

## work_kind

`feature`（跨 Customer / Order / Checkout；非 simple）

## 需求纠偏

- 用户现象「客户调整无邮箱」**不全因匿名**：现代建单未写 `customer_email`，收货快照亦常无 `email`；后台只能用列或 `shipping.email` 兜底。
- 产品目标：访客支付成功后须有可通知联系人 → **Customer 建匿名账户** + **订单落联系字段**。

## EARS

- WHEN 结账提交且会话/地址含有效邮箱，系统 SHALL 将邮箱（及姓名/电话）写入订单 `customer_*`，并在收货地址 JSON 中补齐 `email`。
- WHEN 订单首次投影为已支付且 `customer_id` 为空，系统 SHALL 用结账/配送/支付元数据解析邮箱；若有效则创建或复用 `is_anonymous=1` 客户并绑定订单。
- WHEN 解析邮箱已属于正式（非匿名）客户，系统 SHALL 只回填订单联系字段，**不**自动绑定 `customer_id`（防未登录挂单到他人账户）。
- WHEN 解析邮箱已属于匿名客户，系统 SHALL 复用该客户并绑定。
- WHEN 无任何有效邮箱，系统 SHALL 不创建客户，并保留姓名/电话尽力落库；运营可后台补邮箱。
- IF 前台「访客转正」遇到已绑定的同邮箱匿名客户，系统 SHALL 允许设密转正（清 `is_anonymous`），而非报「已关联」。

## UC-01 访客 PayPal 支付成功

1. 访客结账（可能 Express 未手填邮箱）。
2. 建单：能拿到的联系方式写入订单。
3. 支付成功 → `Weline_Order::order_paid`。
4. Customer 观察者：从订单字段 / shipping / payment metadata|交易回包取邮箱 → ensure 匿名客户 → `attachCustomerToGuestOrders`。
5. 后台客户调整可见邮箱；`OrderMailNotifier` 可发信。

## 模块边界

| 模块 | 职责 |
|------|------|
| Checkout | 提交前把 `guest_email` 并入 `address.email` |
| Order | 建单持久化 `customer_email/name/phone` |
| Customer | `is_anonymous`；支付成功绑定；与 GuestConvert 转正衔接 |
| Payment | 不强制改；binder 软读交易/metadata 中的 payer email |

## 非目标

- 不自动登录匿名客户前台会话。
- 本轮不做历史订单批量回填 UI（可另开）。
