# Weline_Order::backend::order::list::shipping

## 用途

订单后台列表页发货工作台插槽内的默认扩展点。订单列表只保留空 `<w:slot id="backend-order-list-shipping">`，由配送模块通过 Hook（及部件 `default_injections`）注入发货入口与待发货摘要，禁止 Order 硬编码配送运营 UI。

## 实现路径示例

- `Weline_Shipping/view/hooks/Weline_Order/backend/order/list/shipping.phtml`
- 部件：`Weline_Shipping::backend-order-list-shipping` → `templates/Backend/widgets/backend-order-list-shipping.phtml`
