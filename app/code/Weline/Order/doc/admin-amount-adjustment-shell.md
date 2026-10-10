# 后台金额调整壳

Order 宿主编排后台「优惠小计项」调应付；业务规则由 `OrderAdminAmountAdjustmentPolicyInterface` 按 `order_type` 扩展（B2B 提供 tob）。

## 边界

- 壳：校验入口、写 `discount_lines`（`source_type=admin_adjustment`）、重算 money、已付地板、作废未完成支付意图、后台 UI/ACL。
- 策略：是否允许、状态门禁、说明/金额校验、`onApplied` 副作用（不得绕过挂单买家确认改价）。
- 已付地板：`PaymentRefundFacadeInterface::sumCapturedAmountMinor`（多 intent 求和）。
- 与支付激励同袋：`DiscountLinesBySourceMerger` 按 `source_type` 合并。

## 入口

- Facade：`OrderFacadeInterface::appendAdminAdjustmentLine`
- Shell：`OrderAdminAmountAdjustmentShell::addDiscountLine` / `canAdjustOrder`
- 后台：`order/backend/order/adjust-amount`（ACL `Weline_Order::order_admin_amount_adjust`）

规格：`doc/开发/spec/admin-amount-adjustment.md`
