<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Atomic Theme scoped release batch and immutable receipt')]
#[Index(name: 'idx_theme_scope_release_batch_digest', columns: ['batch_digest'])]
#[Index(name: 'idx_theme_scope_release_batch_scope', columns: ['scope', 'store_mode', 'area', 'state'])]
#[Index(name: 'idx_theme_scope_release_batch_source', columns: ['source_batch_id'])]
final class ThemeScopeReleaseBatch extends Model
{
    public const schema_table = 'theme_scope_release_batch';
    public const schema_primary_key = 'batch_id';

    public const STATE_PREPARING = 'preparing';
    public const STATE_PUBLISHED = 'published';
    public const STATE_PUBLISHED_CACHE_DEGRADED = 'published_cache_degraded';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Release batch ID')]
    public const schema_fields_ID = 'batch_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Frozen resource-set SHA-256')]
    public const schema_fields_BATCH_DIGEST = 'batch_digest';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Canonical three-segment scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'normal', comment: 'Store mode')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Theme identity for downstream resources')]
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
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATE_PREPARING, comment: 'Commit/cache state')]
    public const schema_fields_STATE = 'state';
    #[Col(type: 'longtext', nullable: true, comment: 'Immutable committed resource receipt JSON')]
    public const schema_fields_RECEIPT_JSON = 'receipt_json';
    #[Col(type: 'int', nullable: true, comment: 'Historical batch restored by this new batch')]
    public const schema_fields_SOURCE_BATCH_ID = 'source_batch_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Actor ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Actor display name')]
    public const schema_fields_ACTOR_NAME = 'actor_name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Publish or rollback reason')]
    public const schema_fields_REASON = 'reason';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Atomic commit time')]
    public const schema_fields_COMMITTED_AT = 'committed_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Last cache-state update')]
    public const schema_fields_CACHE_UPDATED_AT = 'cache_updated_at';
    #[Col(type: 'longtext', nullable: true, comment: 'Retryable post-commit cache error JSON')]
    public const schema_fields_CACHE_ERROR_JSON = 'cache_error_json';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    /** @return array<string,mixed> */
    public function receipt(): array
    {
        $value = $this->getData(self::schema_fields_RECEIPT_JSON);
        if (\is_array($value)) {
            return $value;
        }
        $decoded = \is_string($value) && $value !== '' ? \json_decode($value, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }
}
