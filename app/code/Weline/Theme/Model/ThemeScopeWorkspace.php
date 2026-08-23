<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Theme scoped draft/published workspace pointers')]
#[Index(name: 'uk_theme_scope_workspace_identity', columns: ['identity_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_scope_workspace_scope', columns: ['scope', 'store_mode', 'area', 'resource_type'])]
#[Index(name: 'idx_theme_scope_workspace_parent', columns: ['parent_release_id'])]
final class ThemeScopeWorkspace extends Model
{
    public const schema_table = 'theme_scope_workspace';
    public const schema_primary_key = 'workspace_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONFLICT = 'conflict';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Workspace ID')]
    public const schema_fields_ID = 'workspace_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Canonical context SHA-256')]
    public const schema_fields_IDENTITY_HASH = 'identity_hash';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Canonical three-segment scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'global/website/store/channel')]
    public const schema_fields_SCOPE_KIND = 'scope_kind';
    #[Col(type: 'int', nullable: true, comment: 'Website ID; zero is valid')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'normal', comment: 'Store mode, independent from scope')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Scoped resource type')]
    public const schema_fields_RESOURCE_TYPE = 'resource_type';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Resolved Theme identity for downstream resources')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 128, nullable: false, default: 'default', comment: 'Layout/page type')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';
    #[Col(type: 'varchar', length: 128, nullable: false, default: 'default', comment: 'Layout option')]
    public const schema_fields_LAYOUT_OPTION = 'layout_option';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'default', comment: 'Locale')]
    public const schema_fields_LOCALE = 'locale';
    #[Col(type: 'varchar', length: 64, nullable: false, default: 'global', comment: 'Business target type')]
    public const schema_fields_TARGET_TYPE = 'target_type';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Business target ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col(type: 'int', nullable: true, comment: 'Current immutable draft revision')]
    public const schema_fields_DRAFT_REVISION_ID = 'draft_revision_id';
    #[Col(type: 'int', nullable: true, comment: 'Current immutable published release')]
    public const schema_fields_PUBLISHED_RELEASE_ID = 'published_release_id';
    #[Col(type: 'int', nullable: true, comment: 'Last successfully published release')]
    public const schema_fields_LAST_GOOD_RELEASE_ID = 'last_good_release_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Optimistic workspace revision')]
    public const schema_fields_REVISION = 'revision';
    #[Col(type: 'int', nullable: true, comment: 'Parent release used by current draft')]
    public const schema_fields_PARENT_RELEASE_ID = 'parent_release_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_ACTIVE, comment: 'Workspace state')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'longtext', nullable: true, comment: 'Current structural conflicts JSON')]
    public const schema_fields_CONFLICT_JSON = 'conflict_json';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getRevision(): int
    {
        return (int)($this->getData(self::schema_fields_REVISION) ?: 0);
    }

    public function conflicts(): array
    {
        $value = $this->getData(self::schema_fields_CONFLICT_JSON);
        if (\is_array($value)) {
            return $value;
        }
        $decoded = \is_string($value) && $value !== '' ? \json_decode($value, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = \date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
    }
}
