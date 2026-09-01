<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\State;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\StorefrontNotFoundStaticPage;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\View\Template;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\DefaultWebsiteService;
use Weline\Websites\Service\ScopeResolver;

/**
 * Generates storefront 404 HTML snapshots under pub/errors/storefront-not-found/.
 *
 * Expensive work (theme slots, product cards, header/footer) runs here during
 * setup:upgrade or explicit publish — not on each 404 request.
 */
final class StorefrontNotFoundStaticGenerator
{
    private const LAYOUT_TYPE = 'not_found';
    private const LAYOUT_OPTION = 'default';
    private const PAGE_TYPE = 'not_found';
    private const RECOMMENDATION_SLOT = 'not-found-recommendations';

    public function __construct(
        private readonly ThemeContextService $themeContext,
        private readonly SlotRendererService $slotRenderer,
        private readonly Template $template,
        private readonly Request $request,
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
    ) {
    }

    /**
     * @return list<string> locale codes written
     */
    public function publishAll(): array
    {
        $this->bootstrapGenerationScope();
        $this->ensureRecommendationDefaultInjections();

        $written = [];
        foreach ($this->discoverAvailableLocales() as $lang) {
            $html = $this->buildHtml($lang);
            if ($html === '') {
                continue;
            }
            $this->saveStaticFile(StorefrontNotFoundStaticPage::staticFilePath($lang), $html);
            $written[] = $lang;
        }

        $this->pruneObsoleteStaticFiles($written);

        return $written;
    }

    public function buildHtml(string $lang): string
    {
        State::setRequestLanguageOverride($lang);
        try {
            return $this->renderThemedNotFoundPage();
        } catch (\Throwable) {
            return $this->fallbackShellHtml();
        } finally {
            State::setRequestLanguageOverride('');
        }
    }

    private function renderThemedNotFoundPage(): string
    {
        $this->bootstrapGenerationScope();

        $theme = $this->themeContext->resolveTheme(PreviewContextService::AREA_FRONTEND, null, false);
        if (!$theme || !$theme->getId()) {
            return $this->fallbackShellHtml();
        }

        $themeId = (int)$theme->getId();
        $catalog = ErrorPageRenderer::catalogEntry(404);

        $this->request->setGet('layout_type', self::LAYOUT_TYPE);
        $this->request->setGet('layout_option', self::LAYOUT_OPTION);
        $this->request->setGet('page_type', self::PAGE_TYPE);

        $meta = [
            'title' => (string)__($catalog['title']),
            'pageTitle' => (string)__($catalog['title']),
            'pageLead' => (string)__($catalog['lead']),
            'pageHint' => (string)__($catalog['hint']),
            'homeHref' => '/',
            'statusCode' => 404,
        ];
        $this->template->assign('meta', $meta);

        $html = (string)$this->template->fetchModuleThemeHtml(
            'Weline_Theme::theme/frontend/layouts/' . self::LAYOUT_TYPE . '/' . self::LAYOUT_OPTION . '.phtml'
        );
        if ($html === '') {
            return $this->fallbackShellHtml();
        }

        $this->slotRenderer->clearCache();

        return $this->slotRenderer->processSlots(
            $html,
            $themeId,
            self::PAGE_TYPE,
            ThemeLayout::STATUS_PUBLISHED,
            PreviewContextService::AREA_FRONTEND,
        );
    }

    private function fallbackShellHtml(): string
    {
        return ErrorPageRenderer::render(404, '', [
            'prefer_json' => false,
            'is_dev' => false,
            'home_href' => '/',
        ]);
    }

    private function ensureRecommendationDefaultInjections(): void
    {
        try {
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->reset()->select()->fetchArray();
            if (!\is_array($themes) || $themes === []) {
                return;
            }

            $identity = [
                'layout_option' => self::LAYOUT_OPTION,
                'scope' => 'default.default.default',
                'locale_code' => '',
                'target_type' => ThemeVirtualLayout::TARGET_GLOBAL,
                'target_id' => 0,
            ];

            foreach ($themes as $themeRow) {
                if (!\is_array($themeRow)) {
                    continue;
                }
                $themeId = (int)($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }
                $this->defaultInjectionService->initSlotDefaultInjections(
                    $themeId,
                    self::PAGE_TYPE,
                    $identity,
                    self::RECOMMENDATION_SLOT,
                    PreviewContextService::AREA_FRONTEND,
                    ThemeLayout::STATUS_PUBLISHED,
                );
            }
        } catch (\Throwable) {
            // Static generation can still proceed with whatever layout rows already exist.
        }
    }

    /**
     * @return list<string>
     */
    private function discoverAvailableLocales(): array
    {
        try {
            $codes = ObjectManager::getInstance(ActiveLocaleCodeProvider::class)->getInstalledActiveCodes();
            $normalized = $this->normalizeLocaleCodes($codes);
            if ($normalized !== []) {
                return $normalized;
            }
        } catch (\Throwable) {
        }

        return [StorefrontNotFoundStaticPage::DEFAULT_LANG, 'en_US'];
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function normalizeLocaleCodes(array $codes): array
    {
        $locales = [];
        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $code) !== 1) {
                continue;
            }
            $locales[] = $code;
        }

        \sort($locales);

        return $locales;
    }

    /**
     * @param list<string> $activeLocales
     */
    private function pruneObsoleteStaticFiles(array $activeLocales): void
    {
        $active = \array_fill_keys($activeLocales, true);
        $dir = (\defined('PUB') ? \rtrim((string)PUB, \DIRECTORY_SEPARATOR) : BP . 'pub')
            . \DIRECTORY_SEPARATOR . 'errors' . \DIRECTORY_SEPARATOR . 'storefront-not-found';
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*.html') ?: [] as $file) {
            $code = \basename((string)$file, '.html');
            if (!isset($active[$code])) {
                @\unlink($file);
            }
        }
    }

    private function saveStaticFile(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $contents) === false) {
            return;
        }

        @\rename($tmp, $path);
    }

    private function bootstrapGenerationScope(): void
    {
        if (RequestContext::scopeIdentity() instanceof ScopeIdentity) {
            return;
        }

        try {
            $defaults = ObjectManager::getInstance(DefaultWebsiteService::class)->ensureDefaultWebsite(false);
            $websiteId = (int)($defaults['website_id'] ?? Website::ID_DEFAULT);
            $websiteCode = (string)($defaults['website_code'] ?? Website::CODE_DEFAULT);
            ObjectManager::getInstance(ScopeResolver::class)->resolve(
                $websiteId,
                $websiteCode,
                'http://127.0.0.1/',
                [],
                '/',
            );

            return;
        } catch (\Throwable) {
        }

        RequestContext::installScopeIdentity(ScopeIdentity::global());
    }
}
