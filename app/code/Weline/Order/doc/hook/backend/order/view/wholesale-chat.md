# Hook：订单详情批发沟通槽

订单后台详情「批发订单沟通」插槽内的默认扩展点。订单布局只保留空 `<w:slot id="backend-order-b2b-chat">`，由 `Weline_B2B` 通过部件 `b2b-backend-order-chat` 的 `default_injections`（及本 Hook 实现）填充商家侧协商消息，禁止 Order 直出聊天 UI。

## 实现

- `Weline_B2B/view/hooks/Weline_Order/backend/order/view/wholesale-chat.phtml`
- `Weline_B2B/view/templates/Backend/widgets/backend-order-chat.phtml`
