# 商品询价已提交

事件名：`Weline_Product::quote_request_submitted`

## 时机

`ProductQuoteRequestService::submit()` 成功写入新询价单之后（非幂等重复、非 honeypot）。

## 载荷

| 键 | 说明 |
|---|---|
| `quote_request_id` | 新单 ID |
| `quote_request` | 模型实例 |
| `status` | 恒为 `new` |
| `customer_id` | 登录顾客 ID 或 `null` |
