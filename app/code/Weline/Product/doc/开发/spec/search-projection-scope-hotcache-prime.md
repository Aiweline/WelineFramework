# Spec: search projection scope HotCache prime

status: ready-for-plan  
covers_use_cases: UC-proj-1a, UC-proj-1b, UC-proj-2

## 背景

冷切矩阵 tip（`ar_SA/search` ≥2.5s）独占 builder：`search.query_result` ~1.2s 内嵌 / 并列 `product.search_projection_scope` ~0.57s（l1/l2 absent）。安静窗同 URL ~0.5s → 类别②空袋首触。catalog / widget_asset 已修未回退。

## 方案

| mechanism | owning_module | not_to_do |
|-----------|---------------|-----------|
| `SearchProjectionScopeCacheWarmer` + `cache:clear` → `warmUp('product')`：freeze 默认 channel 后 `snapshotScope` 写 durable L2 | `Weline_Product` | 不 `snapshotWebsite`；不预热高基数 `search.query_result` 全 q（tip 有界种子归 **Search** `SearchQueryResultTipCacheWarmer` / `warmUp('search')`，见 Search README「店面 tip 查询结果袋预热」） |
| `StorefrontHotCacheBagSeeder::primeSearchProjectionScope` peek 后代建（critical / locale / currency / peer / post_critical / 空 stage） | `Weline_Product` | 不 full×locales；不把 publishedOffers(1000) 塞进 light |
| Framework Clear 只 `warmUp('product')`，禁 soft-pull Product FQCN | `Weline_Framework` | 禁拆 chrome；禁平行 static 袋 |

## 细节

- Policy：`product.search_projection_scope`，pool=`product`，scope=`channel`，无 lang vary。
- 默认站 store/channel id=`0`/`0`（与店面 RequestContext 对齐）。
- 验收：soft-clear 后 `/ar_SA/search?q=hanfu&currency=USD&wls_tpl_perf=1` hit1 无 `search_projection_scope` builder 主导；墙钟相对修前矩阵 tip 下降。

## UC

- **UC-proj-1a** WHEN soft-clear 后 warmer 成功 THEN product 池 projection 袋 L2 已种（warmed≥1 或 peek_hit）。
- **UC-proj-1b** WHEN soft-clear 后首击 ar_SA search THEN timing 无 exclusive `product.search_projection_scope` builder ≥400ms。
- **UC-proj-2** WHEN bag seeder stage 允许 THEN peek miss 可代建；peek hit 不计 seeded。
