# 商品询价状态变更前

事件名：`Weline_Product::quote_request_status_change_before`

## 时机

`ProductQuoteRequestStateMachine::transition()` 在合法性检查通过后、写库前。

## 载荷

| 键 | 说明 |
|---|---|
| `quote_request` | 模型 |
| `quote_request_id` | ID |
| `old_status` / `new_status` | 状态 |
| `comment` | 可选备注 |
| `can_change` | 默认 `true`；置 `false` 则抛出 blocked |
