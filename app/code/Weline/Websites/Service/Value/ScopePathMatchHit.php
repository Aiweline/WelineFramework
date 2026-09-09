<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Value;

use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;

/**
 * L2 path-resolution hit: full Scope identity derived from longest path match.
 */
final readonly class ScopePathMatchHit
{
    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $channel
     */
    public function __construct(
        public int $websiteId,
        public string $websiteCode,
        public int $storeId,
        public string $storeCode,
        public string $storeMode,
        public int $channelId,
        public string $channelCode,
        public string $routePath,
        public array $store,
        public array $channel,
    ) {
    }

    public static function fromResolved(
        int $websiteId,
        string $websiteCode,
        StoreSummary $store,
        SalesChannelSummary $channel,
        string $routePath,
    ): self {
        return new self(
            $websiteId,
            $websiteCode,
            $store->id,
            $store->code,
            $store->storeMode,
            $channel->id,
            $channel->code,
            $routePath,
            $store->toArray(),
            $channel->toArray(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        if (!\array_key_exists('website_id', $payload)
            || !\array_key_exists('website_code', $payload)
            || !\array_key_exists('store_id', $payload)
            || !\array_key_exists('store_code', $payload)
            || !\array_key_exists('store_mode', $payload)
            || !\array_key_exists('channel_id', $payload)
            || !\array_key_exists('channel_code', $payload)
            || !\array_key_exists('route_path', $payload)
            || !\is_array($payload['store'] ?? null)
            || !\is_array($payload['channel'] ?? null)
        ) {
            return null;
        }

        $websiteId = $payload['website_id'];
        $storeId = $payload['store_id'];
        $channelId = $payload['channel_id'];
        if (!\is_int($websiteId) && !(\is_string($websiteId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $websiteId) === 1)) {
            return null;
        }
        if (!\is_int($storeId) && !(\is_string($storeId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $storeId) === 1)) {
            return null;
        }
        if (!\is_int($channelId) && !(\is_string($channelId) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $channelId) === 1)) {
            return null;
        }

        $websiteCode = \trim((string)$payload['website_code']);
        $storeCode = \trim((string)$payload['store_code']);
        $storeMode = \trim((string)$payload['store_mode']);
        $channelCode = \trim((string)$payload['channel_code']);
        $routePath = \trim((string)$payload['route_path']);
        if ($websiteCode === '' || $storeCode === '' || $storeMode === '' || $channelCode === '' || $routePath === '') {
            return null;
        }

        return new self(
            (int)$websiteId,
            $websiteCode,
            (int)$storeId,
            $storeCode,
            $storeMode,
            (int)$channelId,
            $channelCode,
            $routePath,
            $payload['store'],
            $payload['channel'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'website_code' => $this->websiteCode,
            'store_id' => $this->storeId,
            'store_code' => $this->storeCode,
            'store_mode' => $this->storeMode,
            'channel_id' => $this->channelId,
            'channel_code' => $this->channelCode,
            'route_path' => $this->routePath,
            'store' => $this->store,
            'channel' => $this->channel,
        ];
    }

    public function storeSummary(): ?StoreSummary
    {
        return StoreSummary::tryFromArray($this->store);
    }

    public function channelSummary(): ?SalesChannelSummary
    {
        return SalesChannelSummary::tryFromArray($this->channel);
    }

    public function matchesWebsite(int $websiteId, string $websiteCode): bool
    {
        return $this->websiteId === $websiteId
            && $websiteCode !== ''
            && \hash_equals($this->websiteCode, $websiteCode);
    }
}
