# Spec: PLP filter / facet / offers light HotCache prime

status: delivered  
covers_use_cases: UC-plp-filter-offers  
plan_id: P-plp-filter-offers  
parent_wave: `storefront-xscope-hotcache-wave`  
parent_spec: `storefront-xscope-hotcache-wave.md`

## 背景

soft-clear 后 PLP 冷 hit1 主税 filter / facet / offers 空袋（基线 020404：de filter+facet+offers≈531；ja offers≈493）。Clear 已 `warmUp('product')`（projection + shelf），但无 PLP 三袋 light warmer。

## 方案

| mechanism | owning_module | not_to_do |
|-----------|---------------|-----------|
| `PlpFilterOffersLightCacheWarmer` provides `cache.warmer.product.plp_light`，pool=`product`，priority=235；Clear 既有 `warmUp('product')` 自动发现 | `Weline_Product` | 勿混 chrome；禁 publishedOffers(1000)/full×locales；禁拆壳；Clear 禁 Product FQCN soft-pull |
| warm：listing candidates + summary offers + Filters `buildPanel` + facet；有界类目一条；冻结 tip 矩阵 de_DE/USD · ja_JP/GBP | `Weline_Product` | 禁改买家可见排序/筛选语义 |

## 细节

- **无新增对外 REST/Query 契约**——仅内部 warmer。
- 暖窗 memory≥512M；预算诚实 warm_partial / remaining。
- 验收指针：波 `storefront-xscope-hotcache-wave` · `A-plp-filter-offers`；安静窗冷 hit1 无 filter/facet/offers builder（`meetings/性能检查-review.md` · `test-exec.md` · dig `dev/tmp/perf-xscope-accept-20261011-025246-quiet2.json` / `quiet3-retry.json`）。
- 契约：`Test/Unit/Service/PlpFilterOffersLightCacheWarmerContractTest.php`。

## UC

- **UC-plp-filter-offers** WHEN soft-clear 后对冻结 PLP tip（de/ja）安静窗 cold hit1 THEN 无主导 filter/facet/offers 空袋 builder（或 exclusive≪基线），且不与 chrome 混袋。
