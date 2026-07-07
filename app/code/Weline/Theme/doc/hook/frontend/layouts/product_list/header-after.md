# 产品列表布局页头之后

Hook: `Weline_Theme::frontend::layouts::product-list::header-after`

在产品列表布局页头之后触发，允许模块注入横幅、提示或布局辅助内容。

## Implementation

Contributing modules implement this hook under `view/hooks/` by mapping `::` to directories and keeping templates thin.
