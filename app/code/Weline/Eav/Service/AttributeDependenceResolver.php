<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\AttributeDependenceResolverInterface;
use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;

final class AttributeDependenceResolver implements AttributeDependenceResolverInterface
{
    private const MAX_OPTIONS = 500;
    private const MAX_INPUT_ITEMS = 500;
    private const MAX_INPUT_STRING_BYTES = 65536;

    public function __construct(private readonly EavAttribute $attribute)
    {
    }

    public function resolve(array $params): array
    {
        $entityId = $this->requireEntityId($params);
        $dependenceAttribute = $this->requireCode($params, 'dependence_attribute');
        $dependenceValue = $this->requireValue($params, 'dependence_value');
        $attributeCode = $this->requireCode($params, 'attribute');
        $attributeValue = array_key_exists('attribute_value', $params)
            ? $this->normalizeInputValue($params['attribute_value'], 'attribute_value', true)
            : '';

        $attribute = $this->loadAttribute(clone $this->attribute, $entityId, $attributeCode);

        if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
            throw new \DomainException((string)__('当前实体下的属性不存在：%{1}', $attributeCode));
        }

        $dependenceAttributeModel = $this->loadAttribute(
            clone $this->attribute,
            $entityId,
            $dependenceAttribute,
        );
        if (!$dependenceAttributeModel instanceof EavAttribute
            || $dependenceAttributeModel->getAttributeId() <= 0) {
            throw new \DomainException((string)__('当前实体下的依赖属性不存在：%{1}', $dependenceAttribute));
        }

        try {
            $attributeType = $attribute->getType();
        } catch (\Throwable $throwable) {
            throw new \DomainException((string)__('当前属性类型不存在'), 0, $throwable);
        }

        if (!$attributeType->getId()) {
            throw new \DomainException((string)__('当前属性类型不存在'));
        }

        $modelClass = trim($attributeType->getModelClass());
        if ($modelClass === '') {
            throw new \DomainException((string)__('当前属性类型模型不存在'));
        }
        if (!class_exists($modelClass)) {
            throw new \DomainException((string)__('当前属性类型模型类不存在：%{1}', $modelClass));
        }
        if (!is_subclass_of($modelClass, EavModelInterface::class)) {
            throw new \DomainException((string)__('当前属性类型模型未实现EavModelInterface：%{1}', $modelClass));
        }
        if (!method_exists($modelClass, 'dependenceProcess')
            || !is_callable([$modelClass, 'dependenceProcess'])) {
            throw new \DomainException((string)__('当前属性类型模型不支持依赖解析：%{1}', $modelClass));
        }

        try {
            $options = $modelClass::dependenceProcess([
                'dependenceAttribute' => $dependenceAttribute,
                'dependenceAttributeValue' => $dependenceValue,
                'attribute' => $attributeCode,
                'attributeValue' => $attributeValue,
            ]);
        } catch (\Throwable $throwable) {
            try {
                if (\function_exists('w_log_error')) {
                    \w_log_error('EAV attribute dependence resolver failed: ' . $throwable->getMessage());
                }
            } catch (\Throwable) {
                // Preserve the fixed public error even if diagnostics fail.
            }
            throw new \DomainException(
                (string)__('属性依赖选项解析失败。'),
                0,
                $throwable,
            );
        }

        return $this->normalizeOptions($options);
    }

    private function requireEntityId(array $params): int
    {
        if (!array_key_exists('eav_entity_id', $params)) {
            $this->throwMissingParameter('eav_entity_id');
        }

        $entityId = $params['eav_entity_id'];
        if (is_int($entityId)) {
            $normalized = $entityId;
        } elseif (is_string($entityId) && ctype_digit($entityId)) {
            $normalized = (int)$entityId;
        } else {
            throw new \InvalidArgumentException((string)__('EAV实体ID必须是正整数'));
        }

        if ($normalized <= 0) {
            throw new \InvalidArgumentException((string)__('EAV实体ID必须是正整数'));
        }

        return $normalized;
    }

    private function requireCode(array $params, string $key): string
    {
        if (!array_key_exists($key, $params)) {
            $this->throwMissingParameter($key);
        }

        if (!is_string($params[$key])) {
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        $rawValue = $params[$key];
        if (strlen($rawValue) > 255
            || preg_match('//u', $rawValue) !== 1
            || preg_match('/\p{Cc}/u', $rawValue) === 1) {
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        $value = trim($rawValue);
        if ($value === '') {
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        return $value;
    }

    private function requireValue(array $params, string $key): mixed
    {
        if (!array_key_exists($key, $params)) {
            $this->throwMissingParameter($key);
        }

        $value = $this->normalizeInputValue($params[$key], $key, false);
        if ($value === '' || $value === []) {
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        return $value;
    }

    private function normalizeInputValue(mixed $value, string $key, bool $nullable): mixed
    {
        if ($value === null) {
            if ($nullable) {
                return '';
            }
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        if (is_scalar($value)) {
            $this->assertInputStringLength($value, $key);
            return $value;
        }
        if ($value instanceof \Stringable) {
            $value = (string)$value;
            $this->assertInputStringLength($value, $key);
            return $value;
        }
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) > self::MAX_INPUT_ITEMS) {
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        $normalized = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $this->assertInputStringLength($item, $key);
                $normalized[] = $item;
                continue;
            }
            if ($item instanceof \Stringable) {
                $item = (string)$item;
                $this->assertInputStringLength($item, $key);
                $normalized[] = $item;
                continue;
            }
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }

        return $normalized;
    }

    private function assertInputStringLength(mixed $value, string $key): void
    {
        if (is_string($value) && strlen($value) > self::MAX_INPUT_STRING_BYTES) {
            throw new \InvalidArgumentException((string)__('EAV属性依赖参数格式无效：%{1}', $key));
        }
    }

    private function normalizeOptions(mixed $options): array
    {
        if ($options === null || $options === '' || $options === []) {
            return [];
        }
        if (!is_array($options)) {
            throw new \DomainException((string)__('属性依赖解析结果必须是选项映射'));
        }
        if (count($options) > self::MAX_OPTIONS) {
            throw new \DomainException((string)__('属性依赖选项不能超过500项'));
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            if (is_scalar($value)) {
                $normalized[$key] = $value;
                continue;
            }
            if ($value instanceof \Stringable) {
                $normalized[$key] = (string)$value;
                continue;
            }
            throw new \DomainException((string)__('属性依赖选项值必须是标量或可转字符串对象：%{1}', (string)$key));
        }

        return $normalized;
    }

    private function loadAttribute(EavAttribute $attribute, int $entityId, string $attributeCode): mixed
    {
        return $attribute
            ->clearData()
            ->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, $attributeCode)
            ->find()
            ->fetch();
    }

    private function throwMissingParameter(string $key): never
    {
        throw new \InvalidArgumentException((string)__('EAV属性依赖解析缺少必要参数：%{1}', $key));
    }
}
