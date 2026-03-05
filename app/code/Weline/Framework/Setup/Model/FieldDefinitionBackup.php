<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 字段结构定义备份模型
 * 备份字段的定义信息（DDL 元数据），以 JSON 形式保存。表由 SchemaDiff 自动建表。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: '框架字段结构定义备份表')]
#[Index(name: 'idx_def_module_table_field', columns: ['module', 'table_name', 'field_name'], type: 'KEY', comment: '模块表字段')]
#[Index(name: 'idx_def_version', columns: ['version'], type: 'KEY', comment: '版本')]
class FieldDefinitionBackup extends Model
{
    public const schema_table = 'weline_framework_field_definition_backup';
    public const schema_primary_key = 'definition_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Definition ID')]
    public const schema_fields_ID = 'definition_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模块名称')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '表名')]
    public const schema_fields_TABLE_NAME = 'table_name';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '字段名')]
    public const schema_fields_FIELD_NAME = 'field_name';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '模块版本号')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'text', nullable: true, comment: '字段定义信息（JSON）')]
    public const schema_fields_DEFINITION = 'definition';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '备份时间')]
    public const schema_fields_BACKUP_TIME = 'backup_time';
}
