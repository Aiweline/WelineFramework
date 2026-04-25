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
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType(
            $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
            $scope
        );
        $pageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($pageType === '' || !\is_array($virtualPages[$pageType] ?? null)) {
            return null;
        }

        $virtualThemeId = (int)($context['virtual_theme_id'] ?? 0);
        $virtualPage = $virtualPages[$pageType];
        $styleCode = \trim($requestedStyleCode !== '' ? $requestedStyleCode : (string)($virtualPage['style_code'] ?? 'default'));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $locale = \trim($requestedLocale !== '' ? $requestedLocale : (string)($virtualPage['locale'] ?? State::getLang()));
        $locale = $locale !== '' ? $locale : State::getLang();
        $layout = $this->virtualLayoutService->getResolvedLayout($virtualThemeId, $pageType);
        $virtualBlocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;

        /** @var Page $page */
        $page = ObjectManager::make(Page::class);
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => (int)($scope['draft_website_id'] ?? 0),
            Page::schema_fields_PARENT_ID => $pageType === Page::TYPE_HOME ? 0 : 1,
            Page::schema_fields_LAYOUT_PAGE_ID => 0,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_TITLE => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_NAME => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_HANDLE => (string)($virtualPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_TITLE => (string)($virtualPage['meta_title'] ?? ''),
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
            Page::schema_fields_AI_LAYOUT => \json_encode(['blocks' => $virtualBlocks], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_public_id', $publicId);
        $page->setData('virtual_page_type', $pageType);
        $page->setData('virtual_theme_id', $virtualThemeId);
        $page->setData('virtual_layout_config', $layout);
        $page->setData('virtual_pages_by_type', $virtualPages);

        return [
            'page' => $page,
            'style_code' => $styleCode,
            'locale' => $locale,
            'virtual_theme_id' => $virtualThemeId,
            'virtual_pages' => $virtualPages,
        ];
    }

    /**
     * @return array{
     *   session_accessible:bool,
     *   task_plan_confirmed:bool,
     *   page_type:string
     * }
     */
    public function buildUnavailablePayload(int $adminId, string $publicId, string $requestedPageType): array
    {
        $sessionAccessible = false;
        $taskPlanConfirmed = false;
        $publicId = \trim($publicId);
        $requestedPageType = \trim($requestedPageType);
        if ($adminId > 0 && $publicId !== '' && $requestedPageType !== '') {
            $session = $this->sessionService->loadByPublicId($publicId, $adminId);
            if ($session !== null) {
                $sessionAccessible = true;
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
                $taskPlanConfirmed = (int)($scope['task_plan_confirmed'] ?? 0) === 1;
            }
        }

        return [
            'session_accessible' => $sessionAccessible,
            'task_plan_confirmed' => $taskPlanConfirmed,
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

        if (!$visualEditor) {
            return $html;
        }

        return $this->injectWorkspacePreviewNavLinks($html, $context['virtual_pages']);
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPages
     */
    public function injectWorkspacePreviewNavLinks(string $html, array $virtualPages): string
    {
        if ($virtualPages === []) {
            return $html;
        }

        $pages = [];
        foreach ($virtualPages as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }
            $handle = \trim((string)($pageData['handle'] ?? ''));
            $url = ($pageType === Page::TYPE_HOME || $handle === '') ? '/' : '/' . $handle;
            $pages[] = [
                'page_type' => $pageType,
                'url' => $url,
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
    var path = href.replace(/^https?:\\/\\/[^/]*/, '').replace(/\\?.*$/, '').split('#')[0] || '/';
    if (path === '') path = '/';
    for (var i = 0; i < pages.length; i++) {
      if (pages[i].url === path) return pages[i];
    }
    return null;
  }
  var selector = 'header a[href^="/"], footer a[href^="/"], [data-region="header"] a[href^="/"], [data-region="footer"] a[href^="/"], nav a[href^="/"]';
  var links = document.querySelectorAll(selector);
  for (var j = 0; j < links.length; j++) {
    var link = links[j];
    var page = findPageByHref(link.getAttribute('href'));
    if (!page || !page.page_type) continue;
    link.setAttribute('href', '#');
    link.setAttribute('data-ve-page-type', String(page.page_type));
    link.addEventListener('click', function(e) {
      e.preventDefault();
      var pageType = this.getAttribute('data-ve-page-type');
      if (pageType && window.parent !== window) {
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
}
