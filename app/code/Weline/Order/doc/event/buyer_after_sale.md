# 买家售后事件

买家售后使用下面三个事件。不复用 `Weline_Order::order_refunded`。

`order_refunded` 表示订单退款已经完成，由支付退款成功后触发，载荷是订单对象。买家售后是申请和人工处理，保存或审核时订单尚未完成退款。现金退款仍走既有 `OrderRefundCoordinator`，由 `buyer_after_sale_link_payment` 的观察者发起，不在售后服务里直接退款，也不另派 `order_refunded`。

## 事件名

| 事件 | 时机 |
|---|---|
| `Weline_Order::buyer_after_sale_submitted` | 申请单已保存为 `submitted` |
| `Weline_Order::buyer_after_sale_approved` | `return` 或 `exchange` 从 `submitted` 变为 `approved` |
| `Weline_Order::buyer_after_sale_link_payment` | 总闸与支付联动闸都开，且 `refund_only` 已 `approved`，或 `return` 已 `return_received` |

`exchange` 确认收货后进入 `awaiting_reship`，登记重发运单后进入 `manual_done`。换货永不派发 `buyer_after_sale_link_payment`。

派发失败只记日志，不回滚已保存的申请单。

## 载荷

只含标量：`request_uuid`、`order_uuid`、`customer_id`、`website_id`、`resolution`、`status`、`reason_code`、`currency`、`amount_minor`、`item_count`、`link_payment`、`idempotency_key`、`actor`、`occurred_at`。

`buyer_after_sale_approved` 另带 `return_address`、`return_address_source`（`product` / `custom` / `order`）、`warehouse_ids`（逗号分隔的履约仓 id，仅 `order` 时有值）。

退货地址在批准时裁决，不采用顾客提交的地址：

1. 订单商品属性 `return_address` 有值时优先。多个不同地址按商品 id 去重后换行拼接。
2. 否则，订单所在范围的「使用自定义退货地址」开启且地址非空时，用该范围地址。
3. 否则跟订单履约仓。运输模块只按 `warehouse_ids` 格式化这些仓的发货源，不取任意仓。

批准前，订单派发 `Weline_Order::buyer_after_sale_collect_product_return_address`（`website_id`、`product_ids`）。商品模块把 `product_return_addresses` 写回。没有观察者或读失败视为商品没有退货地址。
