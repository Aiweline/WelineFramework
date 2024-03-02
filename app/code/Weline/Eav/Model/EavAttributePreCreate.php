<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/6 23:00:01
 */

namespace Weline\Eav\Model;

use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\Cache\ObjectCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class EavAttributePreCreate extends \Weline\Framework\Database\Model
{
    public const fields_ID                = 'user_id';
    public const fields_attribute_id      = 'attribute_id';
    public const fields_user_id           = 'user_id';
    public const fields_code              = 'code';
    public const fields_name              = 'name';
    public const fields_type_id           = 'type_id';
    public const fields_set_id            = 'set_id';
    public const fields_group_id          = 'group_id';
    public const fields_entity_id         = 'entity_id';
    public const fields_multiple_valued   = 'multiple_valued';
    public const fields_has_option        = 'has_option';
    public const fields_is_system         = 'is_system';
    public const fields_is_enable         = 'is_enable';
    public const fields_OPTIONS           = 'options';
    public const fields_step              = 'step';
    public const fields_delete_option_ids = 'delete_option_ids';

    public array $_index_sort_keys = ['user_id'];
    public array $_unit_primary_keys = ['user_id'];

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
                  ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 0, 'primary key ', '用户ID')
                  ->addColumn(self::fields_attribute_id, TableInterface::column_type_INTEGER, 0, 'unique ', '属性ID')
                  ->addColumn(self::fields_code, TableInterface::column_type_VARCHAR, 255, '', '属性代码')
                  ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, '', '属性名称')
                  ->addColumn(self::fields_type_id, TableInterface::column_type_INTEGER, 0, '', '类型ID')
                  ->addColumn(self::fields_set_id, TableInterface::column_type_VARCHAR, 255, '', '属性集合代码')
                  ->addColumn(self::fields_group_id, TableInterface::column_type_VARCHAR, 255, '', '属性组代码')
                  ->addColumn(self::fields_entity_id, TableInterface::column_type_VARCHAR, 255, '', '实体代码')
                  ->addColumn(self::fields_multiple_valued, TableInterface::column_type_VARCHAR, 255, '', '是否多个值')
                  ->addColumn(self::fields_has_option, TableInterface::column_type_VARCHAR, 255, '', '是否有选项')
                  ->addColumn(self::fields_is_system, TableInterface::column_type_VARCHAR, 255, '', '是否系统属性')
                  ->addColumn(self::fields_is_enable, TableInterface::column_type_VARCHAR, 255, '', '是否可用')
                  ->addColumn(self::fields_OPTIONS, TableInterface::column_type_TEXT, 0, '', '选项值')
                  ->addColumn(self::fields_step, TableInterface::column_type_VARCHAR, 255, '', '步骤')
                  ->addColumn(self::fields_delete_option_ids, TableInterface::column_type_TEXT, 0, '', '删除配置项ID')
                  ->addIndex(TableInterface::index_type_KEY, "idx_user_id", self::fields_user_id)
                  ->create();
        }
    }

    function getEntity(): EavEntity|bool
    {
        if (!$this->getEntityId()) {
            return false;
        }
        $result = ObjectManager::getInstance(EavEntity::class)->load($this->getEntityId());
        if ($result->getId()) {
            return $result;
        }
        return false;
    }

    function getSet(): Set|bool
    {
        if (!$this->getSetId() || !$this->getEntityId()) {
            return false;
        }
        $result = ObjectManager::getInstance(Set::class)->where(Set::fields_entity_id, $this->getEntityId())
                               ->where('set_id', $this->getSetId())
                               ->find()
                               ->fetch();
        if ($result->getId()) {
            return $result;
        }
        return false;
    }

    function getGroup(): Group|bool
    {
        if (empty($this->getGroupId()) || empty($this->getEntityId())) {
            return false;
        }
        $result = ObjectManager::getInstance(Group::class)->where(Set::fields_entity_id, $this->getEntityId())
                               ->where('group_id', $this->getGroupId())
                               ->find()
                               ->fetch();
        if ($result->getId()) {
            return $result;
        }
        return false;
    }

    function getOptions()
    {
        if (empty($this->getData('options'))) {
            return [];
        }
        return json_decode($this->getData('options'), true);
    }

    function addDeleteOptionIds(int $option_id)
    {
        $ids = $this->getDeleteOptionIds();
        if (!in_array($option_id, $ids)) {
            $ids[] = $option_id;
        }
        $this->setDeleteOptionIds($ids);
    }

    function setDeleteOptionIds(array $ids)
    {
        $this->setData(self::fields_delete_option_ids, json_encode($ids))->save(true);
        return $this;
    }

    function getDeleteOptionIds()
    {
        return json_decode($this->getData(self::fields_delete_option_ids)??'[]', true);
    }
}