# 基础布局面包屑之后

Hook: `Weline_Theme::frontend::layouts::base::breadcrumb-after`

在基础布局面包屑之后触发，允许模块注入导航辅助内容。

## Implementation

Contributing modules implement this hook under `view/hooks/` by mapping `::` to directories and keeping templates thin.
