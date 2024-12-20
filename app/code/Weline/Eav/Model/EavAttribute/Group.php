<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/22 19:38:43
 */

namespace Weline\Eav\Model\EavAttribute;

use Weline\Eav\EavModel;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Group extends \Weline\Framework\Database\Model
{
    public const fields_ID        = 'group_id';
    public const fields_group_id  = 'group_id';
    public const fields_name      = 'name';
    public const fields_code      = 'code';
    public const fields_set_id    = 'set_id';
    public const fields_eav_entity_id = 'eav_entity_id';

    public array $_unit_primary_keys = ['group_id', 'eav_entity_id', 'set_id', 'code'];
    public array $_index_sort_keys = ['group_id', 'eav_entity_id', 'set_id', 'code'];

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
            $setup->createTable('属性组')
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'PRIMARY KEY auto_increment', '属性组ID')
                  ->addColumn(self::fields_code, TableInterface::column_type_VARCHAR, 255, 'not null', '属性组代码')
                  ->addColumn(self::fields_set_id, TableInterface::column_type_INTEGER, 0, 'not null', '属性集ID')
                  ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '属性组名')
                  ->addColumn(self::fields_eav_entity_id, TableInterface::column_type_VARCHAR, 255, 'not null', 'Eav实体ID')
                  ->addIndex(
                      TableInterface::index_type_UNIQUE,
                      'idx_unique_code_and_eav_entity_id',
                      [self::fields_code, self::fields_eav_entity_id],
                      '实体和属性组code唯一索引'
                  )
                  ->addIndex(
                      TableInterface::index_type_KEY,
                      'idx_eav_entity_id',
                      self::fields_eav_entity_id,
                      '实体索引'
                  )
                  ->addIndex(
                      TableInterface::index_type_KEY,
                      'idx_set_id',
                      self::fields_set_id,
                      '属性集索引'
                  )
                  ->addIndex(
                      TableInterface::index_type_KEY,
                      'idx_code',
                      self::fields_code,
                      '属性组索引'
                  )
                  ->create();
        }
    }

    function getCode()
    {
        return $this->getData(self::fields_code);
    }

    function setCode(string $code): Group
    {
        return $this->setData(self::fields_code, $code);
    }

    function getSetId()
    {
        return $this->getData(self::fields_set_id);
    }

    function setSetId(int $set_id): Group
    {
        return $this->setData(self::fields_set_id, $set_id);
    }

    function getEavEntityId()
    {
        return $this->getData(self::fields_eav_entity_id);
    }

    function setEntityId(int $eav_entity_id): Group
    {
        return $this->setData(self::fields_eav_entity_id, $eav_entity_id);
    }

    function getName()
    {
        return $this->getData(self::fields_name);
    }

    function setName(string $name): Group
    {
        return $this->setData(self::fields_name, $name);
    }

    function hasAttributes(): bool
    {
        /**@var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $set            = $attributeModel->reset()->where(EavAttribute::fields_group_id, $this->getId())
                                         ->find()->fetch();
        if ($set->getId()) {
            return true;
        }
        return false;
    }

    public function delete_after()
    {
        parent::delete_after();
        // 将使用此属性集的属性集ID设置为0
        /**@var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->where(EavAttribute::fields_group_id, $this->getId())
                  ->update(EavAttribute::fields_group_id, 0)
                  ->fetch();
    }


    function addLocalDescription()
    {
        $lang    = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \Weline\Eav\Model\EavAttribute\Group\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }

    /**
     * @DESC          # 获取关联属性组的属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/27 22:22
     * 参数区：
     */
    public function getAttributeModel():EavAttribute
    {
        /**@var EavAttribute $attrbiute */
        $attrbiute = ObjectManager::getInstance(EavAttribute::class);
        $attrbiute->where(EavAttribute::fields_group_id, $this->getId());
        return $attrbiute;
    }

    public function getEavEntityGroup(EavEntity|EavModel $entity): array
    {
        $query = clone $this->getQuery();
        return $query->where('eav_entity_id', $entity->getEavEntityId())->select()->fetchArray();
    }
}