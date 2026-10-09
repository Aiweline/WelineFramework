# Weline_Order::frontend::account::order-detail::shipments

## 用途

账户首页订单详情「发货记录」插槽。Order 只保留空槽 + Hook 名；由配送模块注入只读发货行、运单号与物流进程。禁止 Order 直读 `OrderShipment` 表拼表；禁止在账户面暴露「办理发货」等写操作。

多拆单场景下详情按单个 `order_uuid` 打开，本槽只投影该子单发货记录，不得混入同结账组其它子单运单。

## 实现路径示例

- `Weline_Shipping/view/hooks/Weline_Order/frontend/account/order-detail/shipments.phtml`
- 模板：`Weline_Shipping::templates/Frontend/account/order-shipments.phtml`
- 查询：`BackendOrderShipmentsService::listForOrderId` + `OrderShipmentTrackingQueryService`
