<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSitemapUrlBuilder;
use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;

final class BlogSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly BlogSitemapUrlBuilder $builder,
        private readonly BlogScopeResolver $scope,
        private readonly WebsiteDirectoryInterface $websiteDirectory,
    ) {
        parent::__construct();
    }

    public function getScope(): string
    {
        return 'blog_article';
    }

    public function getModule(): string
    {
        return 'Weline_Blog';
    }

    public function getWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->websiteDirectory->all() as $website) {
            $websiteId = $website->id;
            if ($websiteId >= 0) {
                $ids[$websiteId] = $websiteId;
            }
        }

        return array_values($ids);
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }

        return $this->builder->buildForWebsite($websiteId, $this->scope->baseUrl());
    }

    public function getDescription(): string
    {
        return (string)__('博客文章 sitemap URL 提供器');
    }
}
