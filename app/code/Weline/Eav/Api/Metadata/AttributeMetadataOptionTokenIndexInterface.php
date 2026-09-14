<?php
declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/** Optional lookup over immutable metadata; implementations share indexes without retaining their DTO keys. */
interface AttributeMetadataOptionTokenIndexInterface
{
    /**
     * Preserve attribute order, first occurrence of each option DTO, last exact alias
     * assignment and the first case-insensitive alias in that resulting order.
     *
     * @param list<AttributeMetadata> $attributes
     */
    public function findOptionByToken(array $attributes, string $token): ?AttributeOptionMetadata;
}
