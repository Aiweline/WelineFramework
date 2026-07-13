<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/14 15:22:17
 */

namespace Weline\Eav\Model\EavAttribute\Option;


use Weline\Eav\Model\EavAttribute\Option;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\I18n\Api\Localization\LocalModel;

class LocalDescription extends LocalModel
{
    public const fields_ID = self::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_local_code = 'local_code';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '本地化选项值')]
    public const schema_fields_value = Option::schema_fields_value;
}
