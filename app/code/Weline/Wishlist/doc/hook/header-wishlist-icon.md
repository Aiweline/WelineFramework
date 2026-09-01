# Hook：header-wishlist-icon

在页头 `user-area` 插槽内展示收藏夹图标与数量。

## 实现

- 模板：`view/hooks/header-wishlist-icon.phtml`
- 宿主：`Theme/view/theme/frontend/partials/header/default.phtml` 中 `<w:hook>header-wishlist-icon</w:hook>`
- 主体复用：`view/theme/frontend/widgets/header/wishlist-icon/default.phtml`
- 顶栏顺序：货币槽之后 → **收藏** → 账户 → 订单 → 购物车

## 说明

Theme 布局/partials 不得内嵌非 `Weline_Theme` 的 `<w:widget>`；收藏入口由本模块 Hook 交付，满足 `frontend-theme-layout-widget-owner` 约束。
