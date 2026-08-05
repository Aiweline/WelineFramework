<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog\Data;

/** Immutable sales-channel projection for cross-module listings. */
final readonly class SalesChannelSummary
{
    public function __construct(
        public int $id,
        public int $websiteId,
        public int $storeId,
        public string $code,
        public string $name,
        public bool $isDefault,
        public bool $enabled,
        public string $parentStoreLifecycleStatus,
        public bool $effectiveEnabled,
    ) {
    }

    /** @return array{channel_id:int,website_id:int,store_id:int,code:string,name:string,is_default:bool,enabled:bool,parent_store_lifecycle_status:string,effective_enabled:bool} */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->id,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'code' => $this->code,
            'name' => $this->name,
            'is_default' => $this->isDefault,
            'enabled' => $this->enabled,
            'parent_store_lifecycle_status' => $this->parentStoreLifecycleStatus,
            'effective_enabled' => $this->effectiveEnabled,
        ];
    }
}
