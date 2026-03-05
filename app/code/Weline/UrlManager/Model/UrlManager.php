<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\UrlManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'URL管理表')]
#[Index(name: 'idx_identify', columns: ['identify'], type: 'UNIQUE')]
class UrlManager extends Model
{

    public const schema_table = 'url_manager';
    public const schema_primary_key = 'url_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'URL ID')]
    public const schema_fields_ID = 'url_id';
    #[Col('varchar', 255, nullable: false, comment: 'URL路径')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 255, nullable: false, unique: true, comment: 'URI指纹')]
    public const schema_fields_IDENTIFY = 'identify';
    #[Col('int', nullable: false, comment: '所属模块ID')]
    public const schema_fields_MODULE_ID = 'module_id';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否已删除')]
    public const schema_fields_IS_DELETE = 'is_deleted';
    #[Col('varchar', 20, nullable: false, comment: '路由类型')]
    public const schema_fields_TYPE = 'type';
    #[Col('text', comment: '路由数据')]
    public const schema_fields_DATA = 'data';
}

