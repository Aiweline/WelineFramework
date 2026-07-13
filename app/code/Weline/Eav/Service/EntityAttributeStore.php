<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\AttributeDefinition;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\AttributeStorageException;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface as DdlTableInterface;
use Weline\Framework\Setup\Db\ModelSetup;

final class EntityAttributeStore implements EntityAttributeStoreInterface
{
    public function __construct(
        private readonly EavEntity $entityModel,
        private readonly EavAttribute $attributeModel,
        private readonly Type $typeModel,
        private readonly Set $setModel,
        private readonly Group $groupModel,
        private readonly EntityAttributeValueTable $valueTableModel,
    ) {
    }

    public function provisionValueTables(EntityDefinitionInterface $entity, ModelSetup $setup): void
    {
        $entityCode = $this->normalizeCode($entity->getEntityCode(), 'eav_entity_code_invalid');
        $types = (clone $this->typeModel)->clear()->select()->fetch()->getItems();

        foreach ($types as $type) {
            if (!$type instanceof Type) {
                continue;
            }

            $typeCode = $this->normalizeCode((string)$type->getCode(), 'eav_attribute_type_invalid');
            $fieldType = trim((string)$type->getFieldType());
            if ($fieldType === '') {
                continue;
            }

            $tableName = $setup->getTable($this->valueTableName($entityCode, $typeCode));
            if ($setup->tableExist($tableName)) {
                continue;
            }

            $table = $setup->createTable(
                $entity->getEntityName() . ' EAV ' . $typeCode . ' 类型数据表',
                $tableName,
            );
            $table
                ->addColumn(
                    Value::schema_fields_value_id,
                    DdlTableInterface::column_type_BIGINT,
                    18,
                    'primary key auto_increment',
                    '属性值ID',
                )
                ->addColumn(
                    Value::schema_fields_attribute_id,
                    DdlTableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '属性ID',
                )
                ->addColumn(
                    Value::schema_fields_entity_id,
                    $entity->getEntityFieldIdType(),
                    $entity->getEntityFieldIdLength(),
                    'not null',
                    '实体ID',
                )
                ->addColumn(
                    Value::schema_fields_value,
                    $fieldType,
                    $type->getFieldLength(),
                    'not null',
                    '属性值',
                );

            if ($type->getIsSwatch()) {
                $table->addColumn(
                    Type::schema_fields_is_swatch,
                    DdlTableInterface::column_type_BOOLEAN,
                    0,
                    'default 0',
                    '是否有样本',
                );
                if ($type->hasSwatchImage()) {
                    $table->addColumn(
                        Type::schema_fields_swatch_image,
                        DdlTableInterface::column_type_VARCHAR,
                        255,
                        '',
                        '样本图片',
                    );
                }
                if ($type->hasSwatchColor()) {
                    $table->addColumn(
                        Type::schema_fields_swatch_color,
                        DdlTableInterface::column_type_VARCHAR,
                        255,
                        '',
                        '样本颜色',
                    );
                }
                if ($type->hasSwatchText()) {
                    $table->addColumn(
                        Type::schema_fields_swatch_text,
                        DdlTableInterface::column_type_VARCHAR,
                        255,
                        '',
                        '样本文本',
                    );
                }
            }

            $table
                ->addIndex(
                    DdlTableInterface::index_type_KEY,
                    $tableName . '_idx_ATTRIBUTE_ID',
                    Value::schema_fields_attribute_id,
                )
                ->addIndex(
                    DdlTableInterface::index_type_KEY,
                    $tableName . '_idx_ENTITY_ID',
                    Value::schema_fields_entity_id,
                )
                ->create();
        }
    }

    public function syncAttributeSequence(): void
    {
        $attribute = clone $this->attributeModel;
        $connector = $attribute->getConnection()->getConnector();
        $connectorClass = $connector::class;
        if (!str_contains(strtolower($connectorClass), 'pgsql')) {
            return;
        }

        $table = $attribute->getTable();
        $idField = EavAttribute::schema_fields_ID;
        $tableLiteral = str_replace("'", "''", str_replace('"', '', $table));
        $idLiteral = str_replace("'", "''", $idField);
        $quotedTable = $this->quoteQualifiedIdentifier($table);
        $quotedId = '"' . str_replace('"', '""', $idField) . '"';

        $connector->query(
            "SELECT setval(pg_get_serial_sequence('{$tableLiteral}', '{$idLiteral}'), "
            . "GREATEST(COALESCE((SELECT MAX({$quotedId}) FROM {$quotedTable}), 1), 1), true)",
        )->fetch();
    }

    public function declareAttribute(
        EntityDefinitionInterface $entity,
        AttributeDefinition $definition,
    ): AttributeRecord {
        $attributeCode = $this->normalizeCode($definition->code, 'eav_attribute_code_invalid');
        $entityRow = $this->requireEntity($entity->getEntityCode());
        $type = $this->requireType($definition->typeCode);
        $set = $this->requireSet((int)$entityRow->getId(), $definition->setCode);
        $group = $this->requireGroup(
            (int)$entityRow->getId(),
            (int)$set->getId(),
            $definition->groupCode,
        );

        $attribute = $this->findAttributeByEntityId((int)$entityRow->getId(), $attributeCode);
        if ($attribute === null) {
            $attribute = clone $this->attributeModel;
        }

        $this->syncAttributeSequence();
        $attribute
            ->clearData()
            ->setData([
                EavAttribute::schema_fields_code => $attributeCode,
                EavAttribute::schema_fields_name => trim($definition->name) ?: $attributeCode,
                EavAttribute::schema_fields_type_id => (int)$type->getId(),
                EavAttribute::schema_fields_set_id => (int)$set->getId(),
                EavAttribute::schema_fields_group_id => (int)$group->getId(),
                EavAttribute::schema_fields_eav_entity_id => (int)$entityRow->getId(),
                EavAttribute::schema_fields_is_system => $definition->system ? 1 : 0,
                EavAttribute::schema_fields_basic_is_enable => $definition->enabled ? 1 : 0,
                EavAttribute::schema_fields_data_is_multiple => $definition->multiple ? 1 : 0,
                EavAttribute::schema_fields_data_has_option => $definition->hasOption ? 1 : 0,
            ])
            ->forceCheck(
                true,
                [EavAttribute::schema_fields_eav_entity_id, EavAttribute::schema_fields_code],
            )
            ->save();

        $saved = $this->findAttributeByEntityId((int)$entityRow->getId(), $attributeCode);
        if ($saved === null) {
            throw new AttributeStorageException(
                AttributeStorageException::ATTRIBUTE_ID_MISSING,
                $attributeCode,
            );
        }

        return $this->toRecord($saved, $type);
    }

    public function getAttribute(
        EntityDefinitionInterface $entity,
        string $attributeCode,
    ): ?AttributeRecord {
        $entityRow = $this->requireEntity($entity->getEntityCode());
        $attribute = $this->findAttributeByEntityId(
            (int)$entityRow->getId(),
            $this->normalizeCode($attributeCode, 'eav_attribute_code_invalid'),
        );
        if ($attribute === null) {
            return null;
        }

        return $this->toRecord($attribute, $this->requireTypeById($attribute->getTypeId()));
    }

    public function getAttributes(EntityDefinitionInterface $entity): array
    {
        $entityRow = $this->requireEntity($entity->getEntityCode());
        $attributes = (clone $this->attributeModel)
            ->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, (int)$entityRow->getId())
            ->select()
            ->fetch()
            ->getItems();
        $records = [];

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof EavAttribute) {
                continue;
            }
            $records[] = $this->toRecord($attribute, $this->requireTypeById($attribute->getTypeId()));
        }

        return $records;
    }

    public function readValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
    ): mixed {
        $this->assertAttributeEntity($entity, $attribute);
        $valueModel = $this->valueModel($entity, $attribute);
        $valueModel
            ->reset()
            ->fields(Value::schema_fields_value)
            ->where(Value::schema_fields_attribute_id, $attribute->id)
            ->where(Value::schema_fields_entity_id, $ownerId);

        if ($attribute->multiple) {
            return array_map(
                static fn(array $row): mixed => $row[Value::schema_fields_value] ?? null,
                $valueModel->select()->fetchArray(),
            );
        }

        $row = $valueModel->find()->fetchArray();

        return $row[Value::schema_fields_value] ?? '';
    }

    public function replaceValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
        string|int|array $value,
    ): void {
        $this->assertAttributeEntity($entity, $attribute);
        $items = is_array($value) ? array_values($value) : [$value];
        if (!$attribute->multiple && count($items) > 1) {
            throw new \InvalidArgumentException('eav_attribute_single_value_expected:' . $attribute->code);
        }

        $valueModel = $this->valueModel($entity, $attribute);
        $valueModel
            ->reset()
            ->where(Value::schema_fields_attribute_id, $attribute->id)
            ->where(Value::schema_fields_entity_id, $ownerId)
            ->delete()
            ->fetch();

        if ($items === []) {
            return;
        }

        $rows = array_map(
            static fn(string|int $item): array => [
                Value::schema_fields_attribute_id => $attribute->id,
                Value::schema_fields_entity_id => $ownerId,
                Value::schema_fields_value => $item,
            ],
            $items,
        );
        $valueModel
            ->reset()
            ->insert(
                $rows,
                [
                    Value::schema_fields_entity_id,
                    Value::schema_fields_attribute_id,
                    Value::schema_fields_value,
                ],
            )
            ->fetch();
    }

    private function requireEntity(string $entityCode): EavEntity
    {
        $entityCode = $this->normalizeCode($entityCode, 'eav_entity_code_invalid');
        $entity = clone $this->entityModel;
        $entity->clearData()->load(EavEntity::schema_fields_code, $entityCode);
        if (!$entity->getId()) {
            throw new AttributeStorageException(
                AttributeStorageException::ENTITY_NOT_REGISTERED,
                $entityCode,
            );
        }

        return $entity;
    }

    private function requireType(string $typeCode): Type
    {
        $typeCode = $this->normalizeCode($typeCode, 'eav_attribute_type_invalid');
        $type = clone $this->typeModel;
        $type->clearData()->load(Type::schema_fields_code, $typeCode);
        if (!$type->getId()) {
            throw new AttributeStorageException(AttributeStorageException::TYPE_NOT_FOUND, $typeCode);
        }

        return $type;
    }

    private function requireTypeById(int $typeId): Type
    {
        $type = clone $this->typeModel;
        $type->clearData()->load($typeId);
        if (!$type->getId()) {
            throw new AttributeStorageException(
                AttributeStorageException::TYPE_NOT_FOUND,
                (string)$typeId,
            );
        }

        return $type;
    }

    private function requireSet(int $entityId, string $setCode): Set
    {
        $setCode = $this->normalizeCode($setCode, 'eav_attribute_set_invalid');
        $set = clone $this->setModel;
        $set
            ->reset()
            ->clearData()
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->where(Set::schema_fields_code, $setCode)
            ->find()
            ->fetch();
        if (!$set->getId()) {
            throw new AttributeStorageException(AttributeStorageException::SET_NOT_FOUND, $setCode);
        }

        return $set;
    }

    private function requireGroup(int $entityId, int $setId, string $groupCode): Group
    {
        $groupCode = $this->normalizeCode($groupCode, 'eav_attribute_group_invalid');
        $group = clone $this->groupModel;
        $group
            ->reset()
            ->clearData()
            ->where(Group::schema_fields_eav_entity_id, $entityId)
            ->where(Group::schema_fields_set_id, $setId)
            ->where(Group::schema_fields_code, $groupCode)
            ->find()
            ->fetch();
        if (!$group->getId()) {
            throw new AttributeStorageException(AttributeStorageException::GROUP_NOT_FOUND, $groupCode);
        }

        return $group;
    }

    private function findAttributeByEntityId(int $entityId, string $attributeCode): ?EavAttribute
    {
        $attribute = clone $this->attributeModel;
        $attribute
            ->reset()
            ->clearData()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, $attributeCode)
            ->find()
            ->fetch();

        return $attribute->getAttributeId() > 0 ? $attribute : null;
    }

    private function toRecord(EavAttribute $attribute, Type $type): AttributeRecord
    {
        $attributeId = $attribute->getAttributeId();
        if ($attributeId <= 0) {
            throw new AttributeStorageException(
                AttributeStorageException::ATTRIBUTE_ID_MISSING,
                $attribute->getCode(),
            );
        }

        return new AttributeRecord(
            id: $attributeId,
            entityId: $attribute->getEavEntityId(),
            code: $attribute->getCode(),
            name: $attribute->getName(),
            typeId: $attribute->getTypeId(),
            typeCode: (string)$type->getCode(),
            setId: (int)$attribute->getData(EavAttribute::schema_fields_set_id),
            groupId: (int)$attribute->getData(EavAttribute::schema_fields_group_id),
            multiple: $attribute->getMultipleValued(),
            hasOption: (bool)$attribute->hasOption(),
        );
    }

    private function valueModel(
        EntityDefinitionInterface $entity,
        AttributeRecord $attribute,
    ): EntityAttributeValueTable {
        $entityCode = $this->normalizeCode($entity->getEntityCode(), 'eav_entity_code_invalid');
        $typeCode = $this->normalizeCode($attribute->typeCode, 'eav_attribute_type_invalid');

        return (clone $this->valueTableModel)->useLogicalTable(
            $this->valueTableName($entityCode, $typeCode),
        );
    }

    private function assertAttributeEntity(
        EntityDefinitionInterface $entity,
        AttributeRecord $attribute,
    ): void {
        $entityRow = $this->requireEntity($entity->getEntityCode());
        if ((int)$entityRow->getId() !== $attribute->entityId) {
            throw new \InvalidArgumentException('eav_attribute_entity_mismatch:' . $attribute->code);
        }
    }

    private function valueTableName(string $entityCode, string $typeCode): string
    {
        return 'eav_' . $entityCode . '_' . $typeCode;
    }

    private function normalizeCode(string $code, string $errorCode): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $code)) {
            throw new \InvalidArgumentException($errorCode . ':' . $code);
        }

        return $code;
    }

    private function quoteQualifiedIdentifier(string $identifier): string
    {
        $parts = array_filter(
            array_map(
                static fn(string $part): string => trim($part, "\" \t\n\r\0\x0B"),
                explode('.', $identifier),
            ),
            static fn(string $part): bool => $part !== '',
        );

        return implode(
            '.',
            array_map(
                static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"',
                $parts,
            ),
        );
    }
}
