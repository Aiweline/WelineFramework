<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 字段备份冲突记录模型
 * 记录字段恢复时与现有数据发生冲突的情况，便于审计和后续人工处理。表由 SchemaDiff 自动建表。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: '框架字段备份冲突记录表')]
#[Index(name: 'idx_conflict_module_table_field', columns: ['module', 'table_name', 'field_name'], type: 'KEY', comment: '模块表字段')]
#[Index(name: 'idx_conflict_primary', columns: ['table_name', 'primary_key', 'primary_value'], type: 'KEY', comment: '主键')]
#[Index(name: 'idx_conflict_version', columns: ['version'], type: 'KEY', comment: '版本')]
class FieldBackupConflict extends Model
{
    public const schema_table = 'weline_framework_field_backup_conflict';
    public const schema_primary_key = 'conflict_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Conflict ID')]
    public const schema_fields_ID = 'conflict_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模块名称')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '表名')]
    public const schema_fields_TABLE_NAME = 'table_name';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '字段名')]
    public const schema_fields_FIELD_NAME = 'field_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '主键字段名')]
    public const schema_fields_PRIMARY_KEY = 'primary_key';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '主键值')]
    public const schema_fields_PRIMARY_VALUE = 'primary_value';
    #[Col(type: 'text', nullable: true, comment: '备份中的字段值（JSON格式）')]
    public const schema_fields_BACKUP_VALUE = 'backup_value';
    #[Col(type: 'text', nullable: true, comment: '当前表中的字段值（JSON格式）')]
    public const schema_fields_CURRENT_VALUE = 'current_value';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '尝试恢复的模块版本号')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '产生冲突的时间')]
    public const schema_fields_CONFLICT_TIME = 'conflict_time';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '备注')]
    public const schema_fields_NOTE = 'note';
}
