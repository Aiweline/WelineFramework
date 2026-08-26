<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/**
 * Immutable attribute-group metadata exposed by Eav.
 */
final readonly class AttributeGroupMetadata
{
    /**
     * @param list<AttributeMetadata> $attributes
     */
    public function __construct(
        public int $id,
        public int $entityId,
        public int $setId,
        public string $code,
        public string $name,
        public int $sortOrder,
        public array $attributes = [],
    ) {
        foreach ($attributes as $attribute) {
            if (!$attribute instanceof AttributeMetadata) {
                throw new \InvalidArgumentException('eav_attribute_group_metadata_attribute_invalid');
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
            'set_id' => $this->setId,
            'code' => $this->code,
            'name' => $this->name,
            'sort_order' => $this->sortOrder,
            'attributes' => array_map(
                static fn(AttributeMetadata $attribute): array => $attribute->toArray(),
                $this->attributes,
            ),
        ];
    }
}
