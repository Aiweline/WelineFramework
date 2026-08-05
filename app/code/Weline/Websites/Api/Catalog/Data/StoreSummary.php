<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog\Data;

/** Immutable store projection for cross-module listings. */
final readonly class StoreSummary
{
    public function __construct(
        public int $id,
        public int $websiteId,
        public string $code,
        public string $name,
        public string $storeMode,
        public bool $isDefault,
        public bool $enabled,
        public string $lifecycleStatus,
        public ?string $tombstonedAt,
        public ?string $url = null,
    ) {
    }

    /** @return array{store_id:int,website_id:int,code:string,name:string,store_mode:string,is_default:bool,enabled:bool,lifecycle_status:string,tombstoned_at:?string,url:?string} */
    public function toArray(): array
    {
        return [
            'store_id' => $this->id,
            'website_id' => $this->websiteId,
            'code' => $this->code,
            'name' => $this->name,
            'store_mode' => $this->storeMode,
            'is_default' => $this->isDefault,
            'enabled' => $this->enabled,
            'lifecycle_status' => $this->lifecycleStatus,
            'tombstoned_at' => $this->tombstonedAt,
            'url' => $this->url,
        ];
    }
}
