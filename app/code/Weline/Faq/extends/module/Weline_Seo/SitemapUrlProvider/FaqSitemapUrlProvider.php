<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Cms\Model\Page;
use Weline\Cms\Service\PageService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Service\FaqPageProviderRegistry;
use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;

final class FaqSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly WebsiteDirectoryInterface $websiteDirectory,
        private readonly ?PageService $pageService = null,
        private readonly ?Page $pageModel = null,
        private readonly ?FaqPageProviderRegistry $pageProviders = null,
    ) {
        parent::__construct();
    }

    public function getScope(): string
    {
        return 'faq';
    }

    public function getModule(): string
    {
        return 'Weline_Faq';
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
        $urls = [[
            'url_key' => 'faq-hub',
            'loc' => $baseUrl . '/' . FaqNamespace::PREFIX,
            'lastmod' => date('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => '0.6',
            'entity_type' => 'faq',
            'entity_id' => 0,
            'metadata' => [
                'page_type' => 'faq',
                'source' => 'Weline_Faq',
            ],
        ]];

        if (!$this->cmsAvailable()) {
            return $urls;
        }

        try {
            $pageModel = $this->pageModel ?? ObjectManager::getInstance(Page::class);
            $pageService = $this->pageService ?? ObjectManager::getInstance(PageService::class);
            $rows = $pageModel->clearData()->reset()
                ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Page::schema_fields_PATH_GROUP, FaqNamespace::PREFIX)
                ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->where(Page::schema_fields_DELETED_AT, null, 'IS NULL')
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $page = clone $pageModel;
                $page->clearData()->setData($row);
                $slug = trim($page->getSlug());
                if ($slug === '') {
                    continue;
                }
                $urls[] = [
                    'url_key' => 'faq-page-' . $page->getPageId(),
                    'loc' => $baseUrl . FaqNamespace::articlePublicPath($slug),
                    'lastmod' => (string)($page->getData(Page::schema_fields_UPDATED_AT) ?: date('Y-m-d')),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                    'entity_type' => 'faq_article',
                    'entity_id' => $page->getPageId(),
                    'metadata' => [
                        'page_type' => 'faq_article',
                        'title' => $page->getTitle(),
                        'source' => 'Weline_Faq',
                    ],
                ];
            }
        } catch (\Throwable) {
            // Hub URL alone is enough when CMS read fails.
        }

        foreach ($this->pageProviders()->enabledPages() as $spi) {
            $slug = trim($spi->slug());
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'url_key' => 'faq-spi-' . $spi->pageCode(),
                'loc' => $baseUrl . FaqNamespace::articlePublicPath($slug),
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => '0.5',
                'entity_type' => 'faq_article',
                'entity_id' => 0,
                'metadata' => [
                    'page_type' => 'faq_article',
                    'title' => $spi->title(),
                    'source' => 'faq_page_provider',
                    'page_code' => $spi->pageCode(),
                ],
            ];
        }

        return $urls;
    }

    public function getDescription(): string
    {
        return (string)__('帮助中心 sitemap URL 提供器');
    }

    private function pageProviders(): FaqPageProviderRegistry
    {
        return $this->pageProviders ?? ObjectManager::getInstance(FaqPageProviderRegistry::class);
    }

    private function cmsAvailable(): bool
    {
        try {
            return (bool)Env::getInstance()->getModuleStatus('Weline_Cms');
        } catch (\Throwable) {
            return class_exists(PageService::class);
        }
    }
}
