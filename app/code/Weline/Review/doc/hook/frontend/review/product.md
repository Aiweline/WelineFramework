# 商品评论 Hook

Hook：`Weline_Review::frontend::layouts::product-reviews::content`

当前商品详情通过 `storefront_offer.global_offer_uuid` 传入实体标识。主题可在同名 Hook 路径重写整体列表和表单，但不得绕过 Review Provider 的字段与媒体校验。

商品 Provider 默认注册总体、质量、交付、服务四项必填 `rating` 字段。默认模板根据 Provider schema 自动生成可点击、可触摸、可键盘操作的原生单选星级组，不渲染下拉框；提交时后三项存入 `extra_json`，公开列表按相同 schema 标签回显。

其他评论类型的拥有模块可在自己的 Provider `fields()` 中追加本类型评分项，并须在 `normalizeValues()` 的 `extra` 中返回扩展值。主题可重写同名 Hook 表现，但仍须提交相同字段 code，并由 Provider 在服务端校验必填、类型及取值范围。
