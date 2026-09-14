# Weline_Order::backend::order::view::shipments

## 用途

订单后台详情「发货记录」插槽内的默认扩展点。订单布局只保留空 `<w:slot id="backend-order-shipments">`，由配送模块通过 Hook（及部件 `default_injections`）填充发货/物流记录，禁止 Order 硬编码发货表 UI。

## 实现路径示例

- `Weline_Shipping/view/hooks/Weline_Order/backend/order/view/shipments.phtml`
- 部件：`Weline_Shipping::backend-order-shipments` → `templates/Backend/widgets/backend-order-shipments.phtml`
