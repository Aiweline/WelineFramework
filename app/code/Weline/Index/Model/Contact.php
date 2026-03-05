<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/17 10:35:03
 */

namespace Weline\Index\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '联系')]
#[Index(name: 'phone', columns: ['phone'], comment: '电话索引')]
class Contact extends Model
{
    public const schema_table = 'weline_index_contact';
    public const schema_primary_key = 'contact_id';

    public const indexer = 'weline_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'contact_id';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: true, comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '称呼')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: '电话号码')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '主题')]
    public const schema_fields_OBJECT = 'object';
    #[Col(type: 'text', nullable: false, comment: '内容')]
    public const schema_fields_MESSAGE = 'message';
}
