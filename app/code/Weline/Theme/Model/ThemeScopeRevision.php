<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Immutable local Theme override revision')]
#[Index(name: 'uk_theme_scope_revision_no', columns: ['workspace_id', 'revision_no'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_scope_revision_parent', columns: ['parent_release_id'])]
final class ThemeScopeRevision extends Model
{
    public const schema_table = 'theme_scope_revision';
    public const schema_primary_key = 'revision_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CONFLICT = 'conflict';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Revision ID')]
    public const schema_fields_ID = 'revision_id';
    #[Col(type: 'int', nullable: false, comment: 'Workspace ID')]
    public const schema_fields_WORKSPACE_ID = 'workspace_id';
    #[Col(type: 'int', nullable: false, comment: 'Monotonic local revision')]
    public const schema_fields_REVISION_NO = 'revision_no';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Optimistic base revision')]
    public const schema_fields_BASE_REVISION = 'base_revision';
    #[Col(type: 'int', nullable: true, comment: 'Parent release baseline')]
    public const schema_fields_PARENT_RELEASE_ID = 'parent_release_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_DRAFT, comment: 'Revision state')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Human summary')]
    public const schema_fields_SUMMARY = 'summary';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Actor ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Actor display name')]
    public const schema_fields_ACTOR_NAME = 'actor_name';
    #[Col(type: 'longtext', nullable: true, comment: 'Structural conflicts JSON')]
    public const schema_fields_CONFLICT_JSON = 'conflict_json';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }
}
