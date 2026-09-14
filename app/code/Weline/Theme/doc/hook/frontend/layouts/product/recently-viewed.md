# 最近浏览

Hook: `Weline_Theme::frontend::layouts::product::recently-viewed`

产品详情常显「最近浏览」槽扩展点。默认由 `Weline_RecentlyViewed::recently-viewed` 经 `default_injections` 注入 `product-recently-viewed`；布局仅保留本 Hook，不再内嵌 Theme 业务部件。本槽位于个性化推荐常显区，不受 `showRelatedProducts` 门控。

## Implementation

Contributing modules implement this hook under `view/hooks/` by mapping `::` to directories and keeping templates thin.
