<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Indexer\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '索引表')]
#[Index(name: 'idx_name', columns: ['name'], comment: '索引名称')]
#[Index(name: 'idx_module', columns: ['module_name'], comment: '模块索引')]
class Indexer extends Model
{
    public string $module_name = '';
    public const schema_table = 'db_indexer';
    public const schema_primary_key = 'indexer_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '索引ID')]
    public const schema_fields_ID     = 'indexer_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '索引名称')]
    public const schema_fields_NAME   = 'name';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '模块名')]
    public const schema_fields_MODULE = 'module_name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '模块模型')]
    public const schema_fields_MODEL  = 'module_model';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '模块表')]
    public const schema_fields_TABLE  = 'module_table';
    public const indexer = 'weline_indexer';
}
