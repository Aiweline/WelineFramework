<?php

declare(strict_types=1);

namespace Weline\Cms\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Cms\Model\Page;
use Weline\Cms\Service\PageService;
use Weline\Seo\Provider\AbstractSitemapUrlProvider;
use Weline\Seo\Service\SeoWebsiteDirectory;

class CmsPageProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly Page $pageModel,
        private readonly PageService $pageService,
        private readonly SeoWebsiteDirectory $websiteDirectory
    ) {
        parent::__construct();
    }

    public function getScope(): string
    {
        return Page::TARGET_TYPE;
    }

    public function getModule(): string
    {
        return 'Weline_Cms';
    }

    public function getWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->websiteDirectory->listWebsites() as $website) {
            $websiteId = (int)($website['website_id'] ?? $website['id'] ?? 0);
            if ($websiteId > 0) {
                $ids[$websiteId] = $websiteId;
            }
        }

        return array_values($ids);
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        if ($websiteId <= 0) {
            return [];
        }

        $rows = $this->pageModel->clearData()->reset()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
            ->where(Page::schema_fields_DELETED_AT, null, 'IS NULL')
            ->order(Page::schema_fields_PATH_GROUP, 'ASC')
            ->order(Page::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $urls = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = clone $this->pageModel;
            $page->clearData()->setData($row);
            $pageId = $page->getPageId();
            if ($pageId <= 0 || !$page->isPublished() || $page->isDeleted()) {
                continue;
            }

            $urls[] = [
                'url_key' => 'cms-page-' . $pageId,
                'loc' => $this->pageService->buildPublicUrl($page),
                'lastmod' => (string)($page->getData(Page::schema_fields_UPDATED_AT) ?: date('Y-m-d')),
                'changefreq' => 'weekly',
                'priority' => '0.6',
                'entity_type' => Page::TARGET_TYPE,
                'entity_id' => $pageId,
                'metadata' => [
                    'page_type' => Page::TARGET_TYPE,
                    'title' => $page->getTitle(),
                    'identifier' => $page->getIdentifier(),
                    'path_group' => $page->getPathGroup(),
                    'slug' => $page->getSlug(),
                    'scope' => $page->getScope(),
                    'source' => 'Weline_Cms',
                ],
            ];
        }

        return $urls;
    }

    public function getDescription(): string
    {
        return __('CMS 已发布页面 sitemap URL 提供器');
    }
}
