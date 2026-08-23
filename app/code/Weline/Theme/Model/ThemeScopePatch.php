<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Immutable per-path Theme override patch')]
#[Index(name: 'uk_theme_scope_patch_path', columns: ['revision_id', 'path_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_scope_patch_workspace', columns: ['workspace_id', 'revision_id', 'sequence_no'])]
#[Index(name: 'idx_theme_scope_patch_node', columns: ['node_uid'])]
final class ThemeScopePatch extends Model
{
    public const schema_table = 'theme_scope_patch';
    public const schema_primary_key = 'patch_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Patch ID')]
    public const schema_fields_ID = 'patch_id';
    #[Col(type: 'int', nullable: false, comment: 'Revision ID')]
    public const schema_fields_REVISION_ID = 'revision_id';
    #[Col(type: 'int', nullable: false, comment: 'Workspace ID')]
    public const schema_fields_WORKSPACE_ID = 'workspace_id';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'set/add_node/remove_node/move_node/inherit')]
    public const schema_fields_OPERATION = 'operation';
    #[Col(type: 'varchar', length: 1024, nullable: false, comment: 'Stable JSON pointer')]
    public const schema_fields_PATH = 'path';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Path SHA-256')]
    public const schema_fields_PATH_HASH = 'path_hash';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'Stable 128-bit node UID')]
    public const schema_fields_NODE_UID = 'node_uid';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'Stable move/add anchor UID')]
    public const schema_fields_ANCHOR_UID = 'anchor_uid';
    #[Col(type: 'varchar', length: 8, nullable: true, comment: 'inside/before/after')]
    public const schema_fields_POSITION = 'position';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Explicit value, including null')]
    public const schema_fields_HAS_VALUE = 'has_value';
    #[Col(type: 'longtext', nullable: true, comment: 'JSON encoded explicit value')]
    public const schema_fields_VALUE_JSON = 'value_json';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Deterministic patch order')]
    public const schema_fields_SEQUENCE_NO = 'sequence_no';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }
}
