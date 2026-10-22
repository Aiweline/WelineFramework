# 购物车加购成功后

事件名：`Weline_Cart::cart_item_added`

## 时机

`CartService::add()` 把本次加购写入购物车并组装摘要之后。

## 载荷

在原有 `summary` / `cart` / `selection_hash` / `quantity_adjusted` 之外，追加本次加购：

| 键 | 说明 |
|---|---|
| `product_id` | 店面商品 ID（快照 `productId`，否则报价上的 legacy id） |
| `customer_id` | 登录顾客；游客为 `0` |
| `quantity` | 本次实际加入的数量（库存裁剪之后） |
| `price` | 本次单价（主单位，不是分） |
| `cart_type` / `type_payload` | 类型信封，非破坏追加 |

## 约束

- 购物车已经写入后，派发失败只记日志，不把加购报成失败。
- 监听方用顶层 `product_id`，不要把整车摘要里的其它行当成这一次加购。
