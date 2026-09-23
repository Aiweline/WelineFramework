# PM 安排：发布槽拼装是否系统级、为何布局间不一致

## 用户原问
拼装不是系统级处理的吗？为什么某一个/每一个布局都不一样？

## 已核实证据（父会话）
1. Filters 经 JSON default_injections → products:`list-filters` / category:`category-filters`（跨模块合法路径）。
2. 布局模板故意留 `slot-placeholder` 文案，不是筛选本体。
3. 店面 `is_active_frontend`=theme 1；products 实体 `b5e7a8f02e3b88a9/r279` 结构已有 category-filters；CLI WidgetRenderer 可出 `w-filters`。
4. 线上 `/products` HTML：无 `data-wslot` / `w-filters` / `theme-layout-entity-slot`，仅占位。
5. `ThemeLayoutEntitySlotFiller::fill` wave8-8s：published 且无 `data-wslot` → **直接 return**（假定壳已烘焙）。
6. `LayoutSlotRenderer::shouldForcePublishedZeroRuntimeFill` → 跳过运行时 fill。

## 请各席只读探查后写 channel 回帖（勿改生产码本波）
- 架构师：系统级拼装管线一张图；「布局不一样」是算法分叉还是烘焙结果分叉？
- 部件：default_injections 是否系统统一；有无按布局特判？
- 主题：published bake 何时把部件写进壳；为何 products 壳仍带占位却无 marker？

notify_pm: true @项目经理
