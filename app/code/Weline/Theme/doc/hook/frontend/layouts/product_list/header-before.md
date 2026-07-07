# 产品列表布局页头之前

Hook: `Weline_Theme::frontend::layouts::product-list::header-before`

在产品列表布局页头之前触发，允许模块注入公告、导航辅助或埋点。

## Implementation

Contributing modules implement this hook under `view/hooks/` by mapping `::` to directories and keeping templates thin.
