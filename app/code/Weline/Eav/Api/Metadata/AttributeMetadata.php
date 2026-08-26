<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/**
 * Immutable attribute metadata exposed by Eav.
 */
final readonly class AttributeMetadata
{
    /**
     * @param list<AttributeOptionMetadata> $options
     */
    public function __construct(
        public int $id,
        public int $entityId,
        public string $code,
        public string $name,
        public string $typeCode,
        public string $fieldType,
        public string $element,
        public int $setId,
        public int $groupId,
        public bool $required,
        public bool $multiple,
        public bool $enabled,
        public bool $hasOption,
        public int $sortOrder,
        public array $options = [],
        public string $compareMode = CompareMode::NONE,
    ) {
        foreach ($options as $option) {
            if (!$option instanceof AttributeOptionMetadata) {
                throw new \InvalidArgumentException('eav_attribute_metadata_option_invalid');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entity_id' => $this->entityId,
            'code' => $this->code,
            'name' => $this->name,
            'type_code' => $this->typeCode,
            'field_type' => $this->fieldType,
            'element' => $this->element,
            'set_id' => $this->setId,
            'group_id' => $this->groupId,
            'required' => $this->required,
            'multiple' => $this->multiple,
            'enabled' => $this->enabled,
            'has_option' => $this->hasOption,
            'sort_order' => $this->sortOrder,
            'compare_mode' => $this->compareMode,
            'options' => array_map(
                static fn(AttributeOptionMetadata $option): array => $option->toArray(),
                $this->options,
            ),
        ];
    }
}
