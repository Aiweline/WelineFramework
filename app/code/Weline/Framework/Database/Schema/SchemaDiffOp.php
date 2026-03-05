<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 单条 Schema 差异操作，由 SchemaDiffEngine 产出，由 SchemaMigrationExecutor 执行。
 */
final class SchemaDiffOp
{
    public const KIND_CREATE_TABLE = 'create_table';
    public const KIND_ADD_COLUMN = 'add_column';
    public const KIND_DROP_COLUMN = 'drop_column';
    public const KIND_MODIFY_COLUMN = 'modify_column';
    public const KIND_ADD_INDEX = 'add_index';
    public const KIND_DROP_INDEX = 'drop_index';
    public const KIND_ADD_FOREIGN_KEY = 'add_foreign_key';
    public const KIND_DROP_FOREIGN_KEY = 'drop_foreign_key';
    /** 仅修改表注释；payload=新注释(string)，rollbackPayload=旧注释(string) */
    public const KIND_MODIFY_TABLE_COMMENT = 'modify_table_comment';

    public function __construct(
        public readonly string $kind,
        public readonly string $tableName,
        public readonly mixed $payload,
        public readonly ?string $modelClass = null,
        /** 回滚用 payload（如 MODIFY_COLUMN 时的旧列定义） */
        public readonly mixed $rollbackPayload = null,
    ) {
    }
}
