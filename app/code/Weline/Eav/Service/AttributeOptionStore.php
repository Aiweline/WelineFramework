<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\Option\AttributeOptionDefinition;
use Weline\Eav\Api\Attribute\Option\AttributeOptionRecord;
use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Eav\Model\EavAttribute\Option;

final class AttributeOptionStore implements AttributeOptionStoreInterface
{
    public function __construct(
        private readonly Option $optionModel,
    ) {
    }

    public function register(AttributeOptionDefinition $definition): void
    {
        $option = (clone $this->optionModel)->clearData()->clearQuery();
        $option
            ->setAttributeId($definition->attributeId)
            ->setCode($definition->code)
            ->setValue($definition->value)
            ->save();
    }

    public function find(int $attributeId, string $code): ?AttributeOptionRecord
    {
        $option = (clone $this->optionModel)
            ->clearData()
            ->clearQuery()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->where(Option::schema_fields_code, $code)
            ->find()
            ->fetch();
        if (!$option->getOptionId()) {
            return null;
        }

        return new AttributeOptionRecord(
            id: $option->getOptionId(),
            attributeId: $option->getAttributeId(),
            code: $option->getCode(),
            value: $option->getValue(),
        );
    }
}
