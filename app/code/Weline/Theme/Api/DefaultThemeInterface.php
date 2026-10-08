<?php

declare(strict_types=1);

namespace Weline\Theme\Api;

/**
 * Theme 模块即系统全局默认主题权威（view/theme），始终可用，不依赖库表注册。
 * Env / Websites 不得另持一份默认主题身份。
 * 目录里若有「Default 默认主题」行，仅作编辑/版本 catalog id（任意正整数，绝非固定 id=1）；
 * 无目录行时 theme_id=0 表示模块包默认（package_defaults）。
 */
interface DefaultThemeInterface
{
    public const REGISTERED_NAME = 'Default 默认主题';
    public const REGISTERED_MODULE = 'Weline_Theme';
    /** 公开命名空间与相对 path（与主题安装登记一致）。 */
    public const REGISTERED_RELATIVE_PATH = 'Weline/Theme/view/theme';
    public const MODULE_ORIGIN = 'Weline_Theme::view/theme';
    /** 无目录行时的应用引用 theme_id（模块包默认，不是库主键）。 */
    public const MODULE_DEFAULT_THEME_ID = 0;

    /**
     * @return array{
     *   id:int,
     *   name:string,
     *   module_name:string,
     *   path:string,
     *   parent_id?:int|null,
     *   is_active?:int|bool,
     *   is_active_frontend?:int|bool,
     *   is_active_backend?:int|bool,
     *   source?:string
     * }
     */
    public function getRegisteredDefault(?string $area = null): array;

    /**
     * 无网站/范围应用引用时的 package_defaults 应用引用（theme_version_id=0, content_revision=0）。
     * theme_id：有目录行则用其 id；否则 MODULE_DEFAULT_THEME_ID（0）。
     *
     * @return array{
     *   theme_id:int,
     *   theme_version_id:int,
     *   content_revision:int,
     *   owner_scope:string,
     *   store_mode:string,
     *   area:string,
     *   source_kind:string
     * }
     */
    public function defaultApplicationReference(string $area, string $ownerScope, string $storeMode): array;

    /** 是否为 Theme 模块全局默认（theme_id=0 或目录中的 Default 行）。 */
    public function isModuleDefaultThemeId(int $themeId): bool;

    /**
     * 布局实体（generated/theme-layout-entities）目录使用的 catalog theme_id。
     * 模块包默认（虚拟 id≤0 或磁盘 path）回落到目录 Default 行；否则返回当前主题 id。
     */
    public function resolveLayoutEntityCatalogThemeId(\Weline\Theme\Model\WelineTheme $theme): int;
}
