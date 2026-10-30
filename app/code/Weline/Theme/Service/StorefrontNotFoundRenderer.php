<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\StaticErrorPagePublisher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Framework\View\Template;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Service\LayoutEntity\StorefrontFloatLayerHost;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ScopeResolver;
use Weline\Websites\Service\ThemeApplicationContextProducer;

/**
 * Renders the storefront 404 page from the theme `not_found` layout.
 *
 * This is the render half of the retired StorefrontNotFoundStaticGenerator:
 * it keeps the proven theme-aware render pipeline (ThemeApplicationContext +
 * Template::fetchHtml design inheritance + slot rendering + float-layer host)
 * but no longer writes pub/errors snapshots. Callers cache the HTML into the
 * per-language special FPC store (Framework\Http\Fpc\StorefrontNotFoundFpcStore).
 *
 * Rendering is isolated in a cloned Context and renders the anonymous shell
 * (header auth state is client-side), so a per-language cached page is safe to
 * serve to every visitor.
 */
final class StorefrontNotFoundRenderer
{
    private const LAYOUT_TYPE = 'not_found';
    private const LAYOUT_OPTION = 'default';
    private const PAGE_TYPE = 'not_found';
    private const RECOMMENDATION_SLOT = 'not-found-recommendations';

    /** @var array<int, true> */
    private static array $injectionSeeded = [];

    public function __construct(
        private readonly ThemeContextService $themeContext,
        private readonly SlotRendererService $slotRenderer,
        private readonly Template $template,
        private readonly Request $request,
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
    ) {
    }

    public function render(int $websiteId, string $websiteCode, string $lang, string $websiteUrl = ''): string
    {
        // Render in an isolated Context so the per-locale language override and
        // scope identity never land in the shared main context.
        $previousContext = Context::getCurrent();
        Context::enter(new Context($previousContext !== null ? $previousContext->toArray() : []));

        try {
            return $this->renderInIsolatedContext($lang, $websiteId, $websiteCode, $websiteUrl);
        } finally {
            try {
                Context::leave();
                if ($previousContext !== null) {
                    Context::enter($previousContext);
                }
            } catch (\Throwable) {
            }
        }
    }

    private function renderInIsolatedContext(string $lang, int $websiteId, string $websiteCode, string $websiteUrl): string
    {
        $this->isolatePhraseBagForStaticRender();
        RequestContext::set(StaticErrorPagePublisher::CTX_PUBLISHING, true);

        Context::current();
        State::setRequestLanguageOverride($lang);
        try {
            StorefrontScopeHotCache::resetProcessCache();
            \Weline\Theme\Block\Partials::clearOutputCache();
            RequestContext::resetWelineVars();
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.runtime');
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.editor');
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.preview');
            RequestContext::set(StaticErrorPagePublisher::CTX_PUBLISHING, true);
            RequestContext::installScopeIdentity($this->authoritativeWebsiteIdentity($websiteId, $websiteCode));
        } catch (\Throwable) {
            RequestContext::set(StaticErrorPagePublisher::CTX_PUBLISHING, true);
        }

        try {
            if (\class_exists(Website::class) && \class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                /** @var Website $website */
                $website = ObjectManager::getInstance(Website::class);
                $website->clear()->where(Website::schema_fields_ID, $websiteId)->find()->fetch();
                if ($website->hasData(Website::schema_fields_ID) || $websiteId === Website::ID_DEFAULT) {
                    if (!$website->hasData(Website::schema_fields_CODE)) {
                        $website->setCode($websiteCode);
                        $website->setData(Website::schema_fields_ID, $websiteId);
                    }
                    \Weline\Websites\Data\WebsiteData::setWebsite($website);
                }
            }
        } catch (\Throwable) {
        }

        Context::current();
        State::setRequestLanguageOverride($lang);
        try {
            $html = $this->renderThemedNotFoundPage($websiteId, $websiteCode, $websiteUrl);
            $html = SlotBoundaryMarkers::strip($html);

            return self::stripClientTranslationDictionaries($html);
        } catch (\Throwable) {
            return self::stripClientTranslationDictionaries($this->fallbackShellHtml());
        } finally {
            State::setRequestLanguageOverride('');
            RequestContext::set(StaticErrorPagePublisher::CTX_PUBLISHING, false);
        }
    }

