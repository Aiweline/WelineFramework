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
     *   plan_json_pages:array<string, array<string, mixed>>
     * }|null
     */
    public function buildPreviewContext(
        int $adminId,
        string $publicId,
        string $requestedPageType,
        string $requestedStyleCode = '',
        string $requestedLocale = '',
        int $requestedVirtualThemeId = 0
    ): ?array {
        $publicId = \trim($publicId);
        $requestedPageType = \trim($requestedPageType);
        if ($adminId <= 0 || $publicId === '' || $requestedPageType === '') {
            return null;
        }

        $context = $this->virtualLayoutService->loadContext($publicId, $adminId, $requestedPageType);
        if ($context === null) {
            $context = $this->buildUrlVirtualThemePreviewContext(
                $adminId,
                $publicId,
                $requestedPageType,
                $requestedVirtualThemeId
            );
            if ($context === null) {
                return null;
            }
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($context['scope']);
        $scope = $this->scopeCompatibilityService->normalizePreviewContentLocale($scope, $requestedLocale);
        $previewLocale = $this->scopeCompatibilityService->resolvePreviewContentLocale($scope, $requestedLocale);
        $planJsonPages = $this->resolvePlanJsonPages($scope);
        $virtualThemeId = \max((int)($context['virtual_theme_id'] ?? 0), $requestedVirtualThemeId);
        $layout = $this->virtualLayoutService->getResolvedLayout($virtualThemeId, $requestedPageType);
        $layout = $this->scopeCompatibilityService->localizeSharedLayoutConfigForScope($layout, $scope, $requestedPageType);
        if ($planJsonPages === [] && $this->layoutHasPreviewContent($layout)) {
            $planJsonPages[$requestedPageType] = $this->buildLayoutBackedPreviewPage($requestedPageType, $scope);
        }
        $pageType = isset($planJsonPages[$requestedPageType]) ? $requestedPageType : (string)\array_key_first($planJsonPages);
        if ($pageType === '' || !\is_array($planJsonPages[$pageType] ?? null)) {
            return null;
        }

        $planJsonPage = $planJsonPages[$pageType];
        $styleCode = \trim($requestedStyleCode !== '' ? $requestedStyleCode : (string)($planJsonPage['style_code'] ?? 'default'));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $locale = \trim($previewLocale !== '' ? $previewLocale : ($requestedLocale !== '' ? $requestedLocale : (string)($planJsonPage['locale'] ?? State::getLang())));
        $locale = $locale !== '' ? $locale : State::getLang();
        if ($pageType !== $requestedPageType) {
            $layout = $this->virtualLayoutService->getResolvedLayout($virtualThemeId, $pageType);
            $layout = $this->scopeCompatibilityService->localizeSharedLayoutConfigForScope($layout, $scope, $pageType);
        }
        $virtualBlocks = \array_values($this->resolvePlanJsonPageBlocks($planJsonPage));
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;
        $pageTitle = $this->sanitizeVisitorTitle((string)($planJsonPage['title'] ?? ''), $scope);
        $metaTitle = $this->sanitizeVisitorTitle((string)($planJsonPage['meta_title'] ?? ''), $scope);
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
            Page::schema_fields_HANDLE => (string)($planJsonPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_TITLE => $metaTitle,
            Page::schema_fields_META_DESCRIPTION => (string)($planJsonPage['meta_description'] ?? ''),
            Page::schema_fields_META_KEYWORDS => (string)($planJsonPage['meta_keywords'] ?? ''),
            Page::schema_fields_AI_DESCRIPTION => (string)($planJsonPage['ai_description'] ?? ''),
            Page::schema_fields_LOCALES => \json_encode([$locale], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_DEFAULT_LOCALE => $locale,
            Page::schema_fields_STYLE_SETTING => \json_encode([
                $styleCode => \is_array($planJsonPage['style_settings'] ?? null) ? $planJsonPage['style_settings'] : [],
            ], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_LAYOUT_CONFIG => \json_encode($layout, \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_RENDER_MODE => $renderMode,
            Page::schema_fields_AI_LAYOUT => \json_encode(['blocks' => $virtualBlocks], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_public_id', $publicId);
        $page->setData('virtual_page_type', $pageType);
        $page->setData('virtual_theme_id', $virtualThemeId);
        $page->setData('virtual_layout_config', $layout);
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
            'plan_json_pages' => $planJsonPages,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,array<string,mixed>>
     */
    private function resolvePlanJsonPages(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $result = [];
        foreach ($pages as $pageType => $page) {
            if (\is_string($pageType) && \is_array($page)) {
                $result[$pageType] = $page;
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   session:AiSiteAgentSession,
     *   scope:array<string, mixed>,
     *   virtual_theme_id:int,
     *   page_type:string
     * }|null
     */
    private function buildUrlVirtualThemePreviewContext(
        int $adminId,
        string $publicId,
        string $pageType,
        int $virtualThemeId
    ): ?array {
        if ($virtualThemeId <= 0) {
            return null;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return null;
        }

        $scope = $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT, ['plan_json']);
        if (!\is_array($scope) || $scope === []) {
            $scope = $session->getScopeArray();
        }
        $scope = $this->scopeCompatibilityService->normalizePreviewContentLocale($scope);
        $scope = $this->scopeCompatibilityService->normalizeScope($scope);
        $scope['virtual_theme_id'] = $virtualThemeId;

        return [
            'session' => $session,
            'scope' => $scope,
            'virtual_theme_id' => $virtualThemeId,
            'page_type' => $pageType,
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @return array<string,array<string,mixed>>
     */
    private function resolvePlanJsonPageBlocks(array $page): array
    {
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!\is_string($key) || !\is_array($value)) {
                continue;
            }
            if (\in_array($key, [
                'layout',
                'page_meta',
                'meta',
                'seo',
                'route',
                'assets',
                'style_settings',
                'design_tokens',
                'theme_css_ref',
                'navigation',
                'menus',
                'links',
                'settings',
                'blocks',
                'block_previews',
                'sections',
                'components',
            ], true)) {
                continue;
            }
            $html = \trim((string)($value['html'] ?? $value['config']['html_content'] ?? ''));
            if ($html === '') {
                continue;
            }
            $blocks[$key] = $value;
        }

        return $blocks;
    }

    /**
     * @param array<string,mixed> $layout
     */
    private function layoutHasPreviewContent(array $layout): bool
    {
        foreach (['header', 'footer'] as $region) {
            if (\trim((string)($layout[$region]['component'] ?? $layout[$region]['code'] ?? '')) !== '') {
                return true;
            }
        }
        foreach (($layout['content'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (\trim((string)($row['code'] ?? $row['component'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildLayoutBackedPreviewPage(string $pageType, array $scope): array
    {
        $pageLabels = Page::getPageTypes();
        $label = (string)($pageLabels[$pageType] ?? $pageType);
        $siteTitle = $this->sanitizeVisitorTitle((string)(
            $scope['site_title']
                ?? $scope['website_profile']['site_title']
                ?? $scope['site_name']
                ?? $scope['website_profile']['brand_name']
                ?? 'Website'
        ), $scope);
        $title = $pageType === Page::TYPE_HOME ? $siteTitle : \trim($label . ' - ' . $siteTitle);

        return [
            'page_type' => $pageType,
            'title' => $title !== '' ? $title : $label,
            'handle' => $pageType === Page::TYPE_HOME ? '' : Page::getDefaultHandleForType($pageType),
            'locale' => (string)($scope['content_locale'] ?? $scope['website_profile']['content_locale'] ?? State::getLang()),
            'style_code' => 'default',
            'status' => 1,
            'preview_source' => 'virtual_theme_layout',
        ];
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
                    $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT, [])
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
     *   plan_json_pages:array<string, array<string, mixed>>
     * } $context
     */
    public function renderPreviewHtml(array $context, bool $visualEditor): string
    {
        $planJsonPages = \is_array($context['plan_json_pages'] ?? null) ? $context['plan_json_pages'] : [];
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
                $planJsonPages,
                $context['virtual_theme_id'],
                true
            );

            return $this->injectWorkspacePreviewNavLinks(
                $html,
                $planJsonPages,
                (string)$context['page']->getData('virtual_public_id'),
                $context['virtual_theme_id']
            );
        }

        return $this->getPreviewLinkRewriteService()->rewriteVirtualPreviewLinks(
            $html,
            (string)$context['page']->getData('virtual_public_id'),
            $planJsonPages,
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
