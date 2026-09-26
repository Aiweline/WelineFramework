<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Unique published/draft selection per version owner.
 * Owner = (theme_id, scope, store_mode, area).
 */
#[Table(comment: 'Theme scope version selection (published/draft authority)')]
#[Index(name: 'uk_theme_scope_version_selection_owner', columns: ['theme_id', 'scope', 'store_mode', 'area'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_scope_version_selection_published', columns: ['published_version_id'])]
#[Index(name: 'idx_theme_scope_version_selection_draft', columns: ['draft_version_id'])]
final class ThemeScopeVersionSelection extends Model
{
    public const schema_table = 'theme_scope_version_selection';
    public const schema_primary_key = 'selection_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Selection ID')]
    public const schema_fields_ID = 'selection_id';
    #[Col(type: 'int', nullable: false, comment: 'Theme ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 400, nullable: false, comment: 'Canonical scope path')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'normal', comment: 'Store mode')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'frontend', comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'int', nullable: false, comment: 'Sealed published version ID')]
    public const schema_fields_PUBLISHED_VERSION_ID = 'published_version_id';
    #[Col(type: 'int', nullable: true, comment: 'Current draft version ID')]
    public const schema_fields_DRAFT_VERSION_ID = 'draft_version_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Optimistic selection revision')]
    public const schema_fields_SELECTION_REVISION = 'selection_revision';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getSelectionId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getThemeId(): int
    {
        return (int)$this->getData(self::schema_fields_THEME_ID);
    }

    public function getScope(): string
    {
        return (string)($this->getData(self::schema_fields_SCOPE) ?: '');
    }

    public function getStoreMode(): string
    {
        return (string)($this->getData(self::schema_fields_STORE_MODE) ?: 'normal');
    }

    public function getArea(): string
    {
        return (string)($this->getData(self::schema_fields_AREA) ?: 'frontend');
    }

    public function getPublishedVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_PUBLISHED_VERSION_ID);
    }

    public function getDraftVersionId(): ?int
    {
        $id = $this->getData(self::schema_fields_DRAFT_VERSION_ID);

        return $id === null || $id === '' ? null : (int)$id;
    }

    public function getSelectionRevision(): int
    {
        return (int)($this->getData(self::schema_fields_SELECTION_REVISION) ?: 0);
    }

    public function save_before(): void
    {
        parent::save_before();
        $themeId = $this->getThemeId();
        $scope = \trim($this->getScope());
        $area = \trim($this->getArea());
        $storeMode = \trim($this->getStoreMode());
        $published = $this->getPublishedVersionId();
        if (
            $themeId < 1
            || $scope === ''
            || !\in_array($area, ['frontend', 'backend'], true)
            || $storeMode === ''
            || $published < 1
        ) {
            throw new \InvalidArgumentException((string)__('Theme 版本选择身份无效。'));
        }
        $this->setData(self::schema_fields_SCOPE, $scope);
        $this->setData(self::schema_fields_AREA, $area);
        $this->setData(self::schema_fields_STORE_MODE, $storeMode);
        $now = \date('Y-m-d H:i:s');
        if (!$this->getSelectionId() && !$this->getData(self::schema_fields_CREATE_TIME)) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
    }
}
