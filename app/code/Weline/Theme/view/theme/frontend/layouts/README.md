# Frontend Layouts

Full layout extension guide: `app/code/Weline/Theme/doc/layout-discovery-guide.md`.

Discovery precedence: active `app/design` theme chain -> `Weline_Theme/view/theme` -> module `view/theme` contributions. Module contributions can add new layouts but cannot override `Weline_Theme` defaults; `app/design` themes can override defaults. Adjacent `*.layout.json` files follow the same precedence.

前端布局按 `layouts/<layoutType>/<option>.phtml` 组织，`layoutType` 由控制器声明，
`option` 可以来自代码显式指定，也可以来自主题配置和预览 scope。

## 命名与解析规则

- 控制器只传布局类型
  - 例如 `layoutType = homepage`
  - 实际 option 由主题配置决定，未配置时回退到 `default`

- 控制器显式传类型和选项
  - 例如 `layoutType = account.auth`
  - 解析后类型为 `account`，选项固定为 `auth`

- 模板元数据
  - 布局文件顶部应包含 `@meta.name`、`@meta.description`、`@param.*`
  - 运行时会注入 `meta`、`theme`、`colors`、`contentTemplate`

- 通用结构
  - `head` partial 负责加载主题 CSS/JS 和生成的布局 CSS
  - `header` / `footer` / `breadcrumb` 等公共片段优先通过 `Weline\Theme\Block\Partials` 引入
  - 可编辑布局使用 `<w:slot>` 暴露插槽

## Theme 壳层 vs 模块贡献

`Weline_Theme/view/theme/frontend/layouts` **只保留站点通用壳层**。业务页（账户、购物车、结算、商品、搜索、博客、FAQ 等）由对应模块的 `view/theme/frontend/layouts` 贡献；下列「全站可发现清单」含模块贡献，并不表示文件仍在 Theme 目录。

### Theme 目录内（本模块）

- `default` / `blank` / `homepage`
- `about` / `terms` / `guide` / `qa` / `activity`
- `policy`（`default`、`cookie`、`privacy`、`term-condition`、`refund`、`disclaimer`、`shipping`、`accessibility`）
- `not_found` / `error` / `sitemap`
- `test`

### 模块贡献（示例）

- `account/*`、`contact` → Weline_Customer
- `cart`、`mini-cart` → Weline_Cart
- `checkout*` → Weline_Checkout
- `product`、`category`、`products` → Weline_Product
- `search` → Weline_Search
- `blog*` → Weline_Blog
- `cms_page` → Weline_Cms
- `faq` / `payment_guide` / `promotion` / `review` / `rma` → 各自模块

## 当前可发现布局清单

- `account`
  - `auth`
  - `challenge`
  - `dashboard`
  - `default`
  - 嵌套 path 对齐（`layoutType=account/{action}`，`option=default`；由 Weline_Customer 贡献）：
    - `login/default`
    - `register/default`
    - `forgot-password/default`
    - `set-password/default`
    - `social-login/default`
    - `logout/default`
    - `orders/default`
    - `profile/default`

- `blank`
  - `full`

- `activity`
  - `default`

- `about`
  - `default`

- `terms`
  - `default`

- `cart`
  - `default`
  - `empty`

- `category`
  - `default`
  - `list`

- `checkout`
  - `default`
  - `one-page`

- `checkout/failure`
  - `default`

- `checkout/success`
  - `default`

- `cms_page`
  - `default`

- `default`
  - `default`

- `error`
  - `default`

- `homepage`
  - `default`
  - `minimal`

- `policy`
  - `cookie`
  - `default`
  - `disclaimer`
  - `privacy`
  - `refund`
  - `term-condition`
  - `shipping`
  - `accessibility`

- `product`
  - `default`

- `search`
  - `default`

- `sitemap`
  - `default`

- `blog`
  - `default`

- `blog_category`
  - `default`

- `not_found`
  - `default`

- `test`
  - `assets-test`

## 维护建议

- 新增布局时，先定好 `layoutType` 和 `option`，不要混用历史命名。
- 业务布局放业务模块；Theme 只加真正跨站通用的壳。
- 修改布局 README 时，以目录现状和 `ControllerFetchFileBefore` / `LayoutResolveObserver` 的解析规则为准。
- 跨布局复用的样式/脚本放 `assets/` 或公共 partial，不要在多个布局里复制相同实现。
