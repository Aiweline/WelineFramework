<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable per-resource snapshot row under one (theme_version_id, content_revision).
 */
#[Table(comment: 'Immutable Theme scope version resource snapshot')]
#[Index(
    name: 'uk_theme_scope_version_resource_snapshot',
    columns: ['theme_version_id', 'content_revision', 'resource_identity_hash'],
    type: 'UNIQUE'
)]
#[Index(name: 'idx_theme_scope_version_resource_release', columns: ['release_id'])]
final class ThemeScopeVersionResourceSnapshot extends Model
{
    public const schema_table = 'theme_scope_version_resource_snapshot';
    public const schema_primary_key = 'snapshot_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Snapshot ID')]
    public const schema_fields_ID = 'snapshot_id';
    #[Col(type: 'int', nullable: false, comment: 'ThemeScopeVersion.version_id')]
    public const schema_fields_THEME_VERSION_ID = 'theme_version_id';
    #[Col(type: 'int', nullable: false, comment: 'Content revision R')]
    public const schema_fields_CONTENT_REVISION = 'content_revision';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Canonical resource identity SHA-256')]
    public const schema_fields_RESOURCE_IDENTITY_HASH = 'resource_identity_hash';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Scoped resource type')]
    public const schema_fields_RESOURCE_TYPE = 'resource_type';
    #[Col(type: 'longtext', nullable: true, comment: 'Canonical resource key JSON')]
    public const schema_fields_RESOURCE_KEY_JSON = 'resource_key_json';
    #[Col(type: 'int', nullable: true, comment: 'Intent revision reference')]
    public const schema_fields_INTENT_REVISION_ID = 'intent_revision_id';
    #[Col(type: 'int', nullable: true, comment: 'Immutable release reference')]
    public const schema_fields_RELEASE_ID = 'release_id';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Source fingerprint')]
    public const schema_fields_SOURCE_FINGERPRINT = 'source_fingerprint';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getThemeVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_THEME_VERSION_ID);
    }

    public function getContentRevision(): int
    {
        return (int)$this->getData(self::schema_fields_CONTENT_REVISION);
    }

    public function getResourceIdentityHash(): string
    {
        return (string)($this->getData(self::schema_fields_RESOURCE_IDENTITY_HASH) ?: '');
    }

    public function save_before(): void
    {
        parent::save_before();
        $hash = \trim($this->getResourceIdentityHash());
        if ($this->getThemeVersionId() < 1 || $this->getContentRevision() < 1 || $hash === '') {
            throw new \InvalidArgumentException((string)__('Theme 版本资源快照身份无效。'));
        }
        $this->setData(self::schema_fields_RESOURCE_IDENTITY_HASH, $hash);
        if (!$this->getId() && !$this->getData(self::schema_fields_CREATE_TIME)) {
            $this->setData(self::schema_fields_CREATE_TIME, \date('Y-m-d H:i:s'));
        }
    }
}
