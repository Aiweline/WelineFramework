# 商品评论 Hook（兼容）

Hook：`Weline_Review::frontend::layouts::product-reviews::content`

**主交付路径**：商品详情布局只提供 `product-reviews` 容器槽；`Weline_Review` 通过 `product-reviews` 部件的 `default_injections` 应用内嵌注入，布局不得直接写 `<w:widget>`。Hook 模板仅为兼容 shim，内部复用同一部件模板。

当前商品详情通过页面上下文的 `storefront_offer.global_offer_uuid` 传入实体标识。主题可覆盖部件模板 `Weline_Review::templates/frontend/widgets/product-reviews.phtml`（或同名 Hook），但不得绕过 Review Provider 的字段与媒体校验。

商品 Provider 默认注册总体、质量、交付、服务四项必填 `rating` 字段。默认脚本根据 Provider schema 自动生成可点击、可触摸、可键盘操作的原生单选星级组，不渲染下拉框；提交时后三项存入 `extra_json`，公开列表按相同 schema 标签回显。

默认 UI 使用主题 CSS 变量（如 `--color-bg-primary`、`--color-accent`、`--radius-md`），不绑定行业定制深色皮肤。
