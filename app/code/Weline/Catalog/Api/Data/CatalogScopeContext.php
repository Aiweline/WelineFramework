<?php

declare(strict_types=1);

namespace Weline\Catalog\Api\Data;

final class CatalogScopeContext
{
    public function __construct(
        public readonly string $space,
        public readonly string $scopeLevel,
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly int $channelId,
    ) {
    }

    public function isWebsiteStructureScope(): bool
    {
        return $this->scopeLevel === 'website';
    }
}
