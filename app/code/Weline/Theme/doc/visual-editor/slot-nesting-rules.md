# Slot 嵌套

嵌套由布局模板上的 `data-wslot` 决定，不是 `component.json` 的 PageBuilder slots，也不是 `POST /backend/visual/api/component/validate`。

## 规则

- 子插槽跟随父插槽所在区域；`accept` 只能比父级更窄。
- `container:<layout_id>` 是内部辅助 id，不可放置。
- 命中时从指针下最深插槽往外找第一个 accept 且未满的槽，避免外层 header 吞掉内层。
- 父页已选中的推荐插槽在指针下时优先。

属性与示例：[`../widget-slot-attributes.md`](../widget-slot-attributes.md)。
