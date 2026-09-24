# 领域探查 · PDP N+1 代码落点（只读）

- **席位**：Team:领域探查:
- **SESSION**：`pdp-n1-query-storm-20260923`
- **时间**：2026-09-23T23:50+08
- **性质**：只读落点映射；禁改业务码

---

## 落点表（相位 → 模块/类/方法）

| 相位 / 部件 | 模块 | 路径 · 符号 | 易 N+1 原因（摘要） |
|-------------|------|-------------|---------------------|
| `product.catalog.live_request` | Product | `Service/StorefrontCatalogViewService.php` · `livePublishedOffersForProduct()` → `buildPublishedOffers()`；入口 `Controller/Frontend/Detail.php` · `publishedOffersBySlug` / `publishedOffersForProduct` | 单商品全变体 live 投影（attrs/media/snapshots）；Slug 多候选时按 productId **循环**再调 live；库存侧可能逐 offer `getAvailability` |
| `theme.partials.fetch.header` | Theme | `Block/Partials.php` · `renderPartials()` → `measurePhase('theme.partials.fetch.header')`；模板 `view/theme/frontend/partials/header/default.phtml` | **包容相位**：页头 SSR 含搜索下拉、分类 nav、slots；子相位 SQL 常记在此桶，勿与子相位相加 |
| `storefront.category_tree.urls` | Product (+ Framework Url / UrlManager) | `Service/StorefrontCategoryTreeIndex.php` · `applyLocalizedNames()` → `Url::getFrontendUrls()` → 单链 rewrite / `SeoUrlGenerateRewrite::prefetch` | 冷树对每个分类 path 生成店面 URL；冷/miss 近似一 path 一查 |
| 搜索 type-dropdown（`com_type-dropdown` / `com_header-bar`） | Theme + Search + Product | 源模板 `Theme/.../partials/search/header-bar.phtml`、`type-dropdown.phtml`；`Helper/HeaderCommerceData::resolveSearchTypes()` → `SearchProviderRegistry::listTypes()` → `Product/.../ProductSearchProvider::listScopeOptions()` → `ProductSearchCategoryScopeService::listForSearch()` | Header 冷缓存拉全站类型+分类 scope；与导航分类树第二套读路径叠加 |
| `recently-viewed` | RecentlyViewed + Product | `RecentlyViewed/.../widgets/recently-viewed.phtml` → `RecentlyViewedService::cards()` | **经典 N+1**：`foreach` id 调 `livePublishedOffersForProduct`（limit≤24） |
| `you-may-like` | Product | `view/templates/frontend/widgets/you-may-like.phtml` → `StorefrontProductWidgetCatalog::youMayLikeCards()` → `sameCategoryCompanionOffers` + `relatedCards` | 分类链 + `publishedOffersForProductIds` + summary 填空；与主 PDP/其它部件重复投影 |
| `cross-sell` | Product | `view/templates/frontend/widgets/cross-sell.phtml` → `StorefrontProductWidgetCatalog::bundleCards()` → `relatedCards()` → `publishedOfferSummaries()` | 独立槽再跑货架摘要；与 live_request / you-may-like 重叠扇出 |

---

## 调用链（简）

```
Detail::index
  └─ StorefrontCatalogViewService::publishedOffersBySlug|ForProduct
       └─ livePublishedOffersForProduct  [product.catalog.live_request]

Partials::header  [theme.partials.fetch.header]
  ├─ header-bar → resolveSearchTypes → ProductSearchProvider scopes
  └─ 分类导航 → StorefrontCategoryTreeIndex::applyLocalizedNames
                 [storefront.category_tree.urls]

widgets
  ├─ recently-viewed → cards() ×N livePublishedOffersForProduct
  ├─ you-may-like → youMayLikeCards()
  └─ cross-sell → bundleCards()
```

---

## 探查要点（给 PM）

1. 最硬 N+1：`RecentlyViewedService::cards` 逐 ID live 投影。
2. 最大单相位扇出：`live_request` 多变体全量投影。
3. Header 双读：分类树 URL + 搜索 scope 树，叠在 `fetch.header`。
4. 部件 span 禁止相加算整页；禁拆 chrome/必装槽。

业务码：**未改**。
