<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\State;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\StaticErrorPageMap;
use Weline\Framework\Http\StaticErrorPagePublisher;
use Weline\Framework\Http\StorefrontNotFoundStaticPage;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;
use Weline\Framework\View\Template;
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
 * Publishes website×locale via StaticErrorPagePublisher (cooperative Fibers).
 */
final class StorefrontNotFoundStaticGenerator
{
    private const LAYOUT_TYPE = 'not_found';
    private const LAYOUT_OPTION = 'default';
    private const PAGE_TYPE = 'not_found';
    private const RECOMMENDATION_SLOT = 'not-found-recommendations';

    /** @var array{website_id: int, website_code: string}|null */
    private ?array $publishSite = null;

    public function __construct(
        private readonly ThemeContextService $themeContext,
        private readonly SlotRendererService $slotRenderer,
        private readonly Template $template,
        private readonly Request $request,
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
        private readonly Printing $printing,
    ) {
    }

    /**
     * @return list<string> websiteCode/locale labels written
     */
    public function publishAll(): array
    {
        $this->isolatePhraseBagForStaticRender();
        $this->ensureRecommendationDefaultInjections();
        $this->slotRenderer->clearCache();

        $publisher = new StaticErrorPagePublisher();
        $result = $publisher->publish(
            StorefrontNotFoundStaticPage::KIND,
            function (array $target): array {
                return $this->publishOne(
                    (string)$target['website_code'],
                    (string)$target['lang'],
                    (int)$target['website_id'],
                    (string)($target['website_url'] ?? ''),
                );
            },
            function (string $phase, array $meta): void {
                if (PHP_SAPI !== 'cli') {
                    return;
                }
                if ($phase === 'start') {
                    $this->printing->note(__(
                        '开始发布前台 404 静态页：%{total} 个 website×locale（Fiber 协作并发 %{c}，非多核；已隔离升级词袋）',
                        ['total' => (int)($meta['total'] ?? 0), 'c' => (int)($meta['concurrency'] ?? 4)]
                    ));
                    if (\defined('STDOUT') && \is_resource(STDOUT)) {
                        \fflush(STDOUT);
                    }
                    return;
                }
                if ($phase === 'task') {
                    $label = (string)($meta['website_code'] ?? '') . '/' . (string)($meta['lang'] ?? '');
                    if (!empty($meta['ok'])) {
                        $this->printing->success(__(
                            '前台 404 静态页 [%{done}/%{total}]：%{label}',
                            [
                                'done' => (int)($meta['done'] ?? 0),
                                'total' => (int)($meta['total'] ?? 0),
                                'label' => $label,
                            ]
                        ));
                    } else {
                        $this->printing->warning(__(
                            '前台 404 静态页失败 [%{done}/%{total}]：%{label} — %{err}',
                            [
                                'done' => (int)($meta['done'] ?? 0),
                                'total' => (int)($meta['total'] ?? 0),
                                'label' => $label,
                                'err' => (string)($meta['error'] ?? ''),
                            ]
                        ));
                    }
                    if (\defined('STDOUT') && \is_resource(STDOUT)) {
                        \fflush(STDOUT);
                    }
                    return;
                }
                if ($phase === 'done') {
                    $this->printing->success(__(
                        '前台 404 静态页发布完成：成功 %{written}，失败 %{failed}，host_map %{keys} 键',
                        [
                            'written' => (int)($meta['written'] ?? 0),
                            'failed' => (int)($meta['failed'] ?? 0),
                            'keys' => (int)($meta['host_map_keys'] ?? 0),
                        ]
                    ));
                }
            },
            ['extensions' => ['html']],
        );

        $labels = [];
        foreach ($result['written'] as $row) {
            $labels[] = $row['website_code'] . '/' . $row['lang'];
        }

        return $labels;
    }

