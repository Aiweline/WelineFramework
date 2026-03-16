# 已废弃：请使用 layouts 目录

本目录（`frontend/pages/`）**不**符合 Weline Theme 的继承结构。

- **规范**：`app/design/WeShop/motor/` 对应继承 `app/code/Weline/Theme/view/theme/`，即 `motor/frontend/` 与 `Theme/view/theme/frontend/` 结构一致。
- **Theme 结构**：仅包含 `layouts/`、`widgets/`、`partials/`、`components/`、`assets/` 等，**没有** `pages/`。
- **当前约定**：页面级内容已迁移到 `frontend/layouts/` 下：
  - 首页：`layouts/homepage/default.phtml`
  - 商品列表：`layouts/category/default.phtml`
  - 商品详情：`layouts/product/default.phtml`

本目录下的文件保留仅作参考或兼容旧路由，新开发请直接使用 `layouts/`。
