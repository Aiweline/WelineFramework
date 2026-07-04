<?php

declare(strict_types=1);

namespace Weline\Index\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Seo\Provider\AbstractSitemapUrlProvider;
use Weline\Seo\Service\SeoWebsiteDirectory;

class HomePageProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly SeoWebsiteDirectory $websiteDirectory
    ) {
        parent::__construct();
    }

    public function getScope(): string
    {
        return 'frontend';
    }

    public function getModule(): string
    {
        return 'Weline_Index';
    }

    public function getWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->websiteDirectory->listWebsites() as $website) {
            $websiteId = (int)($website['website_id'] ?? $website['id'] ?? 0);
            if ($websiteId > 0) {
                $ids[] = $websiteId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        $website = $this->websiteDirectory->getWebsiteById($websiteId);
        if ($website === null) {
            return [];
        }

        return [
            [
                'url_key' => 'home-1',
                'loc' => '/',
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'daily',
                'priority' => '1.0',
                'metadata' => [
                    'page_type' => 'home',
                    'source' => 'Weline_Index',
                ],
            ],
        ];
    }

    public function getDescription(): string
    {
        return __('Weline 首页 sitemap URL 提供器');
    }
}
