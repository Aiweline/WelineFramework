# Weline_Order::order_tracking_feedback_received

物流反馈写入不可变 Inbox 之后触发。

## 时机

`OrderTrackingFeedbackReceiver::receive()` 在 Provider `verifyFeedback` / `parseFeedback` 通过并成功 `save` Inbox 之后。

## 载荷

| 键 | 说明 |
|---|---|
| `inbox` | `OrderTrackingFeedbackInbox` 模型 |
| `inbox_code` | Inbox 业务码 |
| `provider` | `TrackingProviderInterface` 实例 |
| `parsed` | `TrackingFeedbackResult` |

## 约束

- Provider 的 verify/parse 必须是纯函数，不得在此事件之前写订单状态。
- 需要推进发货/订单状态时，应在监听本事件的消费者中幂等执行。
