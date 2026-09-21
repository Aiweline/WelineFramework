# 部件库筛选

选中区域或插槽后，父页 `setWidgetSlotFilter` 用当前插槽向部件库查询重载，不是 `GET /backend/visual/api/component/compatible`。

## 行为

- 插槽模式点选插槽：库只保留该槽 `accept` 允许的部件。
- 部件模式忽略插槽选中，不改库过滤。
- 取消选中后清除过滤，恢复分页库。
- 搜索框展示当前插槽名。

实现：`view/statics/js/theme-editor.js` 的 `handleSlotSelected` / `setWidgetSlotFilter`。
