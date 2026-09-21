# 局部更新

添加、移动、删除、保存部件配置时，只改 iframe 里受影响的节点。不要整页重载画布，也不要调用已下线的 `POST /backend/visual/api/component/add`。

## 行为

- 新部件：按 `sort_order` 插入对应 `[data-wslot]`，绑定已有的 body 级拖拽委托。
- 已有部件改配置：就地更新。找不到节点时打警告，**不** `loadCanvas()`。
- 只有新部件且插槽重试后仍不存在，才刷新画布。
- 删除：`restoreSlotContentAfterWidgetRemoval` 只拿掉部件；有 `original_html` 且槽内确无残留时才恢复模板原文。禁止回填「插槽原本为空」一类占位句。

实现：`view/statics/js/theme-editor.js`。
