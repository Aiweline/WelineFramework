# 插槽接受范围

组件不能放到不接受它的插槽。判断不走已下线的 PageBuilder `SlotValidator`。

## 现网规则

插槽模板写：

- `data-wslot`
- `data-wslot-accept`（逗号分隔部件码，`*` 为不限制）
- `data-wslot-reject`
- `data-wslot-multiple` / `data-wslot-exclusive` / `data-wslot-max`

iframe `slotAcceptsWidget` 与父页 `isSlotDataAccepted` 必须一致。服务端登记：`Weline\Theme\Service\ThemePlaceableRegistry`。

属性表：[`../widget-slot-attributes.md`](../widget-slot-attributes.md)。

放不进去时热路径画 `drag-invalid`，冷路径发 `widget-rejected`。若当前选中的推荐插槽接受该部件，允许从拒绝的内层落到该插槽。
