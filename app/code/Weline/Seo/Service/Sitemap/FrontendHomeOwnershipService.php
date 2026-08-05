<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Sitemap;

use Weline\Seo\Api\Sitemap\FrontendHomeOwnerInterface;
use Weline\Seo\Service\SitemapRegistryService;

/**
 * Resolves which websites already have a dedicated frontend-home owner.
 *
 * Used by Weline_Index HomePageProvider so fallback `/` URLs are only written
 * for websites that no other SitemapUrlProvider claims.
 */
final class FrontendHomeOwnershipService
{
    public function __construct(
        private readonly SitemapRegistryService $registryService,
    ) {
    }

    /**
     * @param string $exceptModule Skip this module when scanning owners
     *                             (usually the fallback HomePageProvider module).
     */
    public function isOwnedByOtherProvider(int $websiteId, string $exceptModule = ''): bool
    {
        if ($websiteId < 0) {
            return false;
        }

        return in_array($websiteId, $this->claimedWebsiteIds($exceptModule), true);
    }

    /**
     * @return list<int>
     */
    public function claimedWebsiteIds(string $exceptModule = ''): array
    {
        $exceptModule = trim($exceptModule);
        $ids = [];
        foreach ($this->registryService->getUrlProviders() as $provider) {
            if (!$provider instanceof FrontendHomeOwnerInterface) {
                continue;
            }
            if ($exceptModule !== '' && trim($provider->getModule()) === $exceptModule) {
                continue;
            }
            foreach ($provider->getFrontendHomeWebsiteIds() as $websiteId) {
                $websiteId = (int)$websiteId;
                if ($websiteId >= 0) {
                    $ids[$websiteId] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
