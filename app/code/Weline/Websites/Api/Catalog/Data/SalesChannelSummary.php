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

    /** @param array<string, mixed> $row */
    public static function tryFromArray(array $row): ?self
    {
        if (!\array_key_exists('channel_id', $row)
            || !\array_key_exists('website_id', $row)
            || !\array_key_exists('store_id', $row)
        ) {
            return null;
        }
        $id = $row['channel_id'];
        $websiteId = $row['website_id'];
        $storeId = $row['store_id'];
        if (!\is_int($id) && !(\is_string($id) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $id) === 1)) {
            return null;
        }
        if (!\is_int($websiteId) && !(\is_string($websiteId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $websiteId) === 1)) {
            return null;
        }
        if (!\is_int($storeId) && !(\is_string($storeId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $storeId) === 1)) {
            return null;
        }
        $code = \trim((string)($row['code'] ?? ''));
        $name = \trim((string)($row['name'] ?? ''));
        $parentLifecycle = \trim((string)($row['parent_store_lifecycle_status'] ?? ''));
        if ($code === '' || $name === '' || $parentLifecycle === '') {
            return null;
        }

        return new self(
            (int)$id,
            (int)$websiteId,
            (int)$storeId,
            $code,
            $name,
            (bool)($row['is_default'] ?? false),
            (bool)($row['enabled'] ?? false),
            $parentLifecycle,
            (bool)($row['effective_enabled'] ?? false),
        );
    }
}
