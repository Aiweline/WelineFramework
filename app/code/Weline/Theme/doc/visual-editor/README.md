# 可视化编辑器

> 更新：2026-09-19  
> 旧 PageBuilder 壳（`GuoLaiRen\PageBuilder`、`/backend/visual/api/*`、`visual-editor.phtml`）已下线。不要再按那套接口开发。

后台入口是 `theme/backend/theme-editor`。画布打开**真实店面 path**，身份以 query + `editor_context` 为准。不要用 `theme-preview/content` 冒充画布，也不要调用 `start-preview`。

三态对照：[`../preview-and-runtime-modes.md`](../preview-and-runtime-modes.md)。

## 运行时结构

| 角色 | 权威源 | 浏览器加载的产物 |
|------|--------|------------------|
| 父页编辑器 | `view/statics/js/theme-editor.js` | `view/statics/ui/pages/weline-theme-editor.js` |
| iframe 编辑模式 | `view/statics/js/editor-mode.js` | `view/statics/ui/pages/weline-theme-preview.js` |

产物由 `php bin/w resource:compile welineUi` 生成。只改权威源，再编译。

## 放置规则

插槽用 `data-wslot*` 声明接受、拒绝、容量。父页与 iframe 共用同一套判断，服务端登记在 `Weline\Theme\Service\ThemePlaceableRegistry`。

属性说明：[`../widget-slot-attributes.md`](../widget-slot-attributes.md)。

选中插槽后，部件库按该插槽过滤（`setWidgetSlotFilter`），不是旧的 `/backend/visual/api/component/compatible`。

## 跨帧协议

父页与 iframe 的消息走预览帧邮箱，不按事件打补丁。见 [`preview-frame-bus.md`](./preview-frame-bus.md)。

## 局部更新

拖入、移动、删除部件后，父页改 iframe 里对应插槽的 DOM。已有部件改配置不得整页重载预览。找不到插槽时才 `loadCanvas()`。

## 相关文档

- [预览帧邮箱](./preview-frame-bus.md)
- [作用域切换](./scope-switching.md)
- [Weline UI 2.0 能力矩阵](./weline-ui-2-capability-matrix.md)
- [区域与接受规则](./region-isolation.md)
- [Slot](./slot-nesting-rules.md)
- [部件库筛选](./component-library-filtering.md)
- [局部更新](./partial-refresh.md)
- [契约测试](./testing.md)
