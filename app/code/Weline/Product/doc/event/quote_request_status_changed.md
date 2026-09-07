# 商品询价状态已变更

事件名：`Weline_Product::quote_request_status_changed`

## 时机

状态已持久化成功之后。

## 载荷

| 键 | 说明 |
|---|---|
| `quote_request` | 更新后模型 |
| `quote_request_id` | ID |
| `old_status` / `new_status` | 状态 |
| `comment` | 可选备注 |
| `admin_reply_at` | 回复/关闭时间戳（若已写入） |

流转到 `replied` / `processed` 且原无 `admin_reply_at` 时会写入，供个人中心未读角标使用。
