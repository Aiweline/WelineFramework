# 商品询价状态可否流转

事件名：`Weline_Product::quote_request_status_can_transition`

## 时机

`ProductQuoteRequestStateMachine::canTransition()` 内，基础转换表判定之后。

## 载荷

| 键 | 说明 |
|---|---|
| `from_status` | 当前状态 |
| `to_status` | 目标状态 |
| `can_transition` | bool，观察者可改写 |
| `transitions` | 完整转换表，观察者可扩展 |
