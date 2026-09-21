# 可视化编辑器契约

PageBuilder 的 `SlotValidatorTest` / `ComponentRendererTest` 已随旧壳下线。当前锁定编辑器行为的是 Theme 单元契约，不是浏览器手工点选。

## 必跑

```bash
php bin/w phpunit app/code/Weline/Theme/test/Unit/ThemeEditorUiCapabilityContractTest.php
php bin/w resource:compile welineUi
```

`testVisualPreviewDragDropKeepsInsideBeforeAndAfterFeedback` 锁定预览帧邮箱：热车道、`notifyParent: false`、父页按 `lane` 打日志。它不再锁定 8ms debounce。

放置登记：`ThemePlaceableRegistryAcceptAliasTest`、`ThemePlaceableRegistryLayoutSupportTest`。

改 `js/theme-editor.js` 或 `js/editor-mode.js` 后必须编译 `welineUi`，再跑上述契约。不要手改 `ui/pages/*.js`。
