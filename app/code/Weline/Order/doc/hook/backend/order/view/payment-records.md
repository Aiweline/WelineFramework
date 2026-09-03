# Weline_Order::backend::order::view::payment-records

## 用途

订单后台详情「支付记录」插槽内的默认扩展点。订单布局只保留空 `<w:slot id="backend-order-payment-records">`，由万能支付模块通过 Hook 实现（及部件 `default_injections`）填充 Attempt 记录，禁止 Order 直读 Payment 表拼表。

## 实现路径示例

- `Weline_Payment/view/hooks/Weline_Order/backend/order/view/payment-records.phtml`
- 部件：`Weline_Payment::backend-order-payment-records` → `templates/Backend/widgets/backend-order-payment-records.phtml`