    private function renderThemedNotFoundPage(int $websiteId, string $websiteCode, string $websiteUrl): string
    {
        $identity = $this->authoritativeWebsiteIdentity($websiteId, $websiteCode);
        try {
            if (RequestContext::scopeIdentity() === null) {
                $url = $websiteUrl !== '' ? $websiteUrl : 'http://127.0.0.1/';
                ObjectManager::getInstance(ScopeResolver::class)->resolve(
                    $websiteId,
                    $websiteCode,
                    $url,
                    [],
                    '/',
                );
            }
        } catch (\Throwable) {
            try {
                RequestContext::installScopeIdentity($identity);
            } catch (\Throwable) {
            }
        }

        $application = $this->installWebsiteThemeApplication($identity, $websiteId);
        $theme = $application !== null
            ? $this->themeContext->resolveThemeForScope(PreviewContextService::AREA_FRONTEND, $application)
            : null;
        if (!$theme || !$theme->getId()) {
            $theme = $this->themeContext->resolveTheme(PreviewContextService::AREA_FRONTEND, null, false);
        }
        if (!$theme || !$theme->getId()) {
            return $this->fallbackShellHtml();
        }

        $themeId = (int)$theme->getId();
        $this->ensureRecommendationDefaultInjections($themeId);

        $catalog = ErrorPageRenderer::catalogEntry(404);
        $title = \Weline\Theme\Helper\WidgetI18n::label((string)$catalog['title']);
        $lead = \Weline\Theme\Helper\WidgetI18n::label((string)$catalog['lead']);
        $hint = \Weline\Theme\Helper\WidgetI18n::label((string)$catalog['hint']);

        $this->request->setGet('layout_type', self::LAYOUT_TYPE);
        $this->request->setGet('layout_option', self::LAYOUT_OPTION);
        $this->request->setGet('page_type', self::PAGE_TYPE);

        $meta = [
            'title' => $title,
            'pageTitle' => $title,
            'pageLead' => $lead,
            'pageHint' => $hint,
            'homeHref' => '/',
            'statusCode' => 404,
        ];
        $this->template->assign('meta', $meta);

        // Theme-aware fetch (design overlay + inheritance). Do NOT use
        // fetchModuleThemeHtml — it forces module Theme and skips design skins.
        $html = (string)$this->template->fetchHtml(
            'Weline_Theme::theme/frontend/layouts/' . self::LAYOUT_TYPE . '/' . self::LAYOUT_OPTION . '.phtml'
        );
        if ($html === '') {
            return $this->fallbackShellHtml();
        }

        $html = $this->slotRenderer->processSlots(
            $html,
            $themeId,
            self::PAGE_TYPE,
            ThemeLayout::STATUS_PUBLISHED,
            PreviewContextService::AREA_FRONTEND,
        );

        return StorefrontFloatLayerHost::ensureInHtml($html);
    }

