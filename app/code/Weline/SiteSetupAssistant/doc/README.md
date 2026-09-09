# Weline_SiteSetupAssistant

建站助手：按 `website_id`（含默认站 `0`）展示上线/迁站贴士与任务进度，深链到各模块配置。

## 挂载

- 经 `Weline_Theme::backend::layouts::base::body-end` hook 注入（与客服悬浮同模式），凡含该 hook 的后台壳（`default` / `dashboard` / `fullscreen` 等）均可看见；不写死在 Dashboard 模板。
- 模板：`view/templates/backend/widgets/site-setup-assistant-float.phtml`；部件登记见 `extends/module/Weline_Widget/.../widget.php`。

## 状态

- `0.1.1`：改为后台通用 `base::body-end` 挂载；去掉 Dashboard 硬编码 fetch。
- `0.1.0`：UI 原型为右下角**悬浮助手**（FAB + 面板）；展开/收起按站点写入 `localStorage`；全部完成后不渲染。面板布局可切 `?variant=A|B|C`。正式 Provider/进度表尚未落地。

## 文档

- 需求与验收以选定原型 + 后续 `doc/需求.md` 为准。
