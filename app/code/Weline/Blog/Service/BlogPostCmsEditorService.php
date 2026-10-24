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
use Weline\Framework\Runtime\ScopeIdentity;

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
    public function findCmsPageId(array $post): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $websiteId = (int)($post[Post::schema_fields_WEBSITE_ID] ?? 0);
        $slug = trim(strtolower((string)($post[Post::schema_fields_SLUG] ?? '')));
        if ($websiteId < 0 || $slug === '') {
            return 0;
        }

        $existing = $this->cmsAdapter->getPageForConflictCheck($websiteId, $slug);

        return (int)($existing['page_id'] ?? 0);
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
        $scopeIdentity = $context->scopeIdentity->toArray();
        $publicRoute = ltrim(trim($page->getIdentifier()), '/');

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
            'scope_kind' => (string)($scopeIdentity['scope_kind'] ?? ScopeIdentity::KIND_STORE),
            'context_version' => (string)($scopeIdentity['context_version'] ?? ScopeIdentity::CONTEXT_VERSION),
            'store_mode' => $context->storeMode,
            'link_block' => '1',
            'preview_entity_route' => $publicRoute,
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

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function buildEditorBridgeState(int $cmsPageId, string $locale, array $post = []): array
    {
        if (!$this->isAvailable() || $cmsPageId <= 0) {
            return [];
        }

        $pageService = ObjectManager::getInstance(PageService::class);
        $page = $pageService->getPageModel($cmsPageId);
        if ($page === null) {
            return [];
        }

        $locale = trim($locale) !== '' ? trim($locale) : trim((string)($post[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN'));
        $resolver = ObjectManager::getInstance(CmsEditorContextResolver::class);
        $context = $resolver->resolve($page, 0, $locale);
        $localeService = ObjectManager::getInstance(PageLocaleService::class);
        $localePayload = $localeService->buildEditorPayload($page, $context->localeCode, $context->storeId);
        $supportedLocales = array_values(array_filter(array_map(
            static fn ($code) => trim((string)$code),
            (array)($localePayload['supported_locales'] ?? []),
        )));
        $titles = is_array($localePayload['titles'] ?? null) ? (array)$localePayload['titles'] : [];
        $sourceLocale = (string)($localePayload['source_locale'] ?? $locale);
        $missingLocales = [];
        foreach ($supportedLocales as $code) {
            $code = trim((string)$code);
            if ($code === '' || $code === $sourceLocale) {
                continue;
            }
            if (trim((string)($titles[$code] ?? '')) === '') {
                $missingLocales[] = $code;
            }
        }

        return [
            'page_id' => $cmsPageId,
            'pageId' => $cmsPageId,
            'websiteId' => $page->getWebsiteId(),
            'websiteCode' => $page->getWebsiteCode(),
            'storeId' => $context->storeId,
            'storeCode' => $context->storeCode,
            'storeMode' => $context->storeMode,
            'scope' => $context->canonicalScope,
            'publicRoute' => ltrim(trim($page->getIdentifier()), '/'),
            'current_locale' => (string)($localePayload['current_locale'] ?? $locale),
            'source_locale' => $sourceLocale,
            'layout_option' => 'default',
            'supported_locales' => $supportedLocales,
            'titles' => $titles,
            'translation' => [
                'type_code' => 'cms.page_translation',
                'missing_locales' => $missingLocales,
                'messages' => [
                    'button' => (string)__('翻译 CMS 页面缺失语言'),
                    'continue' => (string)__('继续查看翻译任务'),
                    'running' => (string)__('翻译进行中'),
                    'save_first' => (string)__('请先保存文章设置，再翻译 CMS 页面语言。'),
                    'ready' => (string)__('待补全 CMS 语言：'),
                    'starting' => (string)__('正在启动翻译任务…'),
                    'progress' => (string)__('正在翻译语言'),
                    'complete' => (string)__('CMS 页面语言均已补全。'),
                    'failed' => (string)__('翻译任务未完成，请稍后重试。'),
                ],
            ],
        ];
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
