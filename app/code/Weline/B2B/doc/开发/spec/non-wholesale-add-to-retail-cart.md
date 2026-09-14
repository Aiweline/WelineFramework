---
status: ready-for-plan
work_kind: feature
feature_slug: non-wholesale-add-to-retail-cart
module: Weline_B2B
updated: 2026-09-14
---

# 非批发商品加购进零售车

## 澄清记录

| 问题 | 答案 | 来源 |
|------|------|------|
| 哪些商品算「不支持批发」？ | 与批发显示门禁一致：站点 tob 开 + 产品 `selling_mode_tob` + SKU 有生效价目档；否则不支持 | 用户截图 + 既有 `ProductWholesaleEligibility` / REQ-B2B-0012 |
| 加购时身份/cookie 为 tob 怎么办？ | 仍写入**零售车**；迷你车切到零售车 Tab 展示刚加购商品 | 用户：「直接加入零售车，不要加入批发车」 |
| 是否禁止未开批发商品进入批发车？ | 是；覆盖原 REQ-B2B-0012「非目标」 | 用户确认 |

## 用户故事

作为批发身份买家，当我在站内 cookie/售卖模式仍为「批发」时浏览并加购一件**未开批发**的商品，我希望它进入**零售车**，并在迷你车看到零售车内容，而不是静默掉进批发车。

## EARS

1. WHEN 买家对不支持批发的商品发起加购且请求 `cart_type=tob`，系统 SHALL 将商品写入 `toc`（零售）购物车。
2. WHEN 买家对支持批发的商品在 tob 模式下加购，系统 SHALL 仍写入 `tob` 购物车（本需求不改变）。
3. WHEN 不支持批发的商品被 remap 进零售车，店面迷你车 SHALL 切换到零售车 Tab 并展示该次加购摘要。
4. IF B2B 未安装或 Offer Routing SPI 未注册，Cart SHALL 保持原 `cart_type` 解析（零热路径探测税失败时 fail-soft）。

## 用例

### UC1 主成功：批发身份 + 非批发 PDP 加购

1. 买家已登录且 `weline_selling_mode=tob`。
2. 打开无批发 UI 的 PDP（无价目档 / 未启用批发）。
3. 点击「加入购物车」。
4. 期望：行写入 toc 车；迷你车「零售车」有货；批发车计数不因该次加购增加。

### UC2 备选：批发商品仍进批发车

1. 同身份，打开有批发门禁通过的商品。
2. tob 模式加购。
3. 期望：写入 tob 车。

### UC3 异常：API 直传 tob

1. 客户端忽略 FE 门禁，对非批发 SKU 传 `cart_type=tob`。
2. 期望：服务端 Offer Routing remap 为 toc 后落库。

## FE / BE 范围

- BE：Cart `CommerceCartOfferRoutingInterface` SPI + B2B 实现；`CartService::add` 在快照后 remap。
- FE：`resolveAddCartType` 对无批发资格 PDP 强制 toc；加购成功后若 preferred 为 tob 且商品无批发资格则 `requestCartType(toc)`；B2B `cart-updated` 优先用摘要 `cart_type`。

## 验收

- UT：Offer Routing + Cart 契约 + Eligibility 文案。
- Browser：非批发 PDP + tob cookie → 加购 → 零售车 (1)，非批发车。
