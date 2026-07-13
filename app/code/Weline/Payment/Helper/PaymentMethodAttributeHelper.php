<?php

declare(strict_types=1);

namespace Weline\Payment\Helper;

use Weline\Eav\Api\Attribute\AttributeDefinition;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\AttributeStorageException;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Payment\Model\PaymentMethodAttributeEntity;

class PaymentMethodAttributeHelper
{
    public const DEFAULT_SET_CODE = 'default';
    public const DEFAULT_GROUP_CODE = 'default';
    public const DEFAULT_TYPE_CODE = 'input_string';

    private ?EntityAttributeStoreInterface $attributeStore = null;

    public function __construct(
        private readonly PaymentMethodAttributeEntity $methodEntity,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function declareAttribute(
        string $attributeCode,
        string $label,
        string $typeCode = self::DEFAULT_TYPE_CODE,
        bool $isMultiple = false,
        bool $hasOption = false,
        string $setCode = self::DEFAULT_SET_CODE,
        string $groupCode = self::DEFAULT_GROUP_CODE,
    ): AttributeRecord {
        $attributeCode = $this->normalizeCode($attributeCode, 'payment_attribute_code_invalid');
        $label = trim($label) ?: $attributeCode;
        $typeCode = $this->normalizeCode($typeCode, 'payment_attribute_type_invalid');
        $setCode = $this->normalizeCode($setCode, 'payment_attribute_set_invalid');
        $groupCode = $this->normalizeCode($groupCode, 'payment_attribute_group_invalid');

        return $this->storeCall(fn(): AttributeRecord => $this->store()->declareAttribute(
            $this->methodEntity,
            new AttributeDefinition(
                code: $attributeCode,
                name: $label,
                typeCode: $typeCode,
                multiple: $isMultiple,
                hasOption: $hasOption,
                setCode: $setCode,
                groupCode: $groupCode,
            ),
        ));
    }

    public function getAttribute(string $attributeCode): ?AttributeRecord
    {
        $attributeCode = $this->normalizeCode($attributeCode, 'payment_attribute_code_invalid');

        return $this->storeCall(
            fn(): ?AttributeRecord => $this->store()->getAttribute($this->methodEntity, $attributeCode),
        );
    }

    public function getValue(string $methodCode, string $attributeCode, mixed $default = null): mixed
    {
        $method = $this->requireMethod($methodCode);
        $attribute = $this->getAttribute($attributeCode);
        if ($attribute === null) {
            return $default;
        }

        $value = $this->storeCall(
            fn(): mixed => $this->store()->readValue($method, (int)$method->getId(), $attribute),
        );

        return $value === '' || $value === [] ? $default : $value;
    }

    /**
     * @param list<string>|null $attributeCodes
     * @return array<string, mixed>
     */
    public function getValues(string $methodCode, ?array $attributeCodes = null): array
    {
        $method = $this->requireMethod($methodCode);
        $attributes = $attributeCodes === null
            ? $this->getAttributes()
            : array_values(array_filter(array_map(
                fn(string $code): ?AttributeRecord => $this->getAttribute($code),
                $attributeCodes,
            )));
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute->code] = $this->storeCall(
                fn(): mixed => $this->store()->readValue($method, (int)$method->getId(), $attribute),
            );
        }

