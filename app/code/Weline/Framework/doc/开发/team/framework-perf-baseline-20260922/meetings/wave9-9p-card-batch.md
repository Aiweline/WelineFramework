# wave9-9p — product.card.render 批渲染 + fragment 分桶

- date: 2026-09-22
- seat: Product 店面列表卡渲染归属（可写 Weline_Product）
- channel: `framework-unreasonable-audit.md` **msg-105**（re msg-102 / msg-103）
- Product: **1.0.298**
- claim_sla: **false** · 禁自 reload · **不回退** 8s5 shell

## 根因（对照 8v2 ≈1289ms）

| 假设 | 结论 |
|------|------|
| fragment cache miss | **是**：冷窗列表唯一品 ×N → Policy 全 MISS，每卡走 `Template::fetch(product-card)` + 嵌套 shopper/labels/add-to-cart |
| 逐卡 Taglib SSR | **是**：catalog/category `foreach` + `<w:product:card>` 每卡一次 `render`/`measurePhase` |
| DB/RPC N+1 | **否**：卡路径 helpers 无查询；耗时在 SSR 拼装 |
| `card_index` 进 logicalKey | **加重暖 HIT 失败**：同品不同列表位无法复用片段 |

## 改动

1. `ProductCardRenderer::projectFromOffers`：批映射 + 批渲染，单 `product.card.render` measurePhase（`batch=true`）。
2. catalog / category 列表改走批路径；CSS 仍 once emit；卡 HTML 仍全量 SSR（SEO / 可点购语义保留）。
3. `bucketCardIndexForFragmentReuse`：index → eager(0) / lazy(8) 两档，复用既有 `StorefrontProductCardFragmentCache` / `productCardHtmlPolicy`。
4. 禁平行 static；禁删卡功能。

## 预期影响

- **冷窗处女（全 MISS）**：去掉 Taglib×N 与 measurePhase×N 开销；SSR 本体仍在 → 期望 `product.card.render` **明显低于** ~1289ms，但非归零。
- **暖窗 / 同品跨位 / peer HIT**：分桶后 fragment HIT 率升 → 列表二次请求降幅更大。
- 固化主门（shell 直读）**不动**。

## UT

`ProductCardBatchRenderContractTest` + Catalog/Category/Storefront/ProductCard 契约 → **24 tests / 169 assertions OK**。

## escalate

@项目经理：9p 落地完成 → 可与 9s 一并后排 **9v** 复测（探针：`product.card.render` 相对 8v2 明显降）。禁本席自 reload；claim_sla=false。
