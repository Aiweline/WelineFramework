<?php

declare(strict_types=1);

namespace Weline\Promotion\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Promotion\Service\PromotionActivityThemeService;
use Weline\Promotion\Service\PromotionScopeResolver;
use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;

final class PromotionSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly WebsiteDirectoryInterface $websiteDirectory,
        private readonly ?PromotionActivityThemeService $themeService = null,
        private readonly ?PromotionScopeResolver $scopeResolver = null,
    ) {
        parent::__construct();
    }

    public function getScope(): string
    {
        return 'promotion';
    }

    public function getModule(): string
    {
        return 'Weline_Promotion';
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
        $website = $this->websiteDirectory->get($websiteId);
        if ($website === null) {
            return [];
        }
        $baseUrl = rtrim(trim($website->url), '/');

        $themeService = $this->themeService;
        try {
            $themeService ??= ObjectManager::getInstance(PromotionActivityThemeService::class);
        } catch (\Throwable) {
            return [[
                'url_key' => 'promotion-hub',
                'loc' => $baseUrl . '/promotion',
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'daily',
                'priority' => '0.8',
                'entity_type' => 'promotion',
                'entity_id' => 0,
                'metadata' => [
                    'page_type' => 'products',
                    'source' => 'Weline_Promotion',
                ],
            ]];
        }

        $urls = [[
            'url_key' => 'promotion-hub',
            'loc' => $baseUrl . '/promotion',
            'lastmod' => date('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => '0.8',
            'entity_type' => 'promotion',
            'entity_id' => 0,
            'metadata' => [
                'page_type' => 'products',
                'source' => 'Weline_Promotion',
            ],
        ]];

        $scope = [
            'website_id' => $websiteId,
            'store_code' => '',
            'channel_code' => '',
        ];
        try {
            $themes = $themeService->listActiveThemesForStorefront($scope);
        } catch (\Throwable) {
            $themes = [];
        }

        foreach ($themes as $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $slug = strtolower(trim((string)($theme['page_slug'] ?? '')));
            if ($slug === '' || $slug === 'index') {
                continue;
            }
            $themeId = (int)($theme['id'] ?? 0);
            $urls[] = [
                'url_key' => 'promotion-theme-' . ($themeId > 0 ? $themeId : $slug),
                'loc' => $baseUrl . '/promotion/' . rawurlencode($slug),
                'lastmod' => date('Y-m-d', strtotime((string)($theme['updated_at'] ?? 'now')) ?: time()),
                'changefreq' => 'daily',
                'priority' => '0.7',
                'entity_type' => 'promotion_theme',
                'entity_id' => $themeId,
                'metadata' => [
                    'page_type' => 'products',
                    'page_slug' => $slug,
                    'source' => 'Weline_Promotion',
                ],
            ];
        }

        return $urls;
    }

    public function getDescription(): string
    {
        return (string)\__('促销活动 sitemap URL 提供器');
    }

    public function supportsSiteLanguagePathExpansion(): bool
    {
        return true;
    }
}
