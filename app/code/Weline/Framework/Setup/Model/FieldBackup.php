<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 字段备份记录模型
 * 存储字段删除前的数据备份，用于后续恢复。表由 SchemaDiff 自动建表。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: '框架字段备份表')]
#[Index(name: 'idx_module_table_field', columns: ['module', 'table_name', 'field_name'], type: 'KEY', comment: '模块表字段')]
#[Index(name: 'idx_primary', columns: ['table_name', 'primary_key', 'primary_value'], type: 'KEY', comment: '主键')]
#[Index(name: 'idx_restored', columns: ['restored'], type: 'KEY', comment: '是否已恢复')]
#[Index(name: 'idx_version', columns: ['version'], type: 'KEY', comment: '版本')]
class FieldBackup extends Model
{
    public const schema_table = 'weline_framework_field_backup';
    public const schema_primary_key = 'backup_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Backup ID')]
    public const schema_fields_ID = 'backup_id';
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
    #[Col(type: 'text', nullable: true, comment: '字段值（JSON格式）')]
    public const schema_fields_FIELD_VALUE = 'field_value';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '模块版本号')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '备份时间')]
    public const schema_fields_BACKUP_TIME = 'backup_time';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否已恢复：0未恢复，1已恢复')]
    public const schema_fields_RESTORED = 'restored';
    #[Col(type: 'timestamp', nullable: true, comment: '恢复时间')]
    public const schema_fields_RESTORE_TIME = 'restore_time';
}
