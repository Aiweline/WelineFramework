<?php

declare(strict_types=1);

namespace Weline\Websites\Data;

use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;

/**
 * L3 request-scoped Scope snapshot for the current Store + Channel.
 *
 * Installed once by ScopeResolver after path resolution. Ordinary reads use
 * this snapshot; force_reload paths bypass it.
 */
final class ScopeData
{
    private const STATE_KEY = 'websites.scope_data.state.v1';

    /**
     * @return array{
     *     store: StoreSummary|null,
     *     channel: SalesChannelSummary|null,
     *     route_path: string|null
     * }
     */
    private static function emptyState(): array
    {
        return [
            'store' => null,
            'channel' => null,
            'route_path' => null,
        ];
    }

    public static function install(
        StoreSummary $store,
        SalesChannelSummary $channel,
        string $routePath,
    ): void {
        RequestContext::set(self::STATE_KEY, [
            'store' => $store,
            'channel' => $channel,
            'route_path' => $routePath,
        ]);
    }

    public static function resetRequestState(): void
    {
        RequestContext::remove(self::STATE_KEY);
    }

    public static function getStore(): ?StoreSummary
    {
        return self::readState()['store'];
    }

    public static function getChannel(): ?SalesChannelSummary
    {
        return self::readState()['channel'];
    }

    public static function getRoutePath(): ?string
    {
        return self::readState()['route_path'];
    }

    public static function matchesStoreId(int $storeId): bool
    {
        if ($storeId < 0) {
            return false;
        }
        $store = self::getStore();
        return $store instanceof StoreSummary && $store->id === $storeId;
    }

    public static function matchesChannelId(int $channelId): bool
    {
        if ($channelId < 0) {
            return false;
        }
        $channel = self::getChannel();
        return $channel instanceof SalesChannelSummary && $channel->id === $channelId;
    }

    public static function matchesStoreCode(int $websiteId, string $storeCode): bool
    {
        $storeCode = \trim($storeCode);
        if ($storeCode === '' || $websiteId < 0) {
            return false;
        }
        $store = self::getStore();
        return $store instanceof StoreSummary
            && $store->websiteId === $websiteId
            && \hash_equals($store->code, $storeCode);
    }

    public static function matchesChannelCode(int $storeId, string $channelCode): bool
    {
        $channelCode = \trim($channelCode);
        if ($channelCode === '' || $storeId < 0) {
            return false;
        }
        $channel = self::getChannel();
        return $channel instanceof SalesChannelSummary
            && $channel->storeId === $storeId
            && \hash_equals($channel->code, $channelCode);
    }

    /**
     * @return array{
     *     store: StoreSummary|null,
     *     channel: SalesChannelSummary|null,
     *     route_path: string|null
     * }
     */
    private static function readState(): array
    {
        $state = RequestContext::get(self::STATE_KEY);
        if (!\is_array($state)) {
            return self::emptyState();
        }

        return [
            'store' => ($state['store'] ?? null) instanceof StoreSummary ? $state['store'] : null,
            'channel' => ($state['channel'] ?? null) instanceof SalesChannelSummary ? $state['channel'] : null,
            'route_path' => \is_string($state['route_path'] ?? null) ? $state['route_path'] : null,
        ];
    }
}
