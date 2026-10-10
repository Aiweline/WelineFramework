# Spec: homepage shelf + offers locale HotCache prime

status: ready-for-plan  
covers_use_cases: UC-shelf-offers-warm  
plan_id: P-product-shelf-warm  
contract: C-product-shelf-offers  
parent_spec: `Weline_Theme/doc/开发/spec/chrome-warmer-l2-shelf-locale-warm.md` §4 / UC-shelf-offers-warm

## 背景

soft-clear 后 home quiet cold 对 `product.homepage_shelf_id_plan` 与 `catalog_offers_summary` / `_lang` 空袋冷建合计约 0.20–0.35s。Clear 已 `warmUp('product')` 但仅挂 search projection warmer。

## 方案

| mechanism | owning_module | not_to_do |
|-----------|---------------|-----------|
| `HomepageShelfOffersLocaleCacheWarmer` provides `cache.warmer.product.homepage_shelf_offers_locale`，pool=`product`，priority≈230；Clear 既有 `warmUp('product')` 自动跑 | `Weline_Product` | 勿与 chrome 混袋；禁 publishedOffers(1000)/full×locales；禁拆 featured widgets |
| warm：`homepage_shelf_id_plan` 一次；启用语×USD → `publishedOfferSummaries(48)`（间接 `_lang`）；peek L2 进 message；诚实 remaining | `Weline_Product` | Theme/Clear 禁 soft-pull Product FQCN / BagSeeder |

## 细节

- Policy：`product.homepage_shelf_id_plan`（website，vary=[]）；`product.catalog_offers_summary`（channel，lang×currency）；`product.catalog_offers_summary_lang`（website×lang）。
- 语种源：`WebsiteLanguage::getWebsiteLanguageCodes`；验收探针至少 fr_FR/it_IT/es_ES ∩ 启用。
- message 须含 `warmed_l2_hit` / `remaining`（或等价）；禁仅 seed 计数当 PASS。
- Clear：`reportWarmUpDetails` 须透出本 warmer 独立行（禁聚合吞 message）；暖窗 memory≥512M；L2 回证须 `purgeProcessCacheForLogicalKey` 后再 peek（禁仅进程 L1）。

## UC

- **UC-shelf-offers-warm** WHEN soft-clear 后对启用语 home quiet cold hit1 THEN 三袋 exclusive 合计 &lt;0.12s 或均无大空袋 builder（E-P1/E-P2）。
