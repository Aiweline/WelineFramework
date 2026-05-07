<?php

declare(strict_types=1);

namespace WeShop\Product\Setup;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use WeShop\Product\Model\Product;

class Install implements InstallInterface
{
    /**
     * 安装模块：创建产品默认属性集、属性组及默认属性（主图/子图）
     */
    public function setup(Setup $setup, Context $context): void
    {
        try {
            $this->installProductDefaultAttributeSet();
        } catch (\Throwable $e) {
            w_log_error('产品默认属性集安装失败: ' . $e->getMessage(), [], 'product');
        }
    }

    private function installProductDefaultAttributeSet(): void
    {
        /** @var EavEntity $eavEntity */
        $eavEntity = ObjectManager::make(EavEntity::class)
            ->loadByCode(Product::entity_code);
        $eavEntityId = (int) $eavEntity->getId();

        if ($eavEntityId === 0) {
            return;
        }

        /** @var Set $setModel */
        $setModel = ObjectManager::getInstance(Set::class);
        $set_id = $setModel->clear()->where(Set::schema_fields_code, 'default')
            ->where(Set::schema_fields_eav_entity_id, $eavEntityId)
            ->find()->fetch()['set_id'] ?? 0;

        if ($set_id == 0) {
            $setModel->clear()->setData(Set::schema_fields_code, 'default')
                ->setData(Set::schema_fields_eav_entity_id, $eavEntityId)
                ->setData(Set::schema_fields_name, '默认属性集')
                ->forceCheck(true, [Set::schema_fields_code, Set::schema_fields_eav_entity_id])
                ->save();
            $set_id = (int) $setModel->getId();
        }

        if ($set_id === 0) {
            return;
        }

        /** @var Group $groupModel */
        $groupModel = ObjectManager::getInstance(Group::class);
        $groupModel->clear()
            ->setData(Group::schema_fields_code, 'default')
            ->setData(Group::schema_fields_eav_entity_id, $eavEntityId)
            ->setData(Group::schema_fields_set_id, $set_id)
            ->setData(Group::schema_fields_name, '默认属性组')
            ->forceCheck(true, [
                Group::schema_fields_code,
                Group::schema_fields_eav_entity_id,
            ])
            ->save();

        $group_id = (int) ($groupModel->clear()
            ->where(Group::schema_fields_code, 'default')
            ->where(Group::schema_fields_eav_entity_id, $eavEntityId)
            ->where(Group::schema_fields_set_id, $set_id)
            ->find()->fetch()['group_id'] ?? 0);

        if ($group_id === 0) {
            return;
        }

        /** @var Type $type */
        $type = ObjectManager::getInstance(Type::class);
        $type_id = (int) ($type->clear()
            ->where(Type::fields_code, 'input_string')
            ->find()->fetch()[Type::fields_ID] ?? 0);

        if ($type_id === 0) {
            return;
        }

        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::make(EavAttribute::class);
        $attributeModel->clear()
            ->setData(EavAttribute::schema_fields_code, 'image')
            ->setData(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
            ->setData(EavAttribute::schema_fields_group_id, $group_id)
            ->setData(EavAttribute::schema_fields_set_id, $set_id)
            ->setData(EavAttribute::schema_fields_type_id, $type_id)
            ->setData(EavAttribute::schema_fields_is_system, true)
            ->setData(EavAttribute::schema_fields_name, '图片')
            ->forceCheck(true, [
                EavAttribute::schema_fields_code,
                EavAttribute::schema_fields_eav_entity_id,
            ])
            ->save();

        $attributeModel->clear()
            ->setData(EavAttribute::schema_fields_code, 'images')
            ->setData(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
            ->setData(EavAttribute::schema_fields_group_id, $group_id)
            ->setData(EavAttribute::schema_fields_set_id, $set_id)
            ->setData(EavAttribute::schema_fields_type_id, $type_id)
            ->setData(EavAttribute::schema_fields_is_system, true)
            ->setData(EavAttribute::schema_fields_name, '子图')
            ->forceCheck(true, [
                EavAttribute::schema_fields_code,
                EavAttribute::schema_fields_eav_entity_id,
            ])
            ->save();
    }
}
