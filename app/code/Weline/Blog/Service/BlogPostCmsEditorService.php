<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Post;
use Weline\Cms\Model\Page;
use Weline\Cms\Service\CmsEditorContextResolver;
use Weline\Cms\Service\PageLocaleService;
use Weline\Cms\Service\PageService;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

final class BlogPostCmsEditorService
{
    public function __construct(
        private readonly CmsBlogPageAdapter $cmsAdapter,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists(PageService::class) && class_exists(Page::class);
    }

    /**
     * @param array<string,mixed> $post
     */
    public function syncCmsPageFromPost(array $post): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $websiteId = (int)($post[Post::schema_fields_WEBSITE_ID] ?? 0);
        $slug = trim(strtolower((string)($post[Post::schema_fields_SLUG] ?? '')));
        $title = trim((string)($post[Post::schema_fields_TITLE] ?? ''));
        $locale = trim((string)($post[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN'));
        $status = trim((string)($post[Post::schema_fields_STATUS] ?? Post::STATUS_DRAFT));

        if ($websiteId < 0 || $slug === '' || $title === '') {
            return 0;
        }

        $existing = $this->cmsAdapter->getPageForConflictCheck($websiteId, $slug);
        $pageId = (int)($existing['page_id'] ?? 0);

        $pagePrototype = ObjectManager::getInstance(Page::class);
        $draftPage = clone $pagePrototype;
        $draftPage->setData(Page::schema_fields_WEBSITE_ID, $websiteId);
        if ($websiteId === 0) {
            $draftPage->setData(Page::schema_fields_WEBSITE_CODE, 'default');
        }
        if ($pageId > 0) {
            $draftPage->setData(Page::schema_fields_ID, $pageId);
        }

        $localeService = ObjectManager::getInstance(PageLocaleService::class);
        $localeContext = $localeService->prepareWriteLocales(
            $draftPage,
            $locale !== '' ? $locale : 'zh_Hans_CN',
            '',
            null,
        );
        $sourceLocale = (string)$localeContext['source_locale'];
        $writeLocale = $pageId > 0
            ? (string)$localeContext['locale_code']
            : $sourceLocale;
        $localeTitles = [$sourceLocale => $title];
        if ($locale !== '' && $locale !== $sourceLocale) {
            $localeTitles[$locale] = $title;
        }

        $pageService = ObjectManager::getInstance(PageService::class);
        $savePayload = [
            'page_id' => $pageId,
            'website_id' => $websiteId,
            'path_group' => BlogNamespace::PREFIX,
            'slug' => $slug,
            'title' => $title,
            'status' => $status,
            'locale_code' => $writeLocale,
            'locale_titles' => $localeTitles,
            'scope' => 'default',
        ];
        if ($websiteId === 0) {
            $savePayload['website_code'] = 'default';
        }
        $saved = $pageService->savePage($savePayload);

        $savedPageId = $saved->getPageId();
        if ($pageId <= 0 && $savedPageId > 0) {
            $pageService->saveLayoutSelection($savedPageId, 'default', 'default', $locale);
        }

        return $savedPageId;
    }

    public function buildThemeEditorUrl(int $cmsPageId, string $locale = ''): string
    {
        if (!$this->isAvailable() || $cmsPageId <= 0) {
            return '';
        }

        $pageService = ObjectManager::getInstance(PageService::class);
        $page = $pageService->getPageModel($cmsPageId);
        if ($page === null) {
            return '';
        }

        $locale = trim($locale);
        $resolver = ObjectManager::getInstance(CmsEditorContextResolver::class);
        $context = $resolver->resolve($page, 0, $locale);

        $url = ObjectManager::getInstance(Url::class);

        return $url->getBackendUrl('theme/backend/theme-editor', [
            'page_type' => Page::LAYOUT_TYPE,
            'layout_type' => Page::LAYOUT_TYPE,
            'layout_option' => 'default',
            'lock_layout' => 1,
            'lock_layout_context' => 1,
            'layout_lock_target_type' => Page::TARGET_TYPE,
            'target_id' => $page->getPageId(),
            'virtual_target_type' => Page::TARGET_TYPE,
            'virtual_target_id' => $page->getPageId(),
            'theme_layout_target_type' => Page::TARGET_TYPE,
            'theme_layout_target_id' => $page->getPageId(),
            'theme_layout_source_target_type' => Page::TARGET_TYPE,
            'theme_layout_source_target_id' => $page->getPageId(),
            'scope' => $context->canonicalScope,
            'store_mode' => $context->storeMode,
            'locale' => $locale,
            'locale_code' => $locale,
            'website_id' => $page->getWebsiteId(),
            'website_code' => $page->getWebsiteCode(),
            'store_id' => $context->storeId,
            'store_code' => $context->storeCode,
            'cms_page_id' => $page->getPageId(),
            'editor_area' => 'frontend',
            'preview_area' => 'frontend',
            'status' => 'draft',
            'lock_source' => 'cms',
        ]);
    }

    public function buildPreviewUrl(int $cmsPageId, string $locale = ''): string
    {
        if (!$this->isAvailable() || $cmsPageId <= 0) {
            return '';
        }

        try {
            $pageService = ObjectManager::getInstance(PageService::class);
            $page = $pageService->getPageModel($cmsPageId);
            if ($page === null) {
                return '';
            }

            return $pageService->buildPreviewUrl($page, null, null, trim($locale));
        } catch (\Throwable) {
            return '';
        }
    }
}