        return $values;
    }

    /**
     * @param string|int|list<string|int> $value
     * @param array<string, mixed> $definition
     */
    public function setValue(
        string $methodCode,
        string $attributeCode,
        string|int|array $value,
        array $definition = [],
    ): AttributeRecord {
        $method = $this->requireMethod($methodCode);
        $attribute = $this->getAttribute($attributeCode);

        if ($attribute === null) {
            $attribute = $this->declareAttribute(
                $attributeCode,
                (string)($definition['label'] ?? $attributeCode),
                (string)($definition['type'] ?? self::DEFAULT_TYPE_CODE),
                (bool)($definition['multiple'] ?? is_array($value)),
                (bool)($definition['has_option'] ?? false),
                (string)($definition['set'] ?? self::DEFAULT_SET_CODE),
                (string)($definition['group'] ?? self::DEFAULT_GROUP_CODE),
            );
        }

        $value = $this->normalizeValue($value);
        if (is_array($value) && !$attribute->multiple && count($value) > 1) {
            throw new \InvalidArgumentException(
                'payment_attribute_single_value_expected:' . $attribute->code,
            );
        }

        $this->storeCall(function () use ($method, $attribute, $value): void {
            $this->store()->replaceValue($method, (int)$method->getId(), $attribute, $value);
        });

        return $attribute;
    }

    /**
     * @param array<string, string|int|list<string|int>> $values
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    public function setValues(string $methodCode, array $values, array $definitions = []): array
    {
        $saved = [];

        foreach ($values as $attributeCode => $value) {
            $attributeCode = (string)$attributeCode;
            $this->setValue(
                $methodCode,
                $attributeCode,
                $value,
                $definitions[$attributeCode] ?? [],
            );
            $saved[$attributeCode] = $this->getValue($methodCode, $attributeCode);
        }

        return $saved;
    }

    /**
     * @return list<AttributeRecord>
     */
    public function getAttributes(): array
    {
        return $this->storeCall(
            fn(): array => $this->store()->getAttributes($this->methodEntity),
        );
    }

    public function getEntityCode(): string
    {
        return PaymentMethodAttributeEntity::entity_code;
    }

    private function requireMethod(string $methodCode): PaymentMethodAttributeEntity
    {
        $methodCode = $this->normalizeMethodCode($methodCode);
        $method = clone $this->methodEntity;
        $method->reset()->clearData()->load(PaymentMethodAttributeEntity::schema_fields_CODE, $methodCode);

        if (!$method->getId()) {
            throw new \InvalidArgumentException('payment_method_not_found:' . $methodCode);
        }

        return $method;
    }

    private function store(): EntityAttributeStoreInterface
    {
        if ($this->attributeStore instanceof EntityAttributeStoreInterface) {
            return $this->attributeStore;
        }

        $store = $this->runtimeProviders->resolve(EntityAttributeStoreInterface::class);
        if (!$store instanceof EntityAttributeStoreInterface) {
            throw new \RuntimeException('payment_method_eav_provider_unavailable');
        }

        return $this->attributeStore = $store;
    }

    private function storeCall(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AttributeStorageException $exception) {
            $this->throwMappedStorageException($exception);
        }
    }

    private function throwMappedStorageException(AttributeStorageException $exception): never
    {
        throw match ($exception->reason) {
            AttributeStorageException::ENTITY_NOT_REGISTERED => new \RuntimeException(
                'payment_method_eav_entity_not_registered',
                previous: $exception,
            ),
            AttributeStorageException::TYPE_NOT_FOUND => new \InvalidArgumentException(
                'payment_attribute_type_not_found:' . $exception->resourceCode,
                previous: $exception,
            ),
            AttributeStorageException::SET_NOT_FOUND => new \RuntimeException(
                'payment_attribute_set_not_found:' . $exception->resourceCode,
                previous: $exception,
            ),
            AttributeStorageException::GROUP_NOT_FOUND => new \RuntimeException(
                'payment_attribute_group_not_found:' . $exception->resourceCode,
                previous: $exception,
            ),
            AttributeStorageException::ATTRIBUTE_ID_MISSING => new \RuntimeException(
                'payment_attribute_id_missing:' . $exception->resourceCode,
                previous: $exception,
            ),
            default => $exception,
        };
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
     * @param string|int|list<string|int> $value
     * @return string|int|list<string|int>
     */
    private function normalizeValue(string|int|array $value): string|int|array
    {
        if (is_array($value)) {
            return array_values(array_map(
                static fn(string|int $item): string|int => is_int($item) ? $item : trim((string)$item),
                $value,
            ));
        }

        return is_int($value) ? $value : trim($value);
    }
}
