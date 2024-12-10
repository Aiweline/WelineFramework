<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:42:04
 */

namespace Weline\I18n\Model\Locale;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Name extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'locale_code';
    public const fields_LOCALE_CODE = 'locale_code';
    public const fields_DISPLAY_LOCALE_CODE = 'display_locale_code';
    public const fields_DISPLAY_NAME = 'display_name';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 12, 'not null', '地区码')
                ->addColumn(self::fields_DISPLAY_LOCALE_CODE, TableInterface::column_type_VARCHAR, 12, 'not null', '展示地区码')
                ->addColumn(self::fields_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 255, 'unique not null', '地区名')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_locale_code', self::fields_LOCALE_CODE, '区码索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_display_locale_code', self::fields_DISPLAY_LOCALE_CODE, '展示区码索引')
                ->create();
        }
    }
}
