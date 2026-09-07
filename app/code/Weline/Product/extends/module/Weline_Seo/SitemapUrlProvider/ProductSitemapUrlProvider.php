<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Product\Service\ProductSitemapUrlService;
use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

final class ProductSitemapUrlProvider implements SitemapUrlProviderInterface
{
    public function __construct(
        private readonly ProductSitemapUrlService $urls,
        private readonly WebsiteCatalogInterface $websites,
    ) {
    }

    public function getScope(): string { return 'product'; }
    public function getModule(): string { return 'Weline_Product'; }
    public function getWebsiteIds(): array
    {
        return array_values(array_unique(array_map(static fn($website): int => $website->id, $this->websites->all())));
    }
    public function getUrlsForWebsite(int $websiteId): array { return $this->urls->getUrlsForWebsite($websiteId); }
    public function getDescription(): string { return (string)__('商品'); }
    public function isEnabled(): bool { return true; }
}
