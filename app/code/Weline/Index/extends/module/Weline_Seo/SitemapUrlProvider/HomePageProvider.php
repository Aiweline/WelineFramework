<?php

declare(strict_types=1);

namespace Weline\Index\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;

class HomePageProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly WebsiteDirectoryInterface $websiteDirectory
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
        foreach ($this->websiteDirectory->all() as $website) {
            $websiteId = $website->id;
            if ($websiteId >= 0) {
                $ids[] = $websiteId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        $website = $this->websiteDirectory->get($websiteId);
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
