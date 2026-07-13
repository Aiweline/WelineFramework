<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\Type\AttributeTypeDefinition;
use Weline\Eav\Api\Attribute\Type\AttributeTypeRecord;
use Weline\Eav\Api\Attribute\Type\AttributeTypeRegistryInterface;
use Weline\Eav\Model\EavAttribute\Type;

final class AttributeTypeRegistry implements AttributeTypeRegistryInterface
{
    public function __construct(
        private readonly Type $typeModel,
    ) {
    }

    public function register(AttributeTypeDefinition $definition): void
    {
        $code = $this->normalizeCode($definition->code);
        $type = (clone $this->typeModel)->clearData()->clearQuery();
        $type
            ->setFieldType($definition->fieldType)
            ->setCode($code)
            ->setFrontendAttrs($definition->frontendAttributes)
            ->setFieldLength($definition->fieldLength)
            ->setIsSwatch($definition->swatch)
            ->setElement($definition->element)
            ->setModelClass($definition->modelClass)
            ->setModelClassData($definition->modelClassData)
            ->setRequired($definition->required)
            ->setDefaultValue($definition->defaultValue)
            ->setName($definition->name)
            ->save();
    }

    public function find(string $code): ?AttributeTypeRecord
    {
        $type = (clone $this->typeModel)
            ->clearData()
            ->clearQuery()
            ->where(Type::schema_fields_code, $this->normalizeCode($code))
            ->find()
            ->fetch();
        if (!$type->getId()) {
            return null;
        }

        return new AttributeTypeRecord(
            id: (int)$type->getId(),
            fieldType: $type->getFieldType(),
            code: $type->getCode(),
            frontendAttributes: $type->getFrontendAttrs(),
            fieldLength: $type->getFieldLength(),
            swatch: $type->getIsSwatch(),
            element: $type->getElement(),
            modelClass: $type->getModelClass(),
            modelClassData: $type->getModelClassData(),
            required: $type->getRequired(),
            defaultValue: (string)($type->getDefaultValue() ?? ''),
            name: $type->getName(),
        );
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $code)) {
            throw new \InvalidArgumentException('eav_attribute_type_code_invalid:' . $code);
        }

        return $code;
    }
}
