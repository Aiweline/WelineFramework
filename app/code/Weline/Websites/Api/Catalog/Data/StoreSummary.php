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

    /** @param array<string, mixed> $row */
    public static function tryFromArray(array $row): ?self
    {
        if (!\array_key_exists('store_id', $row) || !\array_key_exists('website_id', $row)) {
            return null;
        }
        $id = $row['store_id'];
        $websiteId = $row['website_id'];
        if (!\is_int($id) && !(\is_string($id) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $id) === 1)) {
            return null;
        }
        if (!\is_int($websiteId) && !(\is_string($websiteId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $websiteId) === 1)) {
            return null;
        }
        $code = \trim((string)($row['code'] ?? ''));
        $name = \trim((string)($row['name'] ?? ''));
        $storeMode = \trim((string)($row['store_mode'] ?? ''));
        $lifecycle = \trim((string)($row['lifecycle_status'] ?? ''));
        if ($code === '' || $name === '' || $storeMode === '' || $lifecycle === '') {
            return null;
        }
        $url = $row['url'] ?? null;
        if ($url !== null) {
            $url = \trim((string)$url);
            if ($url === '') {
                $url = null;
            }
        }
        $tombstonedAt = $row['tombstoned_at'] ?? null;
        if ($tombstonedAt !== null) {
            $tombstonedAt = \trim((string)$tombstonedAt);
            if ($tombstonedAt === '') {
                $tombstonedAt = null;
            }
        }

        return new self(
            (int)$id,
            (int)$websiteId,
            $code,
            $name,
            $storeMode,
            (bool)($row['is_default'] ?? false),
            (bool)($row['enabled'] ?? false),
            $lifecycle,
            $tombstonedAt,
            $url,
        );
    }
}
