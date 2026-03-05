<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '模组')]
class Module extends Model
{
    public const schema_table = 'weline_module';
    public const schema_primary_key = 'module_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '模块ID')]
    public const schema_fields_ID             = 'module_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '模块名')]
    public const schema_fields_NAME           = 'name';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '状态')]
    public const schema_fields_STATUS         = 'status';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION    = 'description';
    #[Col(type: 'varchar', length: 64, nullable: false, default: 'app', comment: '位置：system/system_config/app/composer')]
    public const schema_fields_POSITION       = 'position';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '命名空间')]
    public const schema_fields_NAMESPACE_PATH = 'namespace_path';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '基础路径')]
    public const schema_fields_BASE_PATH      = 'base_path';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '路径')]
    public const schema_fields_PATH           = 'path';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: '版本')]
    public const schema_fields_VERSION        = 'version';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: '上一版本')]
    public const schema_fields_LAST_VERSION   = 'last_version';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '路由')]
    public const schema_fields_ROUTER         = 'router';
}

