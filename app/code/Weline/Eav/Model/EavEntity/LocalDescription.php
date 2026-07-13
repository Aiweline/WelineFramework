<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/2 13:41:34
 */

namespace Weline\Eav\Model\EavEntity;

use Weline\Eav\Model\EavEntity;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\I18n\Api\Localization\LocalModel;

class LocalDescription extends LocalModel
{
    public const fields_ID = EavEntity::schema_fields_ID;

    #[Col('varchar', 255, nullable: true, comment: 'Localized name')]
    public const schema_fields_name = self::fields_name;
}
