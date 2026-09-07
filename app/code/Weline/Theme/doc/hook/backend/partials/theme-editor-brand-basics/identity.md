# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::backend::partials::theme-editor-brand-basics::identity`
- **显示名称**：主题编辑器基础信息 · 身份槽
- **功能说明**：在主题编辑器「基础信息」Drawer 顶部挂载 Website / Store / Channel 等真实身份字段；权威读写走 `BrandBasicsIdentityProviderInterface` 与 identity API，不写入 `appearance.brand`。

## 使用方法

在模块的 `view/hooks/` 目录下按目录结构创建实现文件：

`view/hooks/Weline_Theme/backend/partials/theme-editor-brand-basics/identity.phtml`

例如 Weline_Websites 模块实现此 Hook，输出与当前 Scope 匹配的网站/店铺/渠道名称与简介字段。

## 相关

- Interface：`Weline\Theme\Api\BrandBasicsIdentityProviderInterface`
- API：`GET|POST /theme/backend/theme-editor/brand-basics-identity`
- 实现参考：`Weline\Websites\Service\ThemeBrandBasicsIdentityProvider`
