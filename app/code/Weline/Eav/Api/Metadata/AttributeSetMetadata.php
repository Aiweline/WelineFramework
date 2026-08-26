<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/**
 * Immutable attribute-set metadata exposed by Eav.
 */
final readonly class AttributeSetMetadata
{
    /**
     * @param list<AttributeGroupMetadata> $groups
     */
    public function __construct(
        public int $id,
        public int $entityId,
        public string $code,
        public string $name,
        public int $sortOrder,
        public array $groups = [],
    ) {
        foreach ($groups as $group) {
            if (!$group instanceof AttributeGroupMetadata) {
                throw new \InvalidArgumentException('eav_attribute_set_metadata_group_invalid');
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
            'sort_order' => $this->sortOrder,
            'groups' => array_map(
                static fn(AttributeGroupMetadata $group): array => $group->toArray(),
                $this->groups,
            ),
        ];
    }
}
