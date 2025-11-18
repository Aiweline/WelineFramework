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

namespace Weline\Eav\Model\EavAttribute;


use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\I18n\LocalModel;

class LocalDescription extends LocalModel
{
    public const fields_ID          = EavAttribute::fields_ID;

//    public function setup(ModelSetup $setup, Context $context): void
//    {
//        $setup->dropTable();
//        $this->install($setup, $context);
//    }
}