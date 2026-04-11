<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class BlogPageResolver
{
    public function __construct(
        private readonly Page $pageModel
    ) {
    }

    public function getListPage(int $preferredWebsiteId = 0, bool $allowGlobalFallback = true): ?Page
    {
        return $this->findFirstPublishedPage($preferredWebsiteId, Page::TYPE_BLOG_LIST)
            ?? ($allowGlobalFallback ? $this->findFirstPublishedPage(0, Page::TYPE_BLOG_LIST) : null);
    }

    public function resolveThemeWebsiteId(int $requestWebsiteId, int $contentWebsiteId): int
    {
        if ($requestWebsiteId > 0) {
            return $requestWebsiteId;
        }

        return max(0, $contentWebsiteId);
    }

    public function resolveContentWebsiteId(?Page $page, int $requestWebsiteId, int $fallbackWebsiteId = 0): int
    {
        if ($page && $page->getId()) {
            $pageWebsiteId = (int)($page->getData(Page::schema_fields_WEBSITE_ID) ?? 0);
            if ($pageWebsiteId > 0) {
                return $pageWebsiteId;
            }
        }

        if ($requestWebsiteId > 0) {
            return $requestWebsiteId;
        }

        return max(0, $fallbackWebsiteId);
    }

    public function getCategoryPage(int $preferredWebsiteId = 0, bool $allowGlobalFallback = true): ?Page
    {
        return $this->findPublishedPageSharingListHeaderFooterSource($preferredWebsiteId, Page::TYPE_BLOG_CATEGORY)
            ?? $this->getListPage($preferredWebsiteId, false)
            ?? ($allowGlobalFallback ? $this->findPublishedPageSharingListHeaderFooterSource(0, Page::TYPE_BLOG_CATEGORY) : null)
            ?? ($allowGlobalFallback ? $this->getListPage(0, true) : null);
    }

    public function getDetailPage(int $preferredWebsiteId = 0, bool $allowGlobalFallback = true): ?Page
    {
        return $this->findPublishedPageSharingListHeaderFooterSource($preferredWebsiteId, Page::TYPE_BLOG)
            ?? $this->getListPage($preferredWebsiteId, false)
            ?? ($allowGlobalFallback ? $this->findPublishedPageSharingListHeaderFooterSource(0, Page::TYPE_BLOG) : null)
            ?? ($allowGlobalFallback ? $this->getListPage(0, true) : null);
    }

    private function findPublishedPageSharingListHeaderFooterSource(int $websiteId, string $type): ?Page
    {
        $pages = $this->findPublishedPages($websiteId, $type);
        if ($pages === []) {
            return null;
        }

        $listPage = $this->findFirstPublishedPage($websiteId, Page::TYPE_BLOG_LIST);
        if ($listPage && $listPage->getId()) {
            $expectedSourcePageId = $this->getHeaderFooterSourcePageId($listPage);
            if ($expectedSourcePageId > 0) {
                foreach ($pages as $page) {
                    if ($this->getHeaderFooterSourcePageId($page) === $expectedSourcePageId) {
                        return $page;
                    }
                }
            }
        }

        return $pages[0];
    }

    private function findFirstPublishedPage(int $websiteId, string $type): ?Page
    {
        $pages = $this->findPublishedPages($websiteId, $type);

        return $pages[0] ?? null;
    }

    /**
     * Preserve the long-standing "first created page wins" behavior that the
     * current home/list resolution already relies on, while making it explicit.
     *
     * @return list<Page>
     */
    private function findPublishedPages(int $websiteId, string $type): array
    {
        if ($websiteId < 0) {
            return [];
        }

        $page = clone $this->pageModel;
        $items = $page->clear()
            ->where(Page::schema_fields_TYPE, $type)
            ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->order(Page::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        return array_values(array_filter(
            $items,
            static fn ($item): bool => $item instanceof Page && (bool)$item->getId()
        ));
    }

    private function getHeaderFooterSourcePageId(Page $page): int
    {
        $sourcePage = $page->getHeaderFooterInheritSourcePage();

        return (int)($sourcePage->getId() ?: 0);
    }
}