    /**
     * Build + install Website ThemeApplicationContext for the target site.
     * resolveThemeForScope requires ThemeApplicationContext — never pass ScopeIdentity.
     */
    private function installWebsiteThemeApplication(ScopeIdentity $identity, int $websiteId): ?ThemeApplicationContext
    {
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website = (clone $website)->load($websiteId, null, true);
            if (!$website->hasData(Website::schema_fields_ID) && $websiteId !== Website::ID_DEFAULT) {
                return null;
            }
            $locale = \trim((string)$website->getDefaultLanguage());
            if ($locale === '') {
                $locale = \Weline\Framework\Http\StorefrontLocale::DEFAULT_LANG;
            }
            $storeMode = $identity->storeMode ?? ScopeIdentity::MODE_NORMAL;
            /** @var ThemeApplicationContextProducer $producer */
            $producer = ObjectManager::getInstance(ThemeApplicationContextProducer::class);
            $application = $producer->build(
                identity: $identity,
                storeMode: $storeMode,
                defaultLocale: $locale,
                displayName: (string)$website->getName(),
                purpose: 'runtime',
            );
            $application->install();

            return $application;
        } catch (\Throwable) {
            return ThemeApplicationContext::current(PreviewContextService::AREA_FRONTEND);
        }
    }

    private function fallbackShellHtml(): string
    {
        return ErrorPageRenderer::render(404, '', [
            'prefer_json' => false,
            'is_dev' => false,
            'home_href' => '/',
            'skip_static' => true,
        ]);
    }

    private function ensureRecommendationDefaultInjections(int $themeId): void
    {
        if ($themeId <= 0 || isset(self::$injectionSeeded[$themeId])) {
            return;
        }
        self::$injectionSeeded[$themeId] = true;
        try {
            $identity = [
                'layout_option' => self::LAYOUT_OPTION,
                'scope' => 'default.default.default',
                'locale_code' => '',
                'target_type' => ThemeVirtualLayout::TARGET_GLOBAL,
                'target_id' => 0,
            ];
            $this->defaultInjectionService->initSlotDefaultInjections(
                $themeId,
                self::PAGE_TYPE,
                $identity,
                self::RECOMMENDATION_SLOT,
                PreviewContextService::AREA_FRONTEND,
                ThemeLayout::STATUS_PUBLISHED,
            );
        } catch (\Throwable) {
            // Rendering can proceed with whatever layout rows already exist.
        }
    }

    private function authoritativeWebsiteIdentity(int $websiteId, string $websiteCode): ScopeIdentity
    {
        $identity = ScopeIdentity::website($websiteId, $websiteCode);
        try {
            $catalog = ObjectManager::getInstance(
                \Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface::class
            );
            $identity = $catalog->authoritativeIdentity($identity);
        } catch (\Throwable) {
        }

        return $identity;
    }

    private function isolatePhraseBagForStaticRender(): void
    {
        \Weline\Framework\Phrase\Parser::clearUsedWords();
        if (\class_exists(\Weline\I18n\Helper\JsWordsRegistry::class)) {
            \Weline\I18n\Helper\JsWordsRegistry::reset();
        }
    }

    /**
     * Remove client-side multi-locale translation bags from the per-locale HTML.
     * The page is already SSR'd for one locale; embedding widgetTranslations
     * (all locales) is redundant and bloats the cached page.
     */
    public static function stripClientTranslationDictionaries(string $html): string
    {
        if ($html === '' || !\str_contains($html, 'widgetTranslations')) {
            return $html;
        }

        $needle = 'widgetTranslations';
        $offset = 0;
        while (($pos = \strpos($html, $needle, $offset)) !== false) {
            $colon = \strpos($html, ':', $pos + \strlen($needle));
            if ($colon === false) {
                break;
            }
            $braceStart = \strpos($html, '{', $colon);
            if ($braceStart === false || $braceStart > $colon + 32) {
                $offset = $pos + \strlen($needle);
                continue;
            }
            $braceEnd = self::matchBalancedJsonObjectEnd($html, $braceStart);
            if ($braceEnd === null) {
                break;
            }
            $html = \substr($html, 0, $braceStart) . '{}' . \substr($html, $braceEnd);
            $offset = $braceStart + 2;
        }

        return $html;
    }

    private static function matchBalancedJsonObjectEnd(string $html, int $braceStart): ?int
    {
        $len = \strlen($html);
        $depth = 0;
        $inString = false;
        $escape = false;
        for ($i = $braceStart; $i < $len; $i++) {
            $ch = $html[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return null;
    }
}
