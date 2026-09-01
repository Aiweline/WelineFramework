# Theme Hook 点位索引（常用 Top 30）

> 完整列表见 [Theme/hook.php](../hook.php) 与 `Theme/doc/hook/`。实现路径规则：`::` → `/`，根目录 `view/hooks/`。

| Hook 名 | 规约 doc | 典型实现路径 |
|---------|----------|-------------|
| `Weline_Theme::frontend::layouts::base::head-before` | [head-before.md](./hook/frontend/layouts/base/head-before.md) | `view/hooks/Weline_Theme/frontend/layouts/base/head-before.phtml` |
| `Weline_Theme::frontend::layouts::base::head-after` | [head-after.md](./hook/frontend/layouts/base/head-after.md) | `.../head-after.phtml` |
| `Weline_Theme::frontend::layouts::base::body-start` | [body-start.md](./hook/frontend/layouts/base/body-start.md) | `.../body-start.phtml` |
| `Weline_Theme::frontend::layouts::base::body-end` | [body-end.md](./hook/frontend/layouts/base/body-end.md) | `.../body-end.phtml` |
| `Weline_Theme::frontend::layouts::base::header-before` | [header-before.md](./hook/frontend/layouts/base/header-before.md) | `.../header-before.phtml` |
| `Weline_Theme::frontend::layouts::base::header-after` | [header-after.md](./hook/frontend/layouts/base/header-after.md) | `.../header-after.phtml` |
| `Weline_Theme::frontend::layouts::base::content-before` | [content-before.md](./hook/frontend/layouts/base/content-before.md) | `.../content-before.phtml` |
| `Weline_Theme::frontend::layouts::base::content-after` | [content-after.md](./hook/frontend/layouts/base/content-after.md) | `.../content-after.phtml` |
| `Weline_Theme::frontend::layouts::base::footer-before` | [footer-before.md](./hook/frontend/layouts/base/footer-before.md) | `.../footer-before.phtml` |
| `Weline_Theme::frontend::layouts::base::footer-after` | [footer-after.md](./hook/frontend/layouts/base/footer-after.md) | `.../footer-after.phtml` |
| `Weline_Theme::frontend::layouts::account::sidebar-before` | [sidebar-before.md](./hook/frontend/layouts/account/sidebar-before.md) | `view/hooks/Weline_Theme/frontend/layouts/account/sidebar-before.phtml` |
| `Weline_Theme::frontend::layouts::account::content-before` | [content-before.md](./hook/frontend/layouts/account/content-before.md) | `.../content-before.phtml` |
| `Weline_Theme::frontend::partials::header::logo` | [logo.md](./hook/backend/partials/topbar/logo.md) 等 header partials | `view/hooks/Weline_Theme/frontend/partials/header/logo.phtml` |
| `Weline_Theme::frontend::partials::header::cart` | [cart.md](./hook/frontend/header/cart.md) | `.../header/cart.phtml` |
| `Weline_Theme::frontend::partials::header::account` | [account.md](./hook/frontend/header/account.md) | `.../header/account.phtml` |
| `Weline_Theme::frontend::partials::header::search` | Theme hook.php `doc` 字段 | `.../partials/header/search.phtml` |
| `header-language-switcher` | [language-switcher.md](./hook/frontend/header/language-switcher.md) | 各模块 `view/hooks/.../language-switcher.phtml` |
| `header-currency-switcher` | [currency-switcher.md](./hook/frontend/header/currency-switcher.md) | 同上 |
| `Weline_Theme::frontend::partials::footer::brand` | [brand.md](./hook/frontend/partials/footer/brand.md) | `.../footer/brand.phtml` |
| `Weline_Theme::frontend::partials::footer::links` | [links.md](./hook/frontend/partials/footer/links.md) | `.../footer/links.phtml` |
| `Weline_Theme::frontend::partials::product-card::add-to-cart` | Theme hook.php | `.../product-card/add-to-cart.phtml` |
| `Weline_Theme::frontend::partials::product-card::buy-now` | [buy-now.md](./hook/frontend/partials/product-card/buy-now.md) | `.../buy-now.phtml` |
| `Weline_Product::frontend::product::detail::after-add-to-cart` | [Product doc](../Product/doc/hook/frontend/product/detail/after-add-to-cart.md) | `view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml` |
| `Weline_Theme::frontend::layouts::homepage::content-before` | `doc/hook/frontend/layouts/homepage/` | `view/hooks/Weline_Theme/frontend/layouts/homepage/content-before.phtml` |
| `Weline_Theme::frontend::layouts::cart::content-before` | `doc/hook/frontend/layouts/cart/` | 对应 layouts/cart 路径 |
| `Weline_Theme::frontend::layouts::checkout::content-before` | checkout layout hooks | 见 Theme/doc/hook/frontend/layouts/ |
| `Weline_Theme::frontend::account::sidebar` | [sidebar.md](./hook/frontend/account/sidebar.md) | Customer 等模块可挂载 |
| `Weline_Theme::frontend::account::sidebar-content` | [sidebar-content.md](./hook/frontend/account/sidebar-content.md) | 账户侧栏内容扩展 |
| `Weline_Theme::frontend::footer` | [footer.md](./hook/frontend/footer.md) | 页脚全局扩展 |
| `Weline_Theme::frontend::header::nav-links` | [nav-links.md](./hook/frontend/header/nav-links.md) | 导航链接扩展 |

## 新建 Hook 流程

见 [Hook创建规范.md](../../Hook/doc/Hook创建规范.md)：

1. Owner 模块 `hook.php` + `doc/hook/*.md`
2. 实现模块 `view/hooks/.../*.phtml`
3. `php bin/w setup:upgrade --route`

## 相关

- [Hook使用指南.md](./Hook使用指南.md)
- [AI硬规则索引.md](../../Ai/doc/AI硬规则索引.md)
