# 主题编辑器壳 · head-before

- **Hook**：`Weline_Theme::backend::layouts::theme-editor::head-before`
- **用途**：主题编辑器专用布局 `<head>` 起始处；仅编辑器壳相关资源。
- **禁止**：挂载后台通用悬浮层（建站助手等须走 `backend::layouts::base::body-end`，本布局不引用该 hook）。
