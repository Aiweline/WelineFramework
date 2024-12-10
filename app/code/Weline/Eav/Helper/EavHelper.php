<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：24/4/2024 10:31:27
 */

namespace Weline\Eav\Helper;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;

class EavHelper
{
    public static function getEntity(string $entity_code): \Weline\Eav\Model\EavEntity
    {
        # 当前实体
        /** @var \Weline\Eav\Model\EavEntity $entity */
        $entity = ObjectManager::make(\Weline\Eav\Model\EavEntity::class);
        $entity = $entity->load($entity::fields_code, $entity_code);
        if (!$entity->getId()) {
            throw new \Exception(__('当前实体不存在 %1', $entity_code));
        }
        return $entity;
    }

    /**
     * @throws \ReflectionException
     * @throws Exception
     */
    public static function getType(string $type_code): \Weline\Eav\Model\EavAttribute\Type
    {
        /** @var \Weline\Eav\Model\EavAttribute\Type $type */
        $type = ObjectManager::make(\Weline\Eav\Model\EavAttribute\Type::class);
        $type = $type->load($type::fields_code, $type_code);
        if (!$type->getId()) {
            throw new \Exception(__('类型不存在:%1', $type_code));
        }
        return $type;
    }

    /**
     * @throws ModelException
     */
    public static function addType(\Weline\Eav\Model\EavAttribute\Type $type): \Weline\Eav\Model\EavAttribute\Type
    {
        $type->save();
        if(!$type->getId()){
            throw new \Exception(__('属性类型添加失败 %1', $type->getCode()));
        }
        return $type;
    }

    /**
     * @throws Exception
     * @throws ModelException
     * @throws \ReflectionException
     */
    public static function getEntityAttributeSet(string $entity_code, string $code = 'default', string $name = '默认属性集'): \Weline\Eav\Model\EavAttribute\Set
    {
        /** @var \Weline\Eav\Model\EavAttribute\Set $attributeSet */
        $attributeSet = ObjectManager::make(\Weline\Eav\Model\EavAttribute\Set::class);
        $eav_entity_id    = self::getEntity($entity_code)->getId();
        $attributeSet = $attributeSet->where($attributeSet::fields_code, $code)
            ->where($attributeSet::fields_eav_entity_id, $eav_entity_id)
            ->find()
            ->fetch();
        if (!$attributeSet->getId()) {
            # 创建属性集
            /** @var \Weline\Eav\Model\EavAttribute\Set $attributeSet */
            $attributeSet = ObjectManager::make(\Weline\Eav\Model\EavAttribute\Set::class);
            $attributeSet->setEntityId($eav_entity_id)
                ->setCode($code)
                ->setName($name)
                ->save();
            if (!$attributeSet->getId()) {
                throw new \Exception('创建属性集失败');
            }
        }
        return $attributeSet;
    }

    public static function getEntityAttributeGroup(string $entity_code, string $code = 'default', string $group_name = '默认属性组', $set_code = 'default', string $set_name = '默认属性集'): \Weline\Eav\Model\EavAttribute\Group
    {
        /** @var \Weline\Eav\Model\EavAttribute\Group $attributeGroup */
        $attributeGroup = ObjectManager::make(\Weline\Eav\Model\EavAttribute\Group::class);
        $eav_entity_id      = self::getEntity($entity_code)->getId();
        $set_id         = self::getEntityAttributeSet($entity_code,$set_code, $set_name)->getId();
        $attributeGroup = $attributeGroup->where($attributeGroup::fields_code, $code)
            ->where($attributeGroup::fields_eav_entity_id, $eav_entity_id)
            ->where($attributeGroup::fields_set_id, $set_id)
            ->find()
            ->fetch();
        if (!$attributeGroup->getId()) {
            # 创建属性集
            /** @var \Weline\Eav\Model\EavAttribute\Group $attributeGroup */
            $attributeGroup = ObjectManager::make(\Weline\Eav\Model\EavAttribute\Group::class);
            $attributeGroup->setEntityId($eav_entity_id)
                ->setCode($code)
                ->setSetId($set_id)
                ->setName($group_name)
                ->save();
            if (!$attributeGroup->getId()) {
                throw new \Exception('创建属性组失败');
            }
        }
        return $attributeGroup;
    }

    public static function getAttribute(string $entity_code, string $code, string $name,bool $is_multi,string $type_code,string $dependence = '', string $group_code = 'default', string $group_name = '默认属性组', string $set_code = 'default', string $set_name = '默认属性集'): \Weline\Eav\Model\EavAttribute
    {
        /** @var \Weline\Eav\Model\EavAttribute $attribute */
        $attribute = ObjectManager::make(\Weline\Eav\Model\EavAttribute::class);
        $eav_entity_id = (int)self::getEntity($entity_code)->getId();
        $set_id    = (int)self::getEntityAttributeSet($set_code, $set_name)->getId();
        $group_id  = (int)self::getEntityAttributeGroup($group_code, $group_name, $set_code, $set_name)->getId();
        $type_id   = (int)self::getType($type_code)->getId();
        /**@var \Weline\Eav\Model\EavAttribute $attribute */
        $attribute = $attribute->where($attribute::fields_code, $code)
            ->where($attribute::fields_eav_entity_id, $eav_entity_id)
            ->find()
            ->fetch();
        if (!$attribute->getId()) {
            /** @var \Weline\Eav\Model\EavAttribute $attribute */
            $attribute = ObjectManager::make(\Weline\Eav\Model\EavAttribute::class);
            $attribute
                ->setEavEntityId($eav_entity_id)
                ->setCode($code)
                ->setName($name)
                ->setTypeId($type_id)
                ->isSystem(true)
                ->setSetId($set_id)
                ->setMultipleValued($is_multi)
                ->setGroupId($group_id)
                ->setDependence($dependence)
                ->save();
            if (!$attribute->getId()) {
                throw new \Exception('创建属性失败');
            }
        } else {
            $attribute->where($attribute::fields_type_id,$type_id)
                ->where($attribute::fields_eav_entity_id,$eav_entity_id)
                ->where($attribute::fields_attribute_id,$attribute->getId())
                ->where($attribute::fields_is_system,1)
                ->where($attribute::fields_code,$code)
                ->update([
                    $attribute::fields_name=>$name,
                    $attribute::fields_group_id=>$group_id,
                    $attribute::fields_set_id=>$set_id,
                    $attribute::fields_multiple_valued=>(int)$is_multi,
                ])
                ->fetch();
        }
        return $attribute;
    }
}