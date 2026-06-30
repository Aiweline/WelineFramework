<?php

declare(strict_types=1);

namespace Weline\Payment\Helper;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentMethodAttributeEntity;

class PaymentMethodAttributeHelper
{
    public const DEFAULT_SET_CODE = 'default';
    public const DEFAULT_GROUP_CODE = 'default';
    public const DEFAULT_TYPE_CODE = 'input_string';

    public function __construct(
        private readonly PaymentMethodAttributeEntity $methodEntity,
        private readonly EavAttribute $attribute,
        private readonly Type $type,
        private readonly Set $set,
        private readonly Group $group
    ) {
    }

    public function declareAttribute(
        string $attributeCode,
        string $label,
        string $typeCode = self::DEFAULT_TYPE_CODE,
        bool $isMultiple = false,
        bool $hasOption = false,
        string $setCode = self::DEFAULT_SET_CODE,
        string $groupCode = self::DEFAULT_GROUP_CODE
    ): EavAttribute {
        $attributeCode = $this->normalizeCode($attributeCode, 'payment_attribute_code_invalid');
        $label = trim($label) ?: $attributeCode;
        $entity = $this->requireEavEntity();
        $type = $this->requireType($typeCode);
        $set = $this->requireSet((int) $entity->getId(), $setCode);
        $group = $this->requireGroup((int) $entity->getId(), (int) $set->getId(), $groupCode);

        $attribute = $this->findAttribute($attributeCode);
        if (!$attribute->getId()) {
            $attribute = ObjectManager::make(EavAttribute::class);
        }

        $this->methodEntity->syncAttributeSequence();

        $attribute
            ->clearData()
            ->setData([
                EavAttribute::schema_fields_code => $attributeCode,
                EavAttribute::schema_fields_name => $label,
                EavAttribute::schema_fields_type_id => (int) $type->getId(),
                EavAttribute::schema_fields_set_id => (int) $set->getId(),
                EavAttribute::schema_fields_group_id => (int) $group->getId(),
                EavAttribute::schema_fields_eav_entity_id => (int) $entity->getId(),
                EavAttribute::schema_fields_is_system => 0,
                EavAttribute::schema_fields_basic_is_enable => 1,
                EavAttribute::schema_fields_data_is_multiple => $isMultiple ? 1 : 0,
                EavAttribute::schema_fields_data_has_option => $hasOption ? 1 : 0,
            ])
            ->forceCheck(true, [EavAttribute::schema_fields_eav_entity_id, EavAttribute::schema_fields_code])
            ->save();

        $attribute = $this->findAttribute($attributeCode);
        $this->bindAttributeEntity($attribute);

        return $attribute;
    }

    public function getAttribute(string $attributeCode): ?EavAttribute
    {
        $attribute = $this->findAttribute($attributeCode);
        if (!$attribute->getId()) {
            return null;
        }

        $this->bindAttributeEntity($attribute);

        return $attribute;
    }

    public function getValue(string $methodCode, string $attributeCode, mixed $default = null): mixed
    {
        $method = $this->requireMethod($methodCode);
        $attribute = $this->getAttribute($attributeCode);
        if (!$attribute) {
            return $default;
        }

        $this->bindAttributeEntity($attribute, $method);
        $value = $this->readAttributeValue($attribute, (int) $method->getId());

        return $value === '' || $value === [] ? $default : $value;
    }

    /**
     * @param array<int, string>|null $attributeCodes
     * @return array<string, mixed>
     */
    public function getValues(string $methodCode, ?array $attributeCodes = null): array
    {
        $method = $this->requireMethod($methodCode);
        $attributes = $attributeCodes === null
            ? $this->getAttributes()
            : array_filter(array_map(fn(string $code): ?EavAttribute => $this->getAttribute($code), $attributeCodes));
        $values = [];

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof EavAttribute) {
                continue;
            }
            $this->bindAttributeEntity($attribute, $method);
            $values[$attribute->getCode()] = $this->readAttributeValue($attribute, (int) $method->getId());
        }

        return $values;
    }

    /**
     * @param string|int|array<int, string|int> $value
     */
    public function setValue(
        string $methodCode,
        string $attributeCode,
        string|int|array $value,
        array $definition = []
    ): EavAttribute {
        $method = $this->requireMethod($methodCode);
        $attribute = $this->getAttribute($attributeCode);

        if (!$attribute) {
            $attribute = $this->declareAttribute(
                $attributeCode,
                (string) ($definition['label'] ?? $attributeCode),
                (string) ($definition['type'] ?? self::DEFAULT_TYPE_CODE),
                (bool) ($definition['multiple'] ?? \is_array($value)),
                (bool) ($definition['has_option'] ?? false),
                (string) ($definition['set'] ?? self::DEFAULT_SET_CODE),
                (string) ($definition['group'] ?? self::DEFAULT_GROUP_CODE)
            );
        }

        $this->bindAttributeEntity($attribute, $method);
        $this->writeAttributeValue($attribute, (int) $method->getId(), $this->normalizeValue($value));
        $attribute->unsetData(EavAttribute::value_key);

        return $attribute;
    }

    /**
     * @param array<string, string|int|array<int, string|int>> $values
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    public function setValues(string $methodCode, array $values, array $definitions = []): array
    {
        $saved = [];

        foreach ($values as $attributeCode => $value) {
            $attributeCode = (string) $attributeCode;
            $saved[$attributeCode] = $this->setValue(
                $methodCode,
                $attributeCode,
                $value,
                $definitions[$attributeCode] ?? []
            );
            $saved[$attributeCode] = $this->getValue($methodCode, $attributeCode);
        }

        return $saved;
    }

    /**
     * @return array<int, EavAttribute>
     */
    public function getAttributes(): array
    {
        $entity = $this->requireEavEntity();
        $attributes = $this->attribute
            ->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, (int) $entity->getId())
            ->select()
            ->fetch()
            ->getItems();

        foreach ($attributes as $attribute) {
            if ($attribute instanceof EavAttribute) {
                $this->bindAttributeEntity($attribute);
            }
        }

        return $attributes;
    }

    public function getEntityCode(): string
    {
        return PaymentMethodAttributeEntity::entity_code;
    }

    private function requireMethod(string $methodCode): PaymentMethodAttributeEntity
    {
        $methodCode = $this->normalizeMethodCode($methodCode);
        $method = ObjectManager::make(PaymentMethodAttributeEntity::class);
        $method->load(PaymentMethodAttributeEntity::schema_fields_CODE, $methodCode);

        if (!$method->getId()) {
            throw new \InvalidArgumentException('payment_method_not_found:' . $methodCode);
        }

        return $method;
    }

    private function findAttribute(string $attributeCode): EavAttribute
    {
        $attributeCode = $this->normalizeCode($attributeCode, 'payment_attribute_code_invalid');
        $entity = $this->requireEavEntity();
        $attribute = ObjectManager::make(EavAttribute::class);
        $attribute
            ->where(EavAttribute::schema_fields_eav_entity_id, (int) $entity->getId())
            ->where(EavAttribute::schema_fields_code, $attributeCode)
            ->find()
            ->fetch();

        return $attribute;
    }

    private function requireEavEntity(): EavEntity
    {
        $entity = ObjectManager::make(EavEntity::class);
        $entity->load(EavEntity::schema_fields_code, PaymentMethodAttributeEntity::entity_code);

        if (!$entity->getId()) {
            throw new \RuntimeException('payment_method_eav_entity_not_registered');
        }

        return $entity;
    }

    private function requireType(string $typeCode): Type
    {
        $typeCode = $this->normalizeCode($typeCode, 'payment_attribute_type_invalid');
        $type = ObjectManager::make(Type::class);
        $type->load(Type::schema_fields_code, $typeCode);

        if (!$type->getId()) {
            throw new \InvalidArgumentException('payment_attribute_type_not_found:' . $typeCode);
        }

        return $type;
    }

    private function requireSet(int $entityId, string $setCode): Set
    {
        $setCode = $this->normalizeCode($setCode, 'payment_attribute_set_invalid');
        $set = ObjectManager::make(Set::class);
        $set
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->where(Set::schema_fields_code, $setCode)
            ->find()
            ->fetch();

        if (!$set->getId()) {
            throw new \RuntimeException('payment_attribute_set_not_found:' . $setCode);
        }

        return $set;
    }

    private function requireGroup(int $entityId, int $setId, string $groupCode): Group
    {
        $groupCode = $this->normalizeCode($groupCode, 'payment_attribute_group_invalid');
        $group = ObjectManager::make(Group::class);
        $group
            ->where(Group::schema_fields_eav_entity_id, $entityId)
            ->where(Group::schema_fields_set_id, $setId)
            ->where(Group::schema_fields_code, $groupCode)
            ->find()
            ->fetch();

        if (!$group->getId()) {
            throw new \RuntimeException('payment_attribute_group_not_found:' . $groupCode);
        }

        return $group;
    }

    private function bindAttributeEntity(EavAttribute $attribute, ?PaymentMethodAttributeEntity $method = null): void
    {
        $entity = $method ?? $this->methodEntity;
        $attribute->current_setEntity($entity);
        $attribute->resetTypeModel();
    }

    private function readAttributeValue(EavAttribute $attribute, int $methodId): mixed
    {
        $valueModel = $attribute->w_getValueModel();
        $valueModel
            ->reset()
            ->fields(Value::schema_fields_value)
            ->where(Value::schema_fields_attribute_id, $this->getAttributeId($attribute))
            ->where(Value::schema_fields_entity_id, $methodId);

        if ($attribute->getMultipleValued()) {
            return array_map(
                static fn(array $row): mixed => $row[Value::schema_fields_value] ?? null,
                $valueModel->select()->fetchArray()
            );
        }

        $row = $valueModel->find()->fetchArray();

        return $row[Value::schema_fields_value] ?? '';
    }

    private function writeAttributeValue(EavAttribute $attribute, int $methodId, string|int|array $value): void
    {
        $attributeId = $this->getAttributeId($attribute);
        $valueModel = $attribute->w_getValueModel();
        $this->deleteAttributeValues($valueModel, $attributeId, $methodId);

        $items = \is_array($value) ? $value : [$value];
        if (!$attribute->getMultipleValued() && \count($items) > 1) {
            throw new \InvalidArgumentException('payment_attribute_single_value_expected:' . $attribute->getCode());
        }
        if ($items === []) {
            return;
        }

        $rows = array_map(
            static fn(string|int $item): array => [
                Value::schema_fields_attribute_id => $attributeId,
                Value::schema_fields_entity_id => $methodId,
                Value::schema_fields_value => $item,
            ],
            $items
        );

        $valueModel
            ->reset()
            ->insert($rows, [Value::schema_fields_entity_id, Value::schema_fields_attribute_id, Value::schema_fields_value])
            ->fetch();
    }

    private function deleteAttributeValues(Value $valueModel, int $attributeId, int $methodId): void
    {
        $connector = $valueModel->getConnection()->getConnector();
        $connectorClass = $connector::class;
        $table = $this->quoteQualifiedIdentifier($valueModel->getTable(), $connectorClass);
        $attributeField = $this->quoteIdentifier(Value::schema_fields_attribute_id, $connectorClass);
        $entityField = $this->quoteIdentifier(Value::schema_fields_entity_id, $connectorClass);

        $connector
            ->query("DELETE FROM {$table} WHERE {$attributeField} = {$attributeId} AND {$entityField} = {$methodId}")
            ->fetch();
    }

    private function getAttributeId(EavAttribute $attribute): int
    {
        $attributeId = (int) $attribute->getData(EavAttribute::schema_fields_attribute_id);
        if ($attributeId <= 0) {
            throw new \RuntimeException('payment_attribute_id_missing:' . $attribute->getCode());
        }

        return $attributeId;
    }

    private function quoteQualifiedIdentifier(string $identifier, string $connectorClass): string
    {
        $parts = array_filter(
            array_map(static fn(string $part): string => trim($part, "`\" \t\n\r\0\x0B"), explode('.', $identifier)),
            static fn(string $part): bool => $part !== ''
        );

        return implode('.', array_map(
            fn(string $part): string => $this->quoteIdentifier($part, $connectorClass),
            $parts
        ));
    }

    private function quoteIdentifier(string $identifier, string $connectorClass): string
    {
        $quote = str_contains(strtolower($connectorClass), 'mysql') ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function normalizeMethodCode(string $methodCode): string
    {
        return $this->normalizeCode($methodCode, 'payment_method_code_invalid');
    }

    private function normalizeCode(string $code, string $errorCode): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $code)) {
            throw new \InvalidArgumentException($errorCode . ':' . $code);
        }

        return $code;
    }

    /**
     * @param string|int|array<int, string|int> $value
     * @return string|int|array<int, string|int>
     */
    private function normalizeValue(string|int|array $value): string|int|array
    {
        if (\is_array($value)) {
            return array_values(array_map(
                static fn(string|int $item): string|int => \is_int($item) ? $item : trim((string) $item),
                $value
            ));
        }

        return \is_int($value) ? $value : trim($value);
    }
}
