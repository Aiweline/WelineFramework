<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/18 13:44:09
 */

namespace Weline\Eav\Model\EavAttribute;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Option extends \Weline\Framework\Database\Model
{
    public const fields_ID           = 'option_id';
    public const fields_option_id    = 'option_id';
    public const fields_entity_id    = 'entity_id';
    public const fields_attribute_id = 'attribute_id';
    public const fields_code         = 'code';
    public const fields_value        = 'value';

    public array $_unit_primary_keys = ['option_id','attribute_id','code'];
    public array $_index_sort_keys = ['option_id','attribute_id','code'];

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
            $setup->createTable('属性配置项')
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '配置项ID')
                  ->addColumn(self::fields_code, TableInterface::column_type_VARCHAR, 255, 'not null', '配置项代码')
                  ->addColumn(self::fields_value, TableInterface::column_type_VARCHAR, 255, 'not null', '配置值')
                  ->addColumn(self::fields_attribute_id, TableInterface::column_type_INTEGER, 0, 'not null', '属性ID')
                  ->addColumn(self::fields_entity_id, TableInterface::column_type_VARCHAR, 255, 'not null', '相关实体ID')
                  ->addIndex(TableInterface::index_type_KEY, 'EAV_ATTRIBUTE_ID', 'attribute_id')
                  ->create();
        }
    }

    function addLocalDescription()
    {
        $lang = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \Weline\Eav\Model\EavAttribute\Option\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }
}