# 猜你喜欢

Hook: `Weline_Theme::frontend::layouts::product::you-may-like`

产品详情常显「猜你喜欢」槽扩展点。默认由 `Weline_Product::you-may-like` 经 `default_injections` 注入 `product-you-may-like`（同分类亲合 + 真实目录报价）；布局仅保留本 Hook，不再内嵌业务部件。

## Implementation

Contributing modules implement this hook under `view/hooks/` by mapping `::` to directories and keeping templates thin.
