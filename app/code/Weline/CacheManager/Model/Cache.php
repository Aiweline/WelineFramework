<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '缓存表')]
class Cache extends Model
{
    public const schema_fields_ID          = 'id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '适配器类名或名称')]
    public const schema_fields_NAME        = 'name';
    #[Col(type: 'int', nullable: false, default: 0, comment: '状态')]
    public const schema_fields_Status      = 'status';
    #[Col(type: 'int', nullable: false, default: 0, comment: '持久化：0不持久化，1持久化')]
    public const schema_fields_Permanently = 'permanently';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '模块')]
    public const schema_fields_Module      = 'module';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '标识')]
    public const schema_fields_IDENTITY    = 'identity';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '类型')]
    public const schema_fields_TYPE        = 'type';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '文件')]
    public const schema_fields_FILE        = 'file';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
}

