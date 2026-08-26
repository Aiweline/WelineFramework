<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\CategoryAttributeEntity;

/**
 * Ensures category EAV attribute set with name and code defaults.
 */
final class ProductCategoryEavBootstrap
{
    /**
     * @return array{entity_id:int,set_id:int,group_id:int,attributes:int}
     */
    public function ensureCategorySchema(): array
    {
        $entityId = $this->resolveEntityId();
        if ($entityId <= 0) {
            return [
                'entity_id' => 0,
                'set_id' => 0,
                'group_id' => 0,
                'attributes' => 0,
            ];
        }

        $setId = $this->ensureSet($entityId, 'category_default', '分类默认');
        $groupId = $this->ensureGroup($entityId, $setId, 'general', '基本信息');
        $typeId = $this->resolveVarcharTypeId();
        $attributeCount = 0;
        foreach ([
            ['code' => 'name', 'name' => '名称', 'required' => true],
            ['code' => 'code', 'name' => 'Code', 'required' => true],
        ] as $attribute) {
            if ($this->ensureAttribute(
                $entityId,
                $setId,
                $groupId,
                $typeId,
                $attribute['code'],
                $attribute['name'],
                $attribute['required'],
            )) {
                ++$attributeCount;
            }
        }

        return [
            'entity_id' => $entityId,
            'set_id' => $setId,
            'group_id' => $groupId,
            'attributes' => $attributeCount,
        ];
    }

    private function resolveEntityId(): int
    {
        /** @var EavEntity $entity */
        $entity = ObjectManager::getInstance(EavEntity::class);
        $entity->clearData()
            ->where(EavEntity::schema_fields_code, CategoryAttributeEntity::entity_code)
            ->find()
            ->fetch();

        return (int)$entity->getId();
    }

    private function resolveVarcharTypeId(): int
    {
        /** @var Type $type */
        $type = ObjectManager::getInstance(Type::class);
        $type->clearData()
            ->where(Type::schema_fields_code, 'input_string_255')
            ->find()
            ->fetch();
        $typeId = (int)$type->getId();
        if ($typeId <= 0) {
            throw new \RuntimeException('EAV input_string_255 type is missing');
        }

        return $typeId;
    }

    private function ensureSet(int $entityId, string $code, string $name): int
    {
        /** @var Set $set */
        $set = ObjectManager::getInstance(Set::class);
        $set->clearData()
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->where(Set::schema_fields_code, $code)
            ->find()
            ->fetch();
        if ((int)$set->getId() > 0) {
            return (int)$set->getId();
        }

        $set->clearData()->insert([
            Set::schema_fields_eav_entity_id => $entityId,
            Set::schema_fields_code => $code,
            Set::schema_fields_name => $name,
        ])->fetch();

        return (int)$set->getId();
    }

    private function ensureGroup(int $entityId, int $setId, string $code, string $name): int
    {
        /** @var Group $group */
        $group = ObjectManager::getInstance(Group::class);
        $group->clearData()
            ->where(Group::schema_fields_eav_entity_id, $entityId)
            ->where(Group::schema_fields_code, $code)
            ->find()
            ->fetch();
        if ((int)$group->getId() > 0) {
            return (int)$group->getId();
        }

        $group->clearData()->insert([
            Group::schema_fields_eav_entity_id => $entityId,
            Group::schema_fields_set_id => $setId,
            Group::schema_fields_code => $code,
            Group::schema_fields_name => $name,
        ])->fetch();

        return (int)$group->getId();
    }

    private function ensureAttribute(
        int $entityId,
        int $setId,
        int $groupId,
        int $typeId,
        string $code,
        string $name,
        bool $required,
    ): bool {
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->clearData()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, $code)
            ->find()
            ->fetch();
        if ((int)$attribute->getId() > 0) {
            return false;
        }

        $attribute->clearData()->insert([
            EavAttribute::schema_fields_eav_entity_id => $entityId,
            EavAttribute::schema_fields_set_id => $setId,
            EavAttribute::schema_fields_group_id => $groupId,
            EavAttribute::schema_fields_type_id => $typeId,
            EavAttribute::schema_fields_code => $code,
            EavAttribute::schema_fields_name => $name,
            EavAttribute::schema_fields_is_required => $required ? 1 : 0,
            EavAttribute::schema_fields_is_user_defined => 0,
        ])->fetch();

        return true;
    }
}
