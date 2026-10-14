<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

/**
 * Resolve storefront language membership + order for Website → Store → Channel.
 *
 * Inheritance: channel override → store override → website_language → platform ranks.
 */
interface ResolvedLanguageCatalogInterface
{
    /**
     * @return list<string> Ordered locale codes for the scope
     */
    public function getResolvedLanguageCodes(
        int $websiteId,
        ?int $storeId = null,
        ?int $channelId = null,
    ): array;

    /**
     * Whether the store (channelId null) or channel has an explicit override.
     */
    public function hasOverride(int $websiteId, int $storeId, ?int $channelId): bool;

    /**
     * Replace membership+order for store (channelId=null) or channel. Sets has_override=1.
     *
     * @param list<string> $languageCodes ordered
     */
    public function saveOverride(
        int $websiteId,
        int $storeId,
        ?int $channelId,
        array $languageCodes,
    ): void;

    /**
     * Clear override so the scope inherits parent again.
     */
    public function clearOverride(int $websiteId, int $storeId, ?int $channelId): void;
}
