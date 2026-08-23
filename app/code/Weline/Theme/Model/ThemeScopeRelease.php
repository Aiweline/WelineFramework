<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Immutable effective Theme release')]
#[Index(name: 'idx_theme_scope_release_workspace', columns: ['workspace_id', 'published_at'])]
#[Index(name: 'idx_theme_scope_release_parent', columns: ['parent_release_id'])]
#[Index(name: 'idx_theme_scope_release_scope', columns: ['scope', 'store_mode', 'area', 'resource_type'])]
#[Index(name: 'idx_theme_scope_release_fingerprint', columns: ['fingerprint'])]
final class ThemeScopeRelease extends Model
{
    public const schema_table = 'theme_scope_release';
    public const schema_primary_key = 'release_id';

    public const STATUS_EFFECTIVE = 'effective';
    public const STATUS_CONFLICT = 'conflict';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Release ID')]
    public const schema_fields_ID = 'release_id';
    #[Col(type: 'int', nullable: false, comment: 'Workspace ID')]
    public const schema_fields_WORKSPACE_ID = 'workspace_id';
    #[Col(type: 'int', nullable: true, comment: 'Published local revision; null for pure inherited propagation')]
    public const schema_fields_REVISION_ID = 'revision_id';
    #[Col(type: 'int', nullable: true, comment: 'Direct parent release')]
    public const schema_fields_PARENT_RELEASE_ID = 'parent_release_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Workspace identity SHA-256')]
    public const schema_fields_IDENTITY_HASH = 'identity_hash';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Canonical scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'normal', comment: 'Store mode')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Scoped resource type')]
    public const schema_fields_RESOURCE_TYPE = 'resource_type';
    #[Col(type: 'int', nullable: true, comment: 'Effective theme binding')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'longtext', nullable: false, comment: 'Immutable effective schema JSON')]
    public const schema_fields_EFFECTIVE_PAYLOAD_JSON = 'effective_payload_json';
    #[Col(type: 'longtext', nullable: true, comment: 'Compiler artifact metadata JSON')]
    public const schema_fields_COMPILED_ARTIFACT_JSON = 'compiled_artifact_json';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Effective payload SHA-256')]
    public const schema_fields_FINGERPRINT = 'fingerprint';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_EFFECTIVE, comment: 'Release status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'longtext', nullable: true, comment: 'Conflict details JSON')]
    public const schema_fields_CONFLICT_JSON = 'conflict_json';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Actor ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Actor display name')]
    public const schema_fields_ACTOR_NAME = 'actor_name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Publish reason')]
    public const schema_fields_REASON = 'reason';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Published at')]
    public const schema_fields_PUBLISHED_AT = 'published_at';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function payload(): array
    {
        $value = $this->getData(self::schema_fields_EFFECTIVE_PAYLOAD_JSON);
        if (\is_array($value)) {
            return $value;
        }
        $decoded = \is_string($value) && $value !== '' ? \json_decode($value, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }
}
