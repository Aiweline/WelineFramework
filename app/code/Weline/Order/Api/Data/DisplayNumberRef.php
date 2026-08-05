<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/** Kind-qualified display number reference（DEC-017）. */
final class DisplayNumberRef
{
    public function __construct(
        public readonly string $numberKind,
        public readonly string $displayNumber,
        public readonly string $entityUuid,
        public readonly int $websiteId = 0,
        public readonly int $storeId = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number_kind' => $this->numberKind,
            'display_number' => $this->displayNumber,
            'entity_uuid' => $this->entityUuid,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
        ];
    }
}
