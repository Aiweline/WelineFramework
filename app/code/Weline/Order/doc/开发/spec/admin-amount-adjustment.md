# 后台订单金额调整壳（admin-amount-adjustment）

status: ready-for-plan

## 背景

批发等订单需要后台以「优惠说明 + 优惠金额」小计项下调应付。万能订单提供壳与资金地板；类型策略（tob）由业务模块扩展。

## 用户故事

- 作为运营，我希望在后台批发订单编辑页添加优惠小计项，使订单总额下降，并留下说明。
- 作为系统，在已部分付款时禁止把总额压到已捕获支付之下，且不自动退款。

## EARS

1. When 订单 `order_type` 有 `AdminAmountAdjustmentPolicy` 且 `canAdjust` 通过，the system shall 在编辑页展示金额调整表单。
2. When 管理员提交有效优惠说明与金额，the system shall 追加 `type_payload.discount_lines` 中 `source_type=admin_adjustment` 行并重算 `money_snapshot`。
3. When 优惠后 `grand_total_minor < sumCapturedAmountMinor`，the system shall 拒绝并返回 `adjustment_below_paid` / tob 等价 reason。
4. When 支付激励同步 `discount_lines`，the system shall 按 `source_type` 合并并保留 `admin_adjustment` 行。
5. When 订单为 toc 或无策略，the system shall 不展示表单且拒绝写路径。
6. When tob 挂单 `awaiting_balance` 或 `hang_revision_pending`，the system shall 拒绝本壳调价并指引挂单改价流。

## UC

| id | acceptance |
|----|------------|
| UC-admin-adj-add | 未付 tob 单加优惠行后 totals/grand 下降 |
| UC-admin-adj-floor | 部分付款单贴地板通过、再压低拒绝 |
| UC-admin-adj-toc | toc 不可见/不可写 |
| UC-admin-adj-merge | incentive 同步后 admin 行仍在 |
