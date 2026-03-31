<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;

class AiSiteMaterializationService
{
    public function __construct(
        private readonly Page $pageModel,
        private readonly PageLayout $pageLayoutModel,
        private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @return array{
     *   pagebuilder_pages_by_type:array<string, array<string, mixed>>,
     *   home_page_id:int,
     *   preview_page_id:int,
     *   preview_page_type:string
     * }
     */
    public function materialize(
        int $websiteId,
        array $websiteProfile,
        array $pageTypes,
        array $pageTypeLayouts
    ): array {
        if ($websiteId <= 0) {
            throw new \InvalidArgumentException((string)__('PageBuilder materialization requires a real website_id'));
        }

        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes($pageTypes);
        $layouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($pageTypeLayouts, $pageTypes);
        $homePageId = 0;
        $pagesByType = [];

        foreach ($pageTypes as $pageType) {
            $page = $this->loadPageByType($websiteId, $pageType) ?? $this->createNewPage();
            $defaults = $this->buildPageDefaults($pageType, $websiteProfile);

            $page->setData(Page::schema_fields_TYPE, $pageType)
                ->setData(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(Page::schema_fields_NAME, $defaults['name'])
                ->setData(Page::schema_fields_TITLE, $defaults['title'])
                ->setData(Page::schema_fields_HANDLE, $this->resolveUniqueHandle(
                    $websiteId,
                    $defaults['handle'],
                    (int)$page->getId()
                ))
                ->setData(Page::schema_fields_CONTENT, '')
                ->setData(Page::schema_fields_PARENT_ID, $pageType === Page::TYPE_HOME ? 0 : $homePageId)
                ->setData(Page::schema_fields_STYLE, 'default')
                ->setData(Page::schema_fields_STYLE_SETTING, '{}')
                ->setData(Page::schema_fields_DEFAULT_LOCALE, (string)($websiteProfile['default_locale'] ?? 'en_US'))
                ->setData(Page::schema_fields_LOCALES, \json_encode($websiteProfile['locales'] ?? ['en_US'], \JSON_UNESCAPED_UNICODE))
                ->setData(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->setData(Page::schema_fields_LOGO, (string)($websiteProfile['logo'] ?? ''))
                ->setData(Page::schema_fields_ICON, (string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''))
                ->setData(Page::schema_fields_META_TITLE, $defaults['meta_title'])
                ->setData(Page::schema_fields_META_DESCRIPTION, $defaults['meta_description'])
                ->setData(Page::schema_fields_META_KEYWORDS, $defaults['meta_keywords'])
                ->setData(Page::schema_fields_AI_DESCRIPTION, (string)($websiteProfile['brief_description'] ?? ''));

            $page->save(true);

            $pageId = (int)$page->getId();
            if ($pageId <= 0) {
                throw new \RuntimeException((string)__('Failed to materialize PageBuilder page: %1', [$pageType]));
            }

            if ($pageType === Page::TYPE_HOME) {
                $homePageId = $pageId;
            } elseif ($homePageId > 0 && (int)$page->getData(Page::schema_fields_PARENT_ID) !== $homePageId) {
                $page->setData(Page::schema_fields_PARENT_ID, $homePageId)->save(true);
            }

            $layoutConfig = $layouts[$pageType] ?? $this->scopeCompatibilityService->normalizeLayoutConfig([]);
            $layout = $this->getOrCreateLayout($pageId);
            $layout->importConfig($layoutConfig)->useOriginalTemplate(false)->save();
            $this->syncLayoutConfigToPage($page, $layout->exportConfig());

            $pagesByType[$pageType] = [
                'page_id' => $pageId,
                'website_id' => $websiteId,
                'type' => $pageType,
                'name' => (string)$page->getData(Page::schema_fields_NAME),
                'title' => (string)$page->getData(Page::schema_fields_TITLE),
                'handle' => (string)$page->getData(Page::schema_fields_HANDLE),
            ];
        }

        $selection = $this->scopeCompatibilityService->resolvePreviewSelection($pagesByType);

        return [
            'pagebuilder_pages_by_type' => $pagesByType,
            'home_page_id' => $homePageId,
            'preview_page_id' => $selection['preview_page_id'],
            'preview_page_type' => $selection['preview_page_type'],
        ];
    }

    private function createNewPage(): Page
    {
        $page = clone $this->pageModel;
        $page->clearData()->clearQuery();

        return $page;
    }

    private function getOrCreateLayout(int $pageId): PageLayout
    {
        return PageLayout::getOrCreateForPage($pageId);
    }

    private function loadPageByType(int $websiteId, string $pageType): ?Page
    {
        $page = clone $this->pageModel;
        $page->clearData()->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_TYPE, $pageType)
            ->order(Page::schema_fields_ID, 'ASC')
            ->limit(1)
            ->find()
            ->fetch();

        return $page->getId() > 0 ? $page : null;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @return array{name:string,title:string,handle:string,meta_title:string,meta_description:string,meta_keywords:string}
     */
    private function buildPageDefaults(string $pageType, array $websiteProfile): array
    {
        $siteTitle = \trim((string)($websiteProfile['site_title'] ?? 'AI Site'));
        $pageLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
        $seo = \is_array($websiteProfile['seo'] ?? null) ? $websiteProfile['seo'] : [];

        $name = $pageType === Page::TYPE_HOME ? $siteTitle : $pageLabel;
        $title = $pageType === Page::TYPE_HOME ? $siteTitle : ($pageLabel . ' - ' . $siteTitle);
        $handle = $pageType === Page::TYPE_HOME
            ? $this->slugify($siteTitle)
            : Page::getDefaultHandleForType($pageType);

        return [
            'name' => $name,
            'title' => $title,
            'handle' => $handle !== '' ? $handle : 'home',
            'meta_title' => $pageType === Page::TYPE_HOME
                ? (string)($seo['meta_title'] ?? $title)
                : $title,
            'meta_description' => (string)($seo['meta_description'] ?? $websiteProfile['brief_description'] ?? ''),
            'meta_keywords' => (string)($seo['meta_keywords'] ?? ''),
        ];
    }

    private function resolveUniqueHandle(int $websiteId, string $desiredHandle, int $currentPageId = 0): string
    {
        $desiredHandle = \trim($desiredHandle);
        if ($desiredHandle === '') {
            return '';
        }

        $conflict = clone $this->pageModel;
        $conflict->clearData()->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_HANDLE, $desiredHandle)
            ->order(Page::schema_fields_ID, 'ASC')
            ->limit(1)
            ->find()
            ->fetch();

        if (!$conflict->getId() || (int)$conflict->getId() === $currentPageId) {
            return $desiredHandle;
        }

        return $desiredHandle . '-' . ($currentPageId > 0 ? $currentPageId : \substr(\md5($desiredHandle . $websiteId), 0, 6));
    }

    /**
     * @param array<string, mixed> $layoutConfig
     */
    private function syncLayoutConfigToPage(Page $page, array $layoutConfig): void
    {
        $pageLayoutConfig = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];

        foreach ($layoutConfig['content'] ?? [] as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $pageLayoutConfig['content'][] = [
                'code' => (string)($component['code'] ?? $component['component'] ?? ''),
                'enabled' => !\array_key_exists('enabled', $component) || (bool)$component['enabled'],
                'config' => \is_array($component['config'] ?? null) ? $component['config'] : [],
                'instance_id' => (string)($component['instance_id'] ?? $component['id'] ?? ''),
            ];
        }

        if (!empty($layoutConfig['header']['component'])) {
            $pageLayoutConfig['header'][] = [
                'code' => (string)$layoutConfig['header']['component'],
                'enabled' => true,
                'config' => \is_array($layoutConfig['header']['config'] ?? null) ? $layoutConfig['header']['config'] : [],
            ];
        }

        if (!empty($layoutConfig['footer']['component'])) {
            $pageLayoutConfig['footer'][] = [
                'code' => (string)$layoutConfig['footer']['component'],
                'enabled' => true,
                'config' => \is_array($layoutConfig['footer']['config'] ?? null) ? $layoutConfig['footer']['config'] : [],
            ];
        }

        $page->setData(Page::schema_fields_LAYOUT_CONFIG, \json_encode($pageLayoutConfig, \JSON_UNESCAPED_UNICODE))
            ->save(true);
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'home';
    }
}
