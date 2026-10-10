# Weline_Order::frontend::account::order-detail::payment-records

## 用途

账户首页订单详情「支付历史」插槽。Order 只保留空槽 + Hook 名；由万能支付模块注入支付尝试行与 `payment_entry` 来源徽章。禁止 Order 直读 Payment 表拼表。

## 实现路径示例

- `Weline_Payment/view/hooks/Weline_Order/frontend/account/order-detail/payment-records.phtml`
- 模板：`Weline_Payment::templates/Frontend/account/order-payment-records.phtml`
