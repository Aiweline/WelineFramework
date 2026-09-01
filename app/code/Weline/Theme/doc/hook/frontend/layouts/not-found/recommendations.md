# 404 推荐产品

Hook: `Weline_Theme::frontend::layouts::not-found::recommendations`

在 404 页面推荐产品区域触发。

## Implementation

Contributing modules implement this hook under `view/hooks/` by mapping `::` to directories and keeping templates thin.

默认由 `Weline_Product::recommended-products` 通过 `default_injections` 注入 `not-found-recommendations` 槽；静态 404 HTML 在 `setup:upgrade` 时生成并写入 `pub/errors/storefront-not-found/`。

产品卡片链接必须使用根相对路径（如 `/product/{slug}`，非默认语种可带 locale 前缀 `/ja_JP/product/{slug}`），禁止 `product/...` 相对路径或 CLI 下空 host 拼出的 `http://product/...`，否则嵌套 404 URL 会解析错误。

404 布局须包含 `Weline_Theme::frontend::layouts::base::body-end`，以便注入 `Weline_Cart` 的 `product-purchase-actions.js` 与 `Weline_Theme` 的 `storefront-shopper-toast.js`；推荐卡片「加入购物车」须使用 Cart 契约（`weline-cart-product-card-add-to-cart` + `data-action="add-v2"` + `data-global-offer-uuid`）。

404 布局 `<html>` 须带 `data-w-area="frontend"`（与其它前台布局一致），否则 `storefront-shopper-toast.js` 不会初始化 `Weline.ShopperNotice`，加购成功后的 Amazon 风格右上角弹窗无法定位显示。
