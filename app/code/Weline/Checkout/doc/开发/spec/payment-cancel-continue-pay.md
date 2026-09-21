# 规格：支付取消/失败后继续支付与尝试历史

## 分类

- work_kind: feature
- FE/BE: FE+BE（Checkout 取消落地 / 结账 recovery；Order 账户 CTA；Payment 后台记录合并）
- ui_skill_decision: participate（账户订单卡 CTA；取消页主 CTA）
- plan_complexity: complex

## 范围（已确认：全做）

| 编号 | 内容 |
|------|------|
| P0 | 支付取消/失败后不得引导至空 `/checkout`；主 CTA「继续支付」接回 `#payment-recovery` / `resumePaymentV2` |
| P0′ | 历次支付尝试记全：库层 Transaction 不删；recovery 追加历史；后台订单支付记录 Transaction∪Attempt 合并展示 |
| P1 | 账户未付订单卡显示「继续支付」；已付+待发货保持「查看详情」 |

## EARS

- When 买家在网关取消或支付失败且订单仍未付，系统 shall 落地取消确认页，主 CTA 为「继续支付」并指向可恢复支付链（含 `quote_token` / `idempotency_key` / `order_uuid` / `payment_method`），禁止裸链 `/checkout`。
- When 买家点击「继续支付」或账户侧同名 CTA，系统 shall 复用已有订单并经 `resumePaymentV2` 新建支付尝试，不得重新 `submitV2` 建单。
- When 某次支付进入失败/取消终态后再次发起支付，系统 shall 保留上一笔 `PaymentTransaction` 终态行，并追加会话侧 `payment_attempt_history`；不得覆盖/删除历史尝试。
- When 后台打开订单支付记录，系统 shall 合并展示 Attempt 与 Transaction（含 failed），禁止「有成功 Attempt 则隐藏失败 Transaction」。
- When 账户订单组状态为待支付（`pending`），系统 shall 展示「继续支付」链；已付/取消不下发该 CTA。
- When Express 取消已 `abandon` 订单，系统 shall 引导回购物车/商品，不得展示「继续支付」假闭环。

## 用例（可执行 · 冻结）

### UC1 网关取消 → 继续支付

1. 店面结账 `submitV2` 建单并跳转支付。
2. 买家在网关取消，回跳 `/checkout/success?outcome=cancel&order_uuid=…&checkout_token=…`。
3. 主 CTA 文案为「继续支付」，href 含 `/checkout` 与 `#payment-recovery?`（含 quote/idempotency/order_uuid/payment_method）。
4. 点击后结账页展示 recovery，可触发 `resumePaymentV2` 再进网关或完成支付。
5. 断言：购物车可空，但 recovery 区可见，非结账空态表单。

### UC2 续付新建尝试且历史保留

1. 同订单完成 UC1 或模拟一次 failed Transaction。
2. 再 `resumePaymentV2` 成功创建第二笔 Transaction。
3. 按 `order_id` 查询 `weline_payment_transaction`：至少 2 行；首行仍为 failed（或终态），次行 pending/success。
4. 后台订单支付记录列表含两行（或等价合并后 ≥2）。

### UC3 账户未付继续支付

1. 登录顾客打开 `/customer/account/index#orders`，存在 `pending` 组。
2. 订单卡可见 `data-continue-pay`「继续支付」链接；已付组无此链接。
3. 点击进入可恢复支付链（同 P0 URL 契约）。

### UC4 Express 已取消订单

1. Express 取消触发 abandon → 订单 cancelled。
2. 取消页不展示「继续支付」主 CTA（或降级为继续购物/回车）。

## 非目标

- 不开启 PaymentFacadeV2 Intent 编排 cutover。
- 不延迟「建单后清车」（订单已存在；用 recovery 续付）。
- 不在账户列表展示完整支付时间线（审计看后台；前台仅 CTA）。

## 契约摘要

- Continue pay URL：`/checkout#payment-recovery?quote_token=&idempotency_key=&payment_method=&order_uuid=&outcome=failed&recoverable=1`（可选 `checkout_group_uuid`）。
- 权威历史：`weline_payment_transaction`；会话 `payment_attempt_history` 为门闩旁路审计；`payment_result` 仍为最新态指针。
- 账户 CTA：仅 `status=pending`（Presenter 有效状态）。
