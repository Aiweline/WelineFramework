# 主题编辑器壳 · body-end

- **Hook**：`Weline_Theme::backend::layouts::theme-editor::body-end`
- **用途**：主题编辑器专用布局 `</body>` 前；仅编辑器壳内扩展。
- **禁止**：挂载 `backend-shell-float`（建站助手等）。通用后台悬浮层只挂 `Weline_Theme::backend::layouts::base::body-end`；本布局刻意不引用 base body-end。
- **若需建站助手**：走编辑器工具栏入口或进度页深链，并带当前 `website_id`，不要在此 hook 挂 FAB。
