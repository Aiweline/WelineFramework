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
| 加购时身份/cookie 为 tob 怎么办？ | 仍写入**零售车**；迷你车 **view-only** 切到零售桶展示刚加购商品；**不**永久改写批发偏好 cookie | 用户：「直接加入零售车」+ 2026-10-06 空车/困惑修复 |
| 立即结账会空车吗？ | buy-now 跳转 `/checkout?cart_type=toc`；结账 URL/`data-cart-type-handoff` 优先于 tob cookie | 用户：批发偏好下立即结账空车 |
| 客户如何事前知道？ | 站点 tob 开且 SKU 无批发资格时渲染 soft hint（仅 tob 偏好可见） | 用户：应有提示否则困惑 |
| 是否禁止未开批发商品进入批发车？ | 是；覆盖原 REQ-B2B-0012「非目标」 | 用户确认 |

## 用户故事

作为批发身份买家，当我在站内 cookie/售卖模式仍为「批发」时浏览并加购一件**未开批发**的商品，我希望它进入**零售车**，并在迷你车看到零售车内容，而不是静默掉进批发车。

## EARS

1. WHEN 买家对不支持批发的商品发起加购且请求 `cart_type=tob`，系统 SHALL 将商品写入 `toc`（零售）购物车。
2. WHEN 买家对支持批发的商品在 tob 模式下加购，系统 SHALL 仍写入 `tob` 购物车（本需求不改变）。
3. WHEN 不支持批发的商品被 remap 进零售车，店面迷你车 SHALL **view-only** 展示零售桶摘要（不永久改写 `weline_selling_mode`）。
4. WHEN 全局偏好为 tob 且当前商品不支持批发，PDP/加购弹层 SHALL 显示零售-only soft hint。
5. WHEN 当前商品支持批发且偏好为 toc，PDP SHALL 在规格下方显示简短「支持批发」文字入口，点击后切换到批发模式。
5. WHEN 上述商品在 tob 偏好下「立即结账」，系统 SHALL 跳转结账并手递 `cart_type=toc`，结账页 SHALL 加载 toc 车（非空 tob）。
6. IF B2B 未安装或 Offer Routing SPI 未注册，Cart SHALL 保持原 `cart_type` 解析（零热路径探测税失败时 fail-soft）。

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
- FE：`resolveAddCartType` 对无批发资格 PDP 强制 toc；PDP soft hint（tob 偏好显隐）；buy-now `?cart_type=toc` handoff；结账/购物车页 URL handoff 优先；加购后 `retail-only-add-preview` 迷你车 toc 预览（不永久 `setMode(toc)`）。

## 验收

- UT：Offer Routing + Cart 契约 + Eligibility + retail-only hint / checkout handoff 文案。
- Browser：非批发 PDP + tob cookie → 见提示 → 加购零售车；立即结账 → `/checkout?cart_type=toc` 非空；偏好 cookie 仍 tob。
