# Weline_Order::backend::order::view::shipments

## 用途

订单后台详情「发货记录」插槽内的默认扩展点。订单布局（详情页与编辑发货面板）只保留空 `<w:slot id="backend-order-shipments">`，由配送模块通过 Hook（及部件 `default_injections`）填充发货记录与内联「物流进程记录」，禁止 Order 硬编码发货表或承运商查询 UI。

## 实现路径示例

- `Weline_Shipping/view/hooks/Weline_Order/backend/order/view/shipments.phtml`
- 部件：`Weline_Shipping::backend-order-shipments` → `templates/Backend/widgets/backend-order-shipments.phtml`
- 查询：`OrderShipmentTrackingQueryService` → 各 Provider `queryTracking`（服务端渲染进程列表）
