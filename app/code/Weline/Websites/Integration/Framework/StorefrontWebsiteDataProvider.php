<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Framework;

use Weline\Framework\Runtime\StorefrontWebsiteDataProviderInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * Publishes WebsiteData projections through the Framework-owned contract.
 */
final class StorefrontWebsiteDataProvider implements StorefrontWebsiteDataProviderInterface
{
    public function languageCodesFor(int $websiteId): array
    {
        return WebsiteData::languageCodesForWebsite($websiteId);
    }

    public function defaultLanguageFor(int $websiteId): ?string
    {
        return WebsiteData::defaultLanguageForWebsite($websiteId);
    }

    public function localRowsFor(int $websiteId): array
    {
        return WebsiteData::localRowsForWebsite($websiteId);
    }
}
