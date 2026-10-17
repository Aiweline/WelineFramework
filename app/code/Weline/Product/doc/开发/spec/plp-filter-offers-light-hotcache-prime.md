# Spec: PLP filter / facet / offers light HotCache prime

status: delivered  
covers_use_cases: UC-plp-filter-offers  
plan_id: P-plp-filter-offers  
reconcile_plan: `plp-filter-soft-cold`（方向 A · contracts FROZEN 2026-10-17）  
parent_wave: `storefront-xscope-hotcache-wave`  
parent_spec: `storefront-xscope-hotcache-wave.md`

## 背景

soft-clear 后 PLP 冷 hit1 主税 filter / facet / offers 空袋（基线 020404：de filter+facet+offers≈531；ja offers≈493）。Clear 已 `warmUp('product')`（projection + shelf），但无 PLP 三袋 light warmer。

后续发现：soft `filteredListingPage` 消费 HotCache 投影段 `candidates-facet-listing-v5-max1000`（`publishedListingCandidates(1000, true)`），而早期 warmer 若只 seed summary/false 或 `max120` 类目片，则与 soft **不同键**，暖后仍会打出 facet 袋冷建。`plp-filter-soft-cold` 方向 A 将 tip 与 facet 同键对齐（本规格 2026-10-17 耐久 reconcile）。

## 方案

| mechanism | owning_module | not_to_do |
|-----------|---------------|-----------|
| `PlpFilterOffersLightCacheWarmer` provides `cache.warmer.product.plp_light`，pool=`product`，priority=235；Clear 既有 `warmUp('product')` 自动发现 | `Weline_Product` | 勿混 chrome；禁 publishedOffers(1000)/full×locales；禁拆壳；Clear 禁 Product FQCN soft-pull；禁只扩 tip 不 seed facet true/同 N |
| warm：对 tip 矩阵 seed **同 soft 键** facet listing（`includeDetails=true`，`N=1000`）+ summary offers + Filters `buildPanel` + facet；有界类目一条仍 `CATEGORY_TIP_LIMIT=120`（**不**冒充 soft 键） | `Weline_Product` | 禁改买家可见排序/筛选语义；禁业务 static / 平行袋；禁 SSR 默认改 `includeDetails=true`（方向 B 另宣） |

### Tip 矩阵（硬 · 对齐 contracts §3）

| 顺序 | lang × currency | 作用 |
|---:|---|---|
| **1（优先）** | **`zh_Hans_CN` × `USD`** | shopper 默认 tip；`BUDGET_MS` 内优先 seed |
| 2 | `de_DE` × `USD` | 兼容 tip（可保留） |
| 3 | `ja_JP` × `GBP` | 兼容 tip（可保留） |

对**每一个** tip（至少含 zh×USD）：必须 `publishedListingCandidates(FACET_LISTING_LIMIT=1000, true)`，与 soft 期望 logical 投影段 **`candidates-facet-listing-v5-max1000` 同键**。  
禁止：仅 de/ja、仅 `includeDetails=false`、或仅 `max120` 冒充 soft facet 袋。

### HotCache 键对照（硬 · 对齐 contracts §2）

| 消费者 | API | includeDetails | max | logical 投影段 |
|---|---|---|---:|---|
| soft `filteredListingPage` | `publishedListingCandidates` | **true** | **1000** | `candidates-facet-listing-v5-max1000` |
| warmer `product.plp_light`（门禁 tip） | 同上 | **true** | **1000** | **同键** |
| SSR `/products`（默认） | 同上 | false | 1000 | `candidates-summary-card3-max1000`（本波不冒充 soft） |

## 细节

- **无新增对外 REST/Query 契约**——仅内部 warmer；`filteredListingPage` 响应形状不变。
- 暖窗 memory≥512M；预算诚实 warm_partial / remaining；notes 可见 `facet_listing_limit=1000`、`facet_listing_details=1`、`facet_seeded:{lang}:{currency}`。
- Registry：仍 `cache.warmer.product.plp_light`。
- 源码：`Service/PlpFilterOffersLightCacheWarmer.php`（`TIP_SCOPES` 首项 zh×USD；`FACET_LISTING_LIMIT=1000`）。
- 契约：`Test/Unit/Service/ProductServiceContractsTest.php`（`testWarmerSeedsSoftSameFacetKeyAndShopperTipFirst`）及既有 `PlpFilterOffersLightCacheWarmerContractTest.php`。
- 验收指针（历史 xscope 波）：`A-plp-filter-offers`；安静窗冷 hit1 无 filter/facet/offers 主导空袋 builder（`meetings/性能检查-review.md` · dig quiet2/quiet3）。
- 验收指针（soft-cold 方向 A · G-A*）：见下节；issuer=`性能检查工程师`；证据 team `dev/team/plp-filter-soft-cold/meetings/性能检查-issuer-acceptance.md`。

## UC / Gate（跟 contracts G-A*）

- **UC-plp-filter-offers**（xscope 继承）：soft-clear 后对冻结 PLP tip 安静窗 cold hit1 → 无主导 filter/facet/offers 空袋 builder（或 exclusive≪基线），且不与 chrome 混袋。**最低必验 tip 须含 shopper 默认 `zh_Hans_CN×USD`**；兼容 tip 可保留 de/ja。
- **UC-tip-matrix**：warmer tip 首项为 zh×USD，且对该 tip（及兼容 tip）seed facet=`true` + N=1000 同 soft 键。
- **G-A1 / UC-cold-first-filter**：clear product+eav → warm `plp_light` → 首次 soft（zh×USD）墙钟 **≤1500ms**。
- **G-A2 / UC-warm-sticky**：二次 soft **≤200ms**。
- **G-A3**：无修前级 ~6s `build_candidates` facet 冷建。
- **UC-filters-not-regress**：袋在手计数仍几十 ms 量级（本规格不改 Filters 语义）。

禁止伪加速比（暖 vs 冷混比）；禁拆壳达成。方向 B（SSR→soft 同键）/ C（投影批量化）不在本 warmer 规格施工范围。
