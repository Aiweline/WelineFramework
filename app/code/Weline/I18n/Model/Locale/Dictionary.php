<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/29 22:34:35
 */

namespace Weline\I18n\Model\Locale;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Dictionary extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'md5';
    public const fields_MD5 = 'md5';
    public const fields_WORD = 'word';
    public const fields_LOCALE_CODE = 'locale_code';
    public const fields_TRANSLATE = 'translate';

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
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 128, 'primary key not null', 'MD5指纹')
                ->addColumn(self::fields_WORD, TableInterface::column_type_TEXT, null, 'not null', '词')
                ->addColumn(self::fields_LOCALE_CODE, TableInterface::column_type_VARCHAR, 12, 'not null', '地区码')
                ->addColumn(self::fields_TRANSLATE, TableInterface::column_type_TEXT, null, 'not null', '翻译')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_code', self::fields_LOCALE_CODE, '区码索引')
                ->create();
        }
    }
}