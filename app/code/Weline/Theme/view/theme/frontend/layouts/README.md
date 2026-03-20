# Frontend Layouts

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

## 当前布局清单

- `account`
  - `auth`
  - `default`

- `account_auth`
  - `default`

- `account_logout`
  - `default`

- `account_orders`
  - `default`

- `account_profile`
  - `default`

- `activity`
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

- `checkout_failer`
  - `default`

- `checkout_success`
  - `default`

- `cms_page`
  - `default`

- `default`
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

- `product`
  - `default`

- `product_list`
  - `default`

- `test`
  - `assets-test`

## 维护建议

- 新增布局时，先定好 `layoutType` 和 `option`，不要混用历史命名。
- 修改布局 README 时，以目录现状和 `ControllerFetchFileBefore` 的解析规则为准。
- 跨布局复用的样式/脚本放 `assets/` 或公共 partial，不要在多个布局里复制相同实现。

