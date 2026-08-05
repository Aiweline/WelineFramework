# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::backend::partials::topbar::logo`
- **显示名称**：后台 Topbar Logo
- **功能说明**：覆盖后台顶部栏的 Logo 区域。默认由 Theme 实现：读取 SiteBrand/`logo_dark`/`logo_light`/`logo_sm`；`logo_sm` 为空时回退到 `resolveIconUrl()`（站点图标/主题默认 icon），不再回退 `logo_dark`。

## 使用方法

在模块的 `view/hooks/` 目录下按目录结构创建实现文件：

`view/hooks/Weline_Theme/backend/partials/topbar/logo.phtml`

例如 Weline_Backend 模块实现此 Hook，输出从 Backend 配置读取的 Logo（外观与 Logo 配置页）。
