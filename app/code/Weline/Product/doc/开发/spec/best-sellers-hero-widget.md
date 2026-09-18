# Spec：热销榜页头部件 `best-sellers-hero`

## 背景

`/best-sellers` 深色页头原写死在控制器模板内，主题编辑器无法配置文案与背景图。

## 需求（EARS）

- WHEN 商家打开热销榜布局编辑器，THE SYSTEM SHALL 将页头作为 `best-sellers-hero` 部件展示，并允许修改眉题、标题、导语、件数模板、是否显示件数、背景图。
- WHEN 热销榜布局渲染（店面或编辑器预览），THE SYSTEM SHALL 在 `best-sellers-hero` 槽内仅输出一枚页头（模板内嵌与布局 CoW 不得叠成双卡）。
- WHEN 主题编辑器渲染 `best_sellers` 布局画布，THE SYSTEM SHALL 在 `best-sellers-hero` 槽内只展示**一份**页头（不得同时保留模板壳与无 `template_ref` 的布局注入行）。
- WHEN 未上传背景图，THE SYSTEM SHALL 使用与现网一致的深色渐变页头。
- WHEN 已上传背景图，THE SYSTEM SHALL 以 `cover` 铺满页头，并叠一层暗遮罩以保证白字可读。
- WHEN `show_count` 为真且榜单件数大于 0，THE SYSTEM SHALL 用 `count_template` 中的 `%{1}` 替换为实际件数。
- WHEN 件数大于 0 且模板无 `%{1}`，THE SYSTEM SHALL 原样显示配置文案。
- WHEN 件数为 0，THE SYSTEM SHALL 显示「暂无热销商品」而非模板数字行。

## 用例

### UC-1 默认店面

1. 访客打开 `/best-sellers`。
2. 可见默认眉题「本站人气排行」、标题「热销榜」、默认导语与件数行（或暂无）。
3. 下方为控制器渲染的商品网格。

### UC-2 主题编辑改配

1. 商家在主题编辑器选中 `best-sellers-hero`。
2. 修改标题与上传 1920×400 背景图并保存。
3. 店面页头反映新文案与背景，商品网格不变。

## 验收

- 契约：部件注册、布局槽 `best-sellers-hero`、`index.phtml` 无内联 `.best-sellers-page__hero`。
- Browser：`/best-sellers` 页头可读；编辑器参数面板含背景图与文案字段。