    /**
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function publishOne(
        string $websiteCode,
        string $lang,
        int $websiteId = 0,
        string $websiteUrl = '',
    ): array {
        $websiteCode = StaticErrorPageMap::sanitizeWebsiteCode($websiteCode);
        if ($websiteCode === '') {
            return ['ok' => false, 'error' => 'invalid website code'];
        }
        $lang = \trim($lang);
        if ($lang === '' || \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $lang) !== 1) {
            return ['ok' => false, 'error' => 'invalid locale'];
        }

        $this->publishSite = [
            'website_id' => $websiteId,
            'website_code' => $websiteCode,
        ];

        try {
            $path = StorefrontNotFoundStaticPage::staticFilePath($lang, $websiteCode);
            $inputHash = $this->computePublishInputHash($websiteCode, $lang, $websiteId, $websiteUrl);
            $fpKey = 'static404:' . $websiteCode . ':' . $lang;
            $fpService = new SetupSourceFingerprint();
            if ($inputHash !== ''
                && $fpService->matches($fpKey, $inputHash)
                && \is_file($path)
            ) {
                FiberTaskRunner::yield();

                return ['ok' => true, 'path' => $path, 'skipped' => true];
            }

            $html = $this->buildHtmlForWebsite($lang, $websiteId, $websiteCode, $websiteUrl);
            FiberTaskRunner::yield();
            if ($html === '') {
                return ['ok' => false, 'error' => 'empty html'];
            }
            $contentHash = \hash('sha256', $path . '|' . $lang . '|' . $websiteCode . '|' . $html);
            if (!$this->saveStaticFileIfChanged($path, $html, $contentHash)) {
                // hash 未变：仍记为成功，避免 prune 误删
                if ($websiteCode === StaticErrorPageMap::DEFAULT_WEBSITE_CODE) {
                    $this->saveStaticFileIfChanged(
                        StorefrontNotFoundStaticPage::staticFilePath($lang, ''),
                        $html,
                        \hash('sha256', StorefrontNotFoundStaticPage::staticFilePath($lang, '') . '|' . $lang . '|' . $websiteCode . '|' . $html)
                    );
                }
                if ($inputHash !== '') {
                    $fpService->set($fpKey, $inputHash);
                }
                FiberTaskRunner::yield();

                return ['ok' => true, 'path' => $path, 'skipped' => true];
            }
            if ($websiteCode === StaticErrorPageMap::DEFAULT_WEBSITE_CODE) {
                $this->saveStaticFileIfChanged(
                    StorefrontNotFoundStaticPage::staticFilePath($lang, ''),
                    $html,
                    \hash('sha256', StorefrontNotFoundStaticPage::staticFilePath($lang, '') . '|' . $lang . '|' . $websiteCode . '|' . $html)
                );
            }
            if ($inputHash !== '') {
                $fpService->set($fpKey, $inputHash);
            }
            FiberTaskRunner::yield();

            return ['ok' => true, 'path' => $path];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $this->publishSite = null;
            try {
                State::setRequestLanguageOverride('');
            } catch (\Throwable) {
            }
        }
    }

    /**
     * publish 输入指纹：站×语×主题×翻译版本（避免假阴性）。
     */
    private function computePublishInputHash(
        string $websiteCode,
        string $lang,
        int $websiteId,
        string $websiteUrl,
    ): string {
        $themeToken = '0';
        try {
            $identity = ScopeIdentity::website($websiteId, $websiteCode);
            $theme = $this->themeContext->resolveThemeForScope(PreviewContextService::AREA_FRONTEND, $identity);
            if (!$theme || !$theme->getId()) {
                $theme = $this->themeContext->resolveTheme(PreviewContextService::AREA_FRONTEND, null, false);
            }
            if ($theme && $theme->getId()) {
                $themeToken = (string)(int)$theme->getId()
                    . ':'
                    . (string)($theme->getData('path') ?? $theme->getData('name') ?? '');
            }
        } catch (\Throwable) {
        }

        $i18nToken = '0';
        try {
            $i18nToken = (string)(DictionaryCacheNamespace::fingerprint([$lang]) ?? '0');
        } catch (\Throwable) {
            $i18nToken = '0';
        }

        return \hash(
            'sha256',
            '404|' . $websiteCode . '|' . $lang . '|' . $websiteId . '|' . $themeToken . '|' . $i18nToken . '|' . $websiteUrl
        );
    }

