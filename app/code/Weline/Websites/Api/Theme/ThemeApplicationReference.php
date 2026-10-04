<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Theme;

/** 使用方已校验的不可变 Theme 内容引用；不传递 Theme 内部模型。 */
final readonly class ThemeApplicationReference
{
    public function __construct(
        public int $themeId,
        public int $themeVersionId,
        public int $contentRevision,
        public string $versionOwnerScope,
        public string $versionOwnerStoreMode,
        public string $area,
    ) {
        // theme_id=0 + version/revision 0/0 = Theme 模块包默认；正 id 为目录主题。
        // 真实版本合法性由 Theme 公开契约校验。
        if ($themeId < 0 || $themeVersionId < 0 || $contentRevision < 0
            || trim($versionOwnerScope) === ''
            || !in_array($versionOwnerStoreMode, ['normal', 'dev', 'test'], true)
            || !in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException('website_theme_application_reference_invalid');
        }
    }

    public function toArray(): array
    {
        return [
            'theme_id' => $this->themeId,
            'theme_version_id' => $this->themeVersionId,
            'content_revision' => $this->contentRevision,
            'version_owner_scope' => $this->versionOwnerScope,
            'version_owner_store_mode' => $this->versionOwnerStoreMode,
            'area' => $this->area,
        ];
    }
}
