# Hook：header-wishlist-icon

在页头 `user-area` 插槽内展示收藏夹图标与数量。

## 默认店面路径（现行）

- 宿主：`Theme/view/theme/frontend/partials/header/default.phtml` 中 `<w:hook>header-wishlist-icon</w:hook>`
- Theme 不得内嵌非 `Weline_Theme` 的 `<w:widget name="wishlist-icon">`
- **不**为 `wishlist-icon` 声明 `default_injections`：应用 Tab 不再出现「推荐/+」，避免与 Hook 常驻顶栏冲突；手动拖入布局部件时 `SlotRenderer` 仍可用既有 markup 替换逻辑去重

## 实现

- 模板：`view/hooks/header-wishlist-icon.phtml`
- 主体复用：`view/theme/frontend/widgets/header/wishlist-icon/default.phtml`
- 顶栏顺序：货币槽之后 → **收藏** → 账户 → 订单 → 购物车
