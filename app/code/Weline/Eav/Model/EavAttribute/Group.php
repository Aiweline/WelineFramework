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
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV属性组模型 (SRP - 单一职责原则)
 * 
 * 表结构定义已迁移到 Schema/EavAttributeGroupSchema.php
 * 本类只负责数据操作和业务逻辑
 */
class Group extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'group_id';
    public const fields_group_id = 'group_id';
    public const fields_name = 'name';
    public const fields_code = 'code';
    public const fields_set_id = 'set_id';
    public const fields_eav_entity_id = 'eav_entity_id';

    public array $_unit_primary_keys = ['group_id', 'eav_entity_id', 'set_id', 'code'];
    public array $_index_sort_keys = ['group_id', 'eav_entity_id', 'set_id', 'code'];

    // 表结构已迁移到 Schema/EavAttributeGroupSchema.php
    // 由 Setup/Install.php 统一管理表创建
    public function setup(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}
    public function upgrade(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}
    public function install(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}

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
        $set = $attributeModel->reset()->where(EavAttribute::fields_group_id, $this->getId())
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


    /**
     * @DESC          # 获取关联属性组的属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/27 22:22
     * 参数区：
     */
    public function getAttributeModel(): EavAttribute
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