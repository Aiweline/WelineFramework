# 评论已写入

事件名：`Weline_Review::review_created`

## 时机

`ReviewService::create()` 评论行与媒体挂接都成功之后。

## 载荷

| 键 | 说明 |
|---|---|
| `product_id` | 商品评论的店面商品 ID；非商品评论或映射不到时为 `0` |
| `customer_id` | 当前顾客，游客为 `0` |
| `review_id` | 新评论 ID |
| `rating` | 规范化后的评分 |
| `type_code` | 评论类型，例如 `product` |
| `entity_uuid` | 评论对象的全局 UUID |
| `idempotency_key` | `review_created:{review_id}` |

## 约束

- `product_id` 来自当前网站分片的 `global_product_uuid` 映射，不是身份注册表 ID。
- 派发失败只记日志，不把已落库的评论报成失败。
