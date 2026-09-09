<?php

declare(strict_types=1);

namespace Weline\Help\Service;

use Weline\Cms\Model\Page;
use Weline\Cms\Service\CmsEditorContextResolver;
use Weline\Cms\Service\PageService;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Help\Api\Uri\HelpNamespace;
use Weline\Theme\Model\ThemeLayout;

final class HelpCmsEditorService
{
    public function isAvailable(): bool
    {
        return class_exists(PageService::class) && class_exists(Page::class);
    }

    /**
     * @param array<string, mixed> $siteParams
     */
    public function createDraft(array $siteParams = []): ?Page
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $pageService = ObjectManager::getInstance(PageService::class);
        $siteParams['kind'] = 'help';
        $siteParams['path_group'] = HelpNamespace::PREFIX;

        return $pageService->createDraftPage('default', 'default', $siteParams);
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
        $layoutType = ThemeLayout::PAGE_TYPE_FAQ;
        $url = ObjectManager::getInstance(Url::class);
        $scopeIdentity = $context->scopeIdentity->toArray();

        return $url->getBackendUrl('theme/backend/theme-editor', [
            'page_type' => $layoutType,
            'layout_type' => $layoutType,
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
            // Typed claims for the new Theme visual editor ScopeSelectorCatalog.
            'scope_kind' => (string)($scopeIdentity['scope_kind'] ?? 'store'),
            'context_version' => (string)($scopeIdentity['context_version'] ?? 'v1'),
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
            'lock_source' => 'help',
        ]);
    }

}
