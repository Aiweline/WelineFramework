<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteAgentWorkspacePreviewService
{
    public function __construct(
        private readonly AiSiteAgentSessionService $sessionService,
        private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService,
        private readonly AiSiteVirtualLayoutService $virtualLayoutService,
        private readonly PageRenderService $pageRenderService,
        private readonly ?AiSitePreviewLinkRewriteService $previewLinkRewriteService = null,
        private readonly ?AiSiteVisualUrlService $visualUrlService = null,
        private readonly ?AiSitePlanJsonStateService $planJsonStateService = null,
    ) {
    }

    /**
     * @return array{
     *   page:Page,
     *   style_code:string,
     *   locale:string,
     *   virtual_theme_id:int,
     *   virtual_pages:array<string, array<string, mixed>>
     * }|null
     */
    public function buildPreviewContext(
        int $adminId,
        string $publicId,
        string $requestedPageType,
        string $requestedStyleCode = '',
        string $requestedLocale = ''
    ): ?array {
        $publicId = \trim($publicId);
        $requestedPageType = \trim($requestedPageType);
        if ($adminId <= 0 || $publicId === '' || $requestedPageType === '') {
            return null;
        }

        $context = $this->virtualLayoutService->loadContext($publicId, $adminId, $requestedPageType);
        if ($context === null) {
            return null;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($context['scope']);
        $scope = $this->scopeCompatibilityService->normalizePreviewContentLocale($scope, $requestedLocale);
        $previewLocale = $this->scopeCompatibilityService->resolvePreviewContentLocale($scope, $requestedLocale);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType(
            $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
            $scope,
            false
        );
        $pageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($pageType === '' || !\is_array($virtualPages[$pageType] ?? null)) {
            return null;
        }

        $virtualThemeId = (int)($context['virtual_theme_id'] ?? 0);
        $virtualPage = $virtualPages[$pageType];
        $styleCode = \trim($requestedStyleCode !== '' ? $requestedStyleCode : (string)($virtualPage['style_code'] ?? 'default'));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $locale = \trim($previewLocale !== '' ? $previewLocale : ($requestedLocale !== '' ? $requestedLocale : (string)($virtualPage['locale'] ?? State::getLang())));
        $locale = $locale !== '' ? $locale : State::getLang();
        $layout = $this->virtualLayoutService->getResolvedLayout($virtualThemeId, $pageType);
        $layout = $this->mergePreviewLayoutFromScope($layout, $scope, $pageType);
        $layout = $this->scopeCompatibilityService->localizeSharedLayoutConfigForScope($layout, $scope, $pageType);
        $virtualBlocks = \is_array($virtualPage['block_nodes'] ?? null) ? $virtualPage['block_nodes'] : [];
        $materializedPreview = $this->resolveMaterializedAiHtmlPreviewData($scope, $pageType);
        $materializedBlocks = \is_array($materializedPreview['block_nodes'] ?? null) ? $materializedPreview['block_nodes'] : [];
        if ($materializedBlocks !== []) {
            $virtualBlocks = $materializedBlocks;
            $virtualPage = \array_replace(
                $virtualPage,
                \is_array($materializedPreview['page'] ?? null) ? $materializedPreview['page'] : []
            );
            $virtualPage['block_nodes'] = $virtualBlocks;
            $virtualPages[$pageType] = $virtualPage;
        }
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;
        $pageTitle = $this->sanitizeVisitorTitle((string)($virtualPage['title'] ?? ''), $scope);
        $metaTitle = $this->sanitizeVisitorTitle((string)($virtualPage['meta_title'] ?? ''), $scope);
        if ($metaTitle === '') {
            $metaTitle = $pageTitle;
        }

        /** @var Page $page */
        $page = ObjectManager::make(Page::class);
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => (int)($scope['draft_website_id'] ?? 0),
            Page::schema_fields_PARENT_ID => $pageType === Page::TYPE_HOME ? 0 : 1,
            Page::schema_fields_LAYOUT_PAGE_ID => 0,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_TITLE => $pageTitle,
            Page::schema_fields_NAME => $pageTitle,
            Page::schema_fields_HANDLE => (string)($virtualPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_TITLE => $metaTitle,
            Page::schema_fields_META_DESCRIPTION => (string)($virtualPage['meta_description'] ?? ''),
            Page::schema_fields_META_KEYWORDS => (string)($virtualPage['meta_keywords'] ?? ''),
            Page::schema_fields_AI_DESCRIPTION => (string)($virtualPage['ai_description'] ?? ''),
            Page::schema_fields_LOCALES => \json_encode([$locale], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_DEFAULT_LOCALE => $locale,
            Page::schema_fields_STYLE_SETTING => \json_encode([
                $styleCode => \is_array($virtualPage['style_settings'] ?? null) ? $virtualPage['style_settings'] : [],
            ], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_LAYOUT_CONFIG => \json_encode($layout, \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_RENDER_MODE => $renderMode,
            Page::schema_fields_AI_LAYOUT => \json_encode(['block_nodes' => $virtualBlocks], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_public_id', $publicId);
        $page->setData('virtual_page_type', $pageType);
        $page->setData('virtual_theme_id', $virtualThemeId);
        $page->setData('virtual_layout_config', $layout);
        $page->setData('virtual_pages_by_type', $virtualPages);
        if (\is_array($scope['design_tokens'] ?? null) && $scope['design_tokens'] !== []) {
            $page->setData('design_tokens', $scope['design_tokens']);
        }
        $themeCssRef = \is_array($scope['theme_css_ref'] ?? null) ? $scope['theme_css_ref'] : [];
        $inlineThemeCss = \trim((string)($scope['theme_css'] ?? ''));
        if ($inlineThemeCss !== '') {
            $themeCssRef['css'] = $inlineThemeCss;
        }
        if ($themeCssRef !== []) {
            $page->setData('theme_css_ref', $themeCssRef);
        }

        return [
            'page' => $page,
            'style_code' => $styleCode,
            'locale' => $locale,
            'virtual_theme_id' => $virtualThemeId,
            'virtual_pages' => $virtualPages,
        ];
    }

    /**
     * @param array<string,mixed> $layout
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function mergePreviewLayoutFromScope(array $layout, array $scope, string $pageType): array
    {
        $pageTypeLayouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $scopeLayout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
        foreach (['header', 'footer'] as $region) {
            $regionLayout = \is_array($scopeLayout[$region] ?? null) ? $scopeLayout[$region] : [];
            if ($regionLayout === []) {
                continue;
            }
            $layout[$region] = \array_replace_recursive(
                \is_array($layout[$region] ?? null) ? $layout[$region] : [],
                $regionLayout
            );
        }

        return $layout;
    }

    /**
     * @return array{block_nodes?:list<array<string,mixed>>,page?:array<string,mixed>}
     */
    private function resolveMaterializedAiHtmlPreviewData(array $scope, string $pageType): array
    {
        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $scope['pagebuilder_pages_by_type'] ?? []
        );
        $pageId = (int)($scope['virtual_pages_by_type'][$pageType]['materialized_page_id'] ?? 0);
        if ($pageId <= 0) {
            $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
        }

        $row = $this->loadMaterializedAiHtmlPreviewPageRow(
            $pageId,
            $pageType,
            (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0)
        );
        if ($row === []) {
            return [];
        }
        if (\trim((string)($row[Page::schema_fields_RENDER_MODE] ?? '')) !== Page::RENDER_MODE_AI_HTML) {
            return [];
        }

        $layout = \json_decode((string)($row[Page::schema_fields_AI_LAYOUT] ?? ''), true);
        $blocks = \is_array($layout['block_nodes'] ?? null) ? $layout['block_nodes'] : [];
        $blocks = \array_values(\array_filter($blocks, static function (mixed $block): bool {
            return \is_array($block)
                && !AiSiteHtmlBlockNodesBuildService::isSharedLayoutBlock($block)
                && \trim((string)($block['html'] ?? $block['config']['html_content'] ?? '')) !== '';
        }));
        if ($blocks === []) {
            return [];
        }

        return [
            'block_nodes' => $blocks,
            'page' => [
                'page_type' => (string)($row[Page::schema_fields_TYPE] ?? $pageType),
                'title' => (string)($row[Page::schema_fields_TITLE] ?? $row[Page::schema_fields_NAME] ?? ''),
                'handle' => (string)($row[Page::schema_fields_HANDLE] ?? ''),
                'locale' => (string)($row[Page::schema_fields_DEFAULT_LOCALE] ?? ''),
                'meta_title' => (string)($row[Page::schema_fields_META_TITLE] ?? ''),
                'meta_description' => (string)($row[Page::schema_fields_META_DESCRIPTION] ?? ''),
                'meta_keywords' => (string)($row[Page::schema_fields_META_KEYWORDS] ?? ''),
                'ai_description' => (string)($row[Page::schema_fields_AI_DESCRIPTION] ?? ''),
                'materialized_page_id' => (int)($row[Page::schema_fields_ID] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadMaterializedAiHtmlPreviewPageRow(int $pageId, string $pageType, int $websiteId): array
    {
        /** @var Page $pageModel */
        $pageModel = ObjectManager::make(Page::class);
        if ($pageId > 0) {
            $rows = (clone $pageModel)
                ->clearData()
                ->clearQuery()
                ->where(Page::schema_fields_ID, $pageId)
                ->pagination(1, 1)
                ->select()
                ->fetchArray();
            if (\is_array($rows[0] ?? null)) {
                return $rows[0];
            }
        }

        if ($websiteId <= 0 || $pageType === '') {
            return [];
        }

        $rows = (clone $pageModel)
            ->clearData()
            ->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_TYPE, $pageType)
            ->pagination(1, 1)
            ->select()
            ->fetchArray();

        return \is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    private function sanitizeVisitorTitle(string $value, array $scope): string
    {
        $title = \preg_replace('/\b(?:website\s*profile|websiteProfile|site\s*profile|profile_json|scope_json|target_domain)\b/iu', '', $value) ?? $value;
        $title = \preg_replace('/\s+/u', ' ', \trim($title)) ?? \trim($title);
        $localized = $this->localizeGenericVisitorTitle($title, $scope);
        if ($localized !== '') {
            return $localized;
        }
        if ($title !== '') {
            return $title;
        }

        foreach ([
            $scope['site_title'] ?? null,
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_name'] ?? null,
            $scope['website_profile']['brand_name'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $candidateTitle = \preg_replace('/\b(?:website\s*profile|websiteProfile|site\s*profile|profile_json|scope_json|target_domain)\b/iu', '', (string)$candidate) ?? (string)$candidate;
            $candidateTitle = \preg_replace('/\s+/u', ' ', \trim($candidateTitle)) ?? \trim($candidateTitle);
            if ($candidateTitle !== '') {
                return $candidateTitle;
            }
        }

        return 'Website';
    }

    private function localizeGenericVisitorTitle(string $title, array $scope): string
    {
        $key = match (\strtolower(\trim($title))) {
            'home', 'homepage' => 'home',
            'about', 'about us' => 'about',
            'contact', 'contact us' => 'contact',
            'privacy policy', 'privacy' => 'privacy_policy',
            'terms of service', 'terms' => 'terms_of_service',
            default => '',
        };
        if ($key === '') {
            return '';
        }

        $locale = \trim((string)(
            $scope['content_locale']
                ?? $scope['website_profile']['content_locale']
                ?? $scope['default_locale']
                ?? $scope['default_language']
                ?? $scope['website_profile']['default_locale']
                ?? ''
        ));

        return match ($key) {
            'home' => 'Home',
            'about' => 'About',
            'contact' => 'Contact',
            'privacy_policy' => 'Privacy Policy',
            'terms_of_service' => 'Terms of Service',
            default => '',
        };
    }

    /**
     * @return array{
     *   session_accessible:bool,
     *   plan_json:array{confirmed:int},
     *   page_type:string
     * }
     */
    public function buildUnavailablePayload(int $adminId, string $publicId, string $requestedPageType): array
    {
        $sessionAccessible = false;
        $planJsonConfirmed = false;
        $publicId = \trim($publicId);
        $requestedPageType = \trim($requestedPageType);
        if ($adminId > 0 && $publicId !== '' && $requestedPageType !== '') {
            $session = $this->sessionService->loadByPublicId($publicId, $adminId);
            if ($session !== null) {
                $sessionAccessible = true;
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
                $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
                $planJsonConfirmed = $this->planJsonStateService()->isConfirmed($planJson);
            }
        }

        return [
            'session_accessible' => $sessionAccessible,
            'plan_json' => ['confirmed' => $planJsonConfirmed ? 1 : 0],
            'page_type' => $requestedPageType,
        ];
    }

    /**
     * @param array{
     *   page:Page,
     *   style_code:string,
     *   locale:string,
     *   virtual_theme_id:int,
     *   virtual_pages:array<string, array<string, mixed>>
     * } $context
     */
    public function renderPreviewHtml(array $context, bool $visualEditor): string
    {
        $renderMode = $visualEditor
            ? PageRenderService::MODE_VISUAL
            : PageRenderService::MODE_PREVIEW;
        $html = $this->pageRenderService->render(
            $context['page'],
            $renderMode,
            $context['locale'],
            $context['style_code'] !== '' ? $context['style_code'] : null,
            $context['virtual_theme_id'] > 0 ? $context['virtual_theme_id'] : null
        );

        if ($visualEditor) {
            $html = $this->getPreviewLinkRewriteService()->rewriteVirtualPreviewLinks(
                $html,
                (string)$context['page']->getData('virtual_public_id'),
                $context['virtual_pages'],
                $context['virtual_theme_id'],
                true
            );

            return $this->injectWorkspacePreviewNavLinks(
                $html,
                $context['virtual_pages'],
                (string)$context['page']->getData('virtual_public_id'),
                $context['virtual_theme_id']
            );
        }

        return $this->getPreviewLinkRewriteService()->rewriteVirtualPreviewLinks(
            $html,
            (string)$context['page']->getData('virtual_public_id'),
            $context['virtual_pages'],
            $context['virtual_theme_id']
        );
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPages
     */
    public function injectWorkspacePreviewNavLinks(
        string $html,
        array $virtualPages,
        string $publicId = '',
        int $virtualThemeId = 0
    ): string
    {
        if ($virtualPages === []) {
            return $html;
        }

        $publicId = \trim($publicId);
        $pages = [];
        $previewRewriteService = $this->getPreviewLinkRewriteService();
        foreach ($virtualPages as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }
            $handle = \trim((string)($pageData['handle'] ?? ''));
            $url = ($pageType === Page::TYPE_HOME || $handle === '') ? '/' : '/' . $handle;
            $previewUrl = '';
            if ($publicId !== '') {
                $resolvedUrls = $this->getVisualUrlService()->resolveVirtualUrls($publicId, $pageType, $virtualThemeId);
                $previewUrl = \trim((string)($resolvedUrls['visual_preview_url'] ?? $resolvedUrls['preview_full_url'] ?? ''));
            }
            $pages[] = [
                'page_type' => $pageType,
                'url' => $url,
                'paths' => $previewRewriteService->resolveVirtualPagePaths($pageType, $pageData),
                'preview_url' => $previewUrl,
            ];
        }
        if ($pages === []) {
            return $html;
        }

        $pagesJson = \json_encode($pages, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $script = <<<SCRIPT
<script>
(function(){
  var pages = {$pagesJson};
  function findPageByHref(href) {
    if (!href || href.charAt(0) === '#') return null;
    var pageType = '';
    var path = '';
    try {
      var parsed = new URL(href, window.location.href);
      pageType = parsed.searchParams.get('page_type') || '';
      path = parsed.pathname || '/';
    } catch (e) {
      path = href.replace(/^https?:\\/\\/[^/]*/, '').replace(/\\?.*$/, '').split('#')[0] || '/';
    }
    if (path === '') path = '/';
    for (var i = 0; i < pages.length; i++) {
      if (pageType && String(pages[i].page_type) === pageType) return pages[i];
      var paths = Array.isArray(pages[i].paths) ? pages[i].paths : [pages[i].url];
      for (var p = 0; p < paths.length; p++) {
        if (paths[p] === path) return pages[i];
      }
    }
    return null;
  }
  var selector = 'header a[href], footer a[href], [data-region="header"] a[href], [data-region="footer"] a[href], nav a[href]';
  var links = document.querySelectorAll(selector);
  for (var j = 0; j < links.length; j++) {
    var link = links[j];
    var page = findPageByHref(link.getAttribute('href'));
    if (!page || !page.page_type) continue;
    if (page.preview_url) {
      link.setAttribute('href', String(page.preview_url));
    }
    link.setAttribute('data-ve-page-type', String(page.page_type));
    link.addEventListener('click', function(e) {
      var pageType = this.getAttribute('data-ve-page-type');
      if (pageType && window.parent !== window) {
        e.preventDefault();
        window.parent.postMessage({ type: 'PageBuilderVisualEditor', action: 'navigate', page_type: pageType }, '*');
      }
    });
  }
})();
</script>
SCRIPT;

        $position = \strripos($html, '</body>');
        if ($position !== false) {
            return \substr($html, 0, $position) . $script . "\n" . \substr($html, $position);
        }

        return $html . $script;
    }

    private function getPreviewLinkRewriteService(): AiSitePreviewLinkRewriteService
    {
        return $this->previewLinkRewriteService
            ?? ObjectManager::getInstance(AiSitePreviewLinkRewriteService::class);
    }

    private function getVisualUrlService(): AiSiteVisualUrlService
    {
        return $this->visualUrlService
            ?? ObjectManager::getInstance(AiSiteVisualUrlService::class);
    }

    private function planJsonStateService(): AiSitePlanJsonStateService
    {
        return $this->planJsonStateService
            ?? ObjectManager::getInstance(AiSitePlanJsonStateService::class);
    }
}
