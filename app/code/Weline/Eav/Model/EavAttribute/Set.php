<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/22 19:38:55
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

class Set extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'set_id';
    public const fields_SET_ID = 'set_id';
    public const fields_code = 'code';
    public const fields_eav_entity_id = 'eav_entity_id';
    public const fields_name = 'name';

    public array $_unit_primary_keys = ['set_id', 'eav_entity_id', 'code'];
    public array $_index_sort_keys = ['set_id', 'eav_entity_id', 'code'];

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
            $setup->createTable(__('属性集表'))
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'PRIMARY KEY auto_increment', __('属性集ID'))
                ->addColumn(self::fields_eav_entity_id, TableInterface::column_type_INTEGER, 0, 'not null', __('实体ID'))
                ->addColumn(self::fields_code, TableInterface::column_type_VARCHAR, 255, 'not null', __('属性集代码'))
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', __('属性集名'))
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_unique_code_and_eav_entity_id', [self::fields_code, self::fields_eav_entity_id])
                ->addIndex(TableInterface::index_type_KEY, 'idx_eav_entity_id', self::fields_eav_entity_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_code)
                ->create();
        }
    }

    function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    function getCode()
    {
        return $this->getData(self::fields_code);
    }

    function setEntityId(int $eav_entity_id): Set
    {
        return $this->setData(self::fields_eav_entity_id, $eav_entity_id);
    }

    function getEavEntityId()
    {
        return $this->getData(self::fields_eav_entity_id);
    }

    function getName()
    {
        return $this->getData(self::fields_name);
    }

    function setName(string $name): Set
    {
        return $this->setData(self::fields_name, $name);
    }

    function hasAttributes(): bool
    {
        /**@var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $set = $attributeModel->reset()->where(EavAttribute::fields_set_id, $this->getId())
            ->find()->fetch();
        if ($set->getId()) {
            return true;
        }
        return false;
    }

    function hasGroups(): bool
    {
        /**@var Group $group */
        $group = ObjectManager::getInstance(Group::class);
        $group = $group->reset()->where(EavAttribute::fields_set_id, $this->getId())
            ->find()->fetch();
        if ($group->getId()) {
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
        $attribute->where(EavAttribute::fields_set_id, $this->getId())
            ->update(EavAttribute::fields_set_id, 0)
            ->fetch();
        // 将使用此属性集的属性组ID设置为0
        /**@var Group $group */
        $group = ObjectManager::getInstance(Group::class);
        $group->where(Group::fields_set_id, $this->getId())
            ->update(Group::fields_set_id, 0)
            ->fetch();
    }

    function addLocalDescription()
    {
        $lang = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \Weline\Eav\Model\EavAttribute\Set\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }

    /**
     * @DESC          # 获取关联属性集的属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/27 22:21
     * 参数区：
     * @return \Weline\Eav\Model\EavAttribute
     */
    public function getAttrbiuteModel(): EavAttribute
    {
        /**@var EavAttribute $attrbiute */
        $attrbiute = ObjectManager::getInstance(EavAttribute::class);
        $attrbiute->where(EavAttribute::fields_set_id, $this->getId());
        return $attrbiute;
    }

    /**
     * @DESC          # 获取关联属性集的属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/27 22:21
     * 参数区：
     * @return \Weline\Eav\Model\EavAttribute\Group
     */
    public function getAttrbiuteSetGroupModel(): Group
    {
        /**@var Group $group */
        $group = ObjectManager::getInstance(Group::class);
        $group->where(Group::fields_set_id, $this->getId());
        return $group;
    }

    public function getEavEntitySet(EavEntity|EavModel $entity): array
    {
        $query = clone $this->getQuery();
        return $query->where('eav_entity_id', $entity->getEavEntityId())->select()->fetchArray();
    }
}