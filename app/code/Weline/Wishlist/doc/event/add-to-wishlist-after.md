# 收藏新增加入后

事件名：`Weline_Wishlist::add_to_wishlist_after`

## 时机

`WishlistService::add()` 确认该商品还不在当前收藏、并且已经写入之后。已存在的重复加入、移除、校验失败不派发。

## 载荷

| 键 | 说明 |
|---|---|
| `product_id` | 店面商品 ID |
| `created` | 恒为 `true` |
| `customer_id` | 本模块按会话收藏，这里为 `0`；监听方自行解析当前顾客 |
| `wishlist_id` | 会话收藏没有行 ID，为 `0` |

## 约束

- 收藏写入成功后，派发失败只记日志，不撤回收藏。
- 分销监听只记互动，不写回收藏模块。
