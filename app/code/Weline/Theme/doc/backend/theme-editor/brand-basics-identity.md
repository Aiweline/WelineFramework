# 主题编辑器「基础信息」身份槽

Hook：`Weline_Theme::backend::theme-editor::brand-basics::identity`

用于在主题编辑器「基础信息」Drawer 顶部挂载 Website / Store / Channel 等真实身份字段。

## 权威读写

- **Interface**：`Weline\Theme\Api\BrandBasicsIdentityProviderInterface`
- **注册**：`module.php` provides 键 `theme.brand_basics_identity.*`（或 Theme `extends/BrandBasicsIdentity`）
- **API**：`GET|POST /theme/backend/theme-editor/brand-basics-identity`
- **实现参考**：`Weline\Websites\Service\ThemeBrandBasicsIdentityProvider`

保存时必须写回模块权威实体（如 `Website.name`、`Store.name`、`SalesChannel.name`；网站简介同步 `Weline_Backend` `site_description`）。

## 非本槽职责

Favicon / Logo 仍走 Appearance `brand`（`/brand/favicon|…`），不写入 Website schema。
