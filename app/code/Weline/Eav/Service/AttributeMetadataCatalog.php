<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\AttributeStorageException;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Eav\Api\Metadata\CompareMode;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;

/**
 * Eav-owned read model. Consumers receive DTOs and never Eav ORM objects.
 */
final class AttributeMetadataCatalog implements AttributeMetadataCatalogInterface
{
    public function __construct(
        private readonly EavEntity $entityModel,
        private readonly Set $setModel,
        private readonly Group $groupModel,
        private readonly EavAttribute $attributeModel,
        private readonly Type $typeModel,
        private readonly Option $optionModel,
    ) {
    }

    public function catalog(EntityDefinitionInterface $entity): array
    {
        $entityCode = strtolower(trim($entity->getEntityCode()));
        if ($entityCode === '') {
            throw new \InvalidArgumentException('eav_entity_code_invalid');
        }

        $entityRow = clone $this->entityModel;
        $entityRow->clearData()->load(EavEntity::schema_fields_code, $entityCode);
        if (!$entityRow->getId()) {
            throw new AttributeStorageException(
                AttributeStorageException::ENTITY_NOT_REGISTERED,
                $entityCode,
            );
        }
        $entityId = (int)$entityRow->getId();

        $types = [];
        foreach ($this->items($this->typeModel) as $type) {
            if ($type instanceof Type) {
                $types[(int)$type->getId()] = $type;
            }
        }

        $optionsByAttribute = [];
        foreach ($this->items($this->optionModel, Option::schema_fields_eav_entity_id, $entityId) as $option) {
            if (!$option instanceof Option) {
                continue;
            }
            $attributeId = $option->getAttributeId();
            $optionId = $option->getOptionId();
            $code = trim($option->getCode());
            $label = trim($option->getValue());
            $optionsByAttribute[$attributeId][] = new AttributeOptionMetadata(
                id: $optionId,
                value: (string)$optionId,
                code: $code !== '' ? $code : (string)$optionId,
                label: $label !== '' ? $label : ($code !== '' ? $code : (string)$optionId),
                sortOrder: $optionId,
                swatchImage: $option->getSwatchImage(),
                swatchColor: $option->getSwatchColor(),
                swatchText: $option->getSwatchText(),
            );
        }
        foreach ($optionsByAttribute as &$options) {
            usort(
                $options,
                static fn(AttributeOptionMetadata $left, AttributeOptionMetadata $right): int =>
                    [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
            );
        }
        unset($options);

        /** @var array<int, array<string, mixed>> $sets */
        $sets = [];
        foreach ($this->items($this->setModel, Set::schema_fields_eav_entity_id, $entityId) as $set) {
            if (!$set instanceof Set) {
                continue;
            }
            $setId = (int)$set->getId();
            $sets[$setId] = [
                'id' => $setId,
                'entity_id' => $entityId,
                'code' => $this->label((string)$set->getCode(), 'set_' . $setId),
                'name' => $this->label((string)$set->getName(), (string)$set->getCode()),
                'sort_order' => $setId,
                'groups' => [],
            ];
        }

        foreach ($this->items($this->groupModel, Group::schema_fields_eav_entity_id, $entityId) as $group) {
            if (!$group instanceof Group) {
                continue;
            }
            $setId = (int)$group->getSetId();
            $this->ensureSet($sets, $setId, $entityId);
            $groupId = (int)$group->getId();
            $sets[$setId]['groups'][$groupId] = [
                'id' => $groupId,
                'entity_id' => $entityId,
                'set_id' => $setId,
                'code' => $this->label((string)$group->getCode(), 'group_' . $groupId),
                'name' => $this->label((string)$group->getName(), (string)$group->getCode()),
                'sort_order' => $groupId,
                'attributes' => [],
            ];
        }

        foreach ($this->items($this->attributeModel, EavAttribute::schema_fields_eav_entity_id, $entityId) as $attribute) {
            if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
                continue;
            }
            $setId = $attribute->getSetId();
            $groupId = $attribute->getGroupId();
            $this->ensureSet($sets, $setId, $entityId);
            $this->ensureGroup($sets[$setId]['groups'], $groupId, $setId, $entityId);

            $type = $types[$attribute->getTypeId()] ?? null;
            $typeCode = $type instanceof Type ? trim($type->getCode()) : 'string';
            $fieldType = $type instanceof Type ? trim($type->getFieldType()) : 'string';
            $element = $type instanceof Type ? trim($type->getElement()) : 'input';
            $attributeId = $attribute->getAttributeId();
            $sets[$setId]['groups'][$groupId]['attributes'][] = new AttributeMetadata(
                id: $attributeId,
                entityId: $entityId,
                code: $attribute->getCode(),
                name: $this->label($attribute->getName(), $attribute->getCode()),
                typeCode: $typeCode !== '' ? $typeCode : 'string',
                fieldType: $fieldType !== '' ? $fieldType : 'string',
                element: $element !== '' ? $element : 'input',
                setId: $setId,
                groupId: $groupId,
                required: $type instanceof Type && $type->getRequired(),
                multiple: $attribute->getMultipleValued(),
                enabled: (bool)$attribute->isEnable(),
                hasOption: (bool)$attribute->hasOption(),
                sortOrder: $attributeId,
                options: $optionsByAttribute[$attributeId] ?? [],
                compareMode: CompareMode::normalize((string)$attribute->getData(EavAttribute::schema_fields_compare_mode)),
            );
        }

        $result = [];
        foreach ($sets as $set) {
            $groups = [];
            foreach ($set['groups'] as $group) {
                usort(
                    $group['attributes'],
                    static fn(AttributeMetadata $left, AttributeMetadata $right): int =>
                        [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
                );
                $groups[] = new AttributeGroupMetadata(
                    id: $group['id'],
                    entityId: $group['entity_id'],
                    setId: $group['set_id'],
                    code: $group['code'],
                    name: $group['name'],
                    sortOrder: $group['sort_order'],
                    attributes: $group['attributes'],
                );
            }
            usort(
                $groups,
                static fn(AttributeGroupMetadata $left, AttributeGroupMetadata $right): int =>
                    [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
            );
            $result[] = new AttributeSetMetadata(
                id: $set['id'],
                entityId: $set['entity_id'],
                code: $set['code'],
                name: $set['name'],
                sortOrder: $set['sort_order'],
                groups: $groups,
            );
        }
        usort(
            $result,
            static fn(AttributeSetMetadata $left, AttributeSetMetadata $right): int =>
                [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
        );

        return $result;
    }

    public function attributeIndexByEntityCode(string $entityCode): array
    {
        $entityCode = strtolower(trim($entityCode));
        if ($entityCode === '') {
            return [];
        }

        $entityRow = clone $this->entityModel;
        $entityRow->clearData()->load(EavEntity::schema_fields_code, $entityCode);
        if (!$entityRow->getId()) {
            return [];
        }

        $types = [];
        foreach ($this->items($this->typeModel) as $type) {
            if ($type instanceof Type) {
                $types[(int)$type->getId()] = $type;
            }
        }

        $index = [];
        foreach ($this->items($this->attributeModel, EavAttribute::schema_fields_eav_entity_id, (int)$entityRow->getId()) as $attribute) {
            if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
                continue;
            }
            $code = strtolower(trim($attribute->getCode()));
            if ($code === '') {
                continue;
            }
            $typeModel = $types[$attribute->getTypeId()] ?? null;
            $typeCode = $typeModel instanceof Type ? trim($typeModel->getCode()) : 'string';
            $fieldType = $typeModel instanceof Type ? trim($typeModel->getFieldType()) : 'string';
            $element = $typeModel instanceof Type ? trim($typeModel->getElement()) : 'input';
            $index[$code] = new AttributeMetadata(
                id: $attribute->getAttributeId(),
                entityId: (int)$entityRow->getId(),
                code: $attribute->getCode(),
                name: $this->label($attribute->getName(), $attribute->getCode()),
                typeCode: $typeCode !== '' ? $typeCode : 'string',
                fieldType: $fieldType !== '' ? $fieldType : 'string',
                element: $element !== '' ? $element : 'input',
                setId: $attribute->getSetId(),
                groupId: $attribute->getGroupId(),
                required: $typeModel instanceof Type && $typeModel->getRequired(),
                multiple: $attribute->getMultipleValued(),
                enabled: (bool)$attribute->isEnable(),
                hasOption: (bool)$attribute->hasOption(),
                sortOrder: $attribute->getAttributeId(),
                compareMode: CompareMode::normalize((string)$attribute->getData(EavAttribute::schema_fields_compare_mode)),
            );
        }

        return $index;
    }

    /**
     * @return list<object>
     */
    private function items(object $prototype, ?string $field = null, mixed $value = null): array
    {
        $query = clone $prototype;
        $query->reset()->clearData();
        if ($field !== null) {
            $query->where($field, $value);
        }

        return array_values(array_filter(
            $query->select()->fetch()->getItems(),
            'is_object',
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $sets
     */
    private function ensureSet(array &$sets, int $setId, int $entityId): void
    {
        if (isset($sets[$setId])) {
            return;
        }
        $sets[$setId] = [
            'id' => $setId,
            'entity_id' => $entityId,
            'code' => $setId > 0 ? 'legacy_set_' . $setId : 'ungrouped',
            'name' => $setId > 0 ? '历史属性集 #' . $setId : '未分组属性',
            'sort_order' => $setId,
            'groups' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private function ensureGroup(array &$groups, int $groupId, int $setId, int $entityId): void
    {
        if (isset($groups[$groupId])) {
            return;
        }
        $groups[$groupId] = [
            'id' => $groupId,
            'entity_id' => $entityId,
            'set_id' => $setId,
            'code' => $groupId > 0 ? 'legacy_group_' . $groupId : 'general',
            'name' => $groupId > 0 ? '历史属性组 #' . $groupId : '常规',
            'sort_order' => $groupId,
            'attributes' => [],
        ];
    }

    private function label(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }
}