    public function buildHtml(string $lang): string
    {
        $websiteId = 0;
        $websiteCode = StaticErrorPageMap::DEFAULT_WEBSITE_CODE;
        $websiteUrl = 'http://127.0.0.1/';
        try {
            $defaults = ObjectManager::getInstance(DefaultWebsiteService::class)->ensureDefaultWebsite(false);
            $websiteId = (int)($defaults['website_id'] ?? Website::ID_DEFAULT);
            $websiteCode = StaticErrorPageMap::sanitizeWebsiteCode((string)($defaults['website_code'] ?? Website::CODE_DEFAULT))
                ?: StaticErrorPageMap::DEFAULT_WEBSITE_CODE;
        } catch (\Throwable) {
        }

        return $this->buildHtmlForWebsite($lang, $websiteId, $websiteCode, $websiteUrl);
    }

    private function buildHtmlForWebsite(string $lang, int $websiteId, string $websiteCode, string $websiteUrl): string
    {
        // setup:upgrade 等长 CLI 会把全流程 __() 堆进 usedWords；若不隔离，
        // Frontend head 会把整袋词典塞进 runtime JSON，首语言可拖到分钟级甚至 OOM。
        $this->isolatePhraseBagForStaticRender();

        State::setRequestLanguageOverride($lang);
        try {
            RequestContext::resetWelineVars();
            RequestContext::installScopeIdentity(ScopeIdentity::website($websiteId, $websiteCode));
        } catch (\Throwable) {
        }

        // Prefer Context language override — do not mutate process $_SERVER REQUEST_URI.
        try {
            return $this->renderThemedNotFoundPage($websiteId, $websiteCode, $websiteUrl);
        } catch (\Throwable) {
            return $this->fallbackShellHtml();
        } finally {
            State::setRequestLanguageOverride('');
        }
    }

    private function isolatePhraseBagForStaticRender(): void
    {
        \Weline\Framework\Phrase\Parser::clearUsedWords();
        if (\class_exists(\Weline\I18n\Helper\JsWordsRegistry::class)) {
            \Weline\I18n\Helper\JsWordsRegistry::reset();
        }
    }

    private function renderThemedNotFoundPage(int $websiteId, string $websiteCode, string $websiteUrl): string
    {
        $identity = ScopeIdentity::website($websiteId, $websiteCode);
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

        $theme = $this->themeContext->resolveThemeForScope(PreviewContextService::AREA_FRONTEND, $identity);
        if (!$theme || !$theme->getId()) {
            $theme = $this->themeContext->resolveTheme(PreviewContextService::AREA_FRONTEND, null, false);
        }
        if (!$theme || !$theme->getId()) {
            return $this->fallbackShellHtml();
        }

        $themeId = (int)$theme->getId();
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

        $html = (string)$this->template->fetchModuleThemeHtml(
            'Weline_Theme::theme/frontend/layouts/' . self::LAYOUT_TYPE . '/' . self::LAYOUT_OPTION . '.phtml'
        );
        if ($html === '') {
            return $this->fallbackShellHtml();
        }

        // 不在每个语言清空槽位缓存：布局图与部件元数据可复用；文案/推荐仍随语言覆盖重算。
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

    private function saveStaticFile(string $path, string $contents): void
    {
        $this->saveStaticFileIfChanged($path, $contents, \hash('sha256', $path . '|' . $contents));
    }

    /**
     * @return bool true 已写盘；false 旁路 .hash 未变跳过
     */
    private function saveStaticFileIfChanged(string $path, string $contents, string $contentHash): bool
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $hashPath = $path . '.hash';
        if (\is_file($path) && \is_file($hashPath)) {
            $prev = @\file_get_contents($hashPath);
            if (\is_string($prev) && \hash_equals(\trim($prev), $contentHash)) {
                return false;
            }
        }

        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $contents) === false) {
            return false;
        }

        @\rename($tmp, $path);
        @\file_put_contents($hashPath, $contentHash);

        return true;
    }
}
