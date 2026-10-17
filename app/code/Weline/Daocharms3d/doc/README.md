# DaoCharms 三维交互模块

为 **design 主题** `app/design/Weline/daocharms-3d` 提供三维市集场景：模块拥有 Widget + 目录投影 Service；**禁止**再挂全局 `base::body-end` / `base::head-before`（主题绑定场景不侵入全站 Hook）。

## 权威落点

| 层 | 路径 |
|---|---|
| 目录投影 | `Service/TownCatalogProjector.php`（`product_storefront` Query） |
| 部件 | `view/templates/frontend/widgets/daocharms-town.phtml`（无 `default_injections`） |
| 挂载 | `app/design/Weline/daocharms-3d/frontend/layouts/homepage/default.phtml` 显式 `<w:widget name="daocharms-town" />` |
| SEO search 模板 | design `partials/head/assets-suffix.phtml` → `SeoPageProfileBag` |
| JS | `view/statics/js/bootstrap.js` + `town.js`（`daocharmsTown` module） |

目录/搜索/规格/购物车/账户/订单仍归原模块；不存在模拟订单与独立购物车。三维商品形状是示意模型，不保证背面、尺寸与实物一致，原图与真实商品详情保留。
