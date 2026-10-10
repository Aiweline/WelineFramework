<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Framework\View\Template;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\HeaderCommerceData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\SolidifiedControllerTemplateResolver;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\ThemeApplicationContextProducer;

/**
 * Seeds theme.storefront_chrome (+ head home/assets) per enabled website locale.
 *
 * Header chrome is guest-neutral (lang-only); search facade hydrates on serve.
 * Soft-clear / empty L2 otherwise makes first hit of a new locale pay chrome builder.
 * CLI/Clear must pin the acceptance public Host so environmentHash matches HTTP.
 * MUST install the same homepage ThemeLayoutSourceSnapshot the storefront Index
 * path installs (SolidifiedControllerTemplateResolver) so chrome partial bytes are
 * captured for path-local digests (xscope A / chrome-partial-v22). Full layout
 * snapshot fingerprint is no longer folded into header/footer HotCache keys.
 * MUST install Website ThemeApplicationContext + homepage layout/colorMode on the
 * Template theme bag — otherwise theme_id falls back to module default (1) while
 * HTTP uses the published site theme (e.g. 3) and chrome L2 misses after soft-clear.
 * Path A (T1 pass): layout dims neutralized in Partials — homepage seeds share keys
 * with search/plp/blog/pdp. Path B (T1 fail) would expand layout families here; not
 * active while A holds. Fail-open; no empty burn; never strip chrome shells.
 *
 * CLI default memory_limit (128M) OOMs while hydrating ~40 locale dictionaries;
 * raise to 512M for the warm window. Do not clear Phrase bags between locales.
 */
final class StorefrontChromeLocaleCacheWarmer implements CacheWarmerInterface
{
    /**
     * Soft wall for cache:clear. Cold Clear + head×locales is slower than a
     * standalone CLI warm; 90s often cut before gate locales (fr/it/es ~pos 11–23).
     */
    private const BUDGET_MS = 180000;

    /** Floor for multi-locale Phrase hydrate during cache:clear. */
    private const MEMORY_FLOOR = '512M';

    /**
     * Quiet-cold acceptance locales (G-chrome-ex). Must warm before alphabetical
     * tail so budget cutovers still cover the gate sample set.
     *
     * @var list<string>
     */
    private const GATE_LOCALES = ['fr_FR', 'it_IT', 'es_ES'];

    public function getName(): string
    {
        return 'theme.storefront_chrome_locales';
    }

    public function getTargetPool(): string
    {
        return StorefrontThemeCacheCoordinator::STOREFRONT_CHROME_POOL;
    }

    public function getPriority(): int
    {
        return 240;
    }

    public function canWarm(): bool
    {
        return \class_exists(Partials::class)
            && \class_exists(WebsiteLanguage::class);
    }

    public function warm(): array
    {
        $prevMem = (string)\ini_get('memory_limit');
        $this->ensureMemoryFloor();

        $hadRequest = RequestContext::isInitialized();
        if (!$hadRequest) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                $this->restoreMemoryLimit($prevMem);

                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'request_init_fail:' . $e->getMessage(),
                ];
            }
        }

        $warmedRender = 0;
        $warmedL2Hit = 0;
        $headL2Hit = 0;
        $headAssetsL2Hit = 0;
        $skipped = 0;
        $remaining = 0;
        $notes = [];
        $started = \hrtime(true);

        try {
            $this->installDefaultChannelIdentity();
            RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
            $hostNote = $this->installAcceptancePublicHost();
            $notes[] = $hostNote;
            $notes[] = $this->ensureFrontendThemeAssignedToTemplate();

            $locales = $this->resolveEnabledLocales();
            $notes[] = 'locales=' . \count($locales);
            if ($locales === []) {
                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'no_enabled_locales;' . \implode(';', $notes),
                ];
            }

            /** @var Partials $partials */
            $partials = ObjectManager::getInstance(Partials::class);
            /** @var StorefrontCacheKeyContextResolver $resolver */
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);

            foreach ($locales as $idx => $locale) {
                $elapsedMs = (\hrtime(true) - $started) / 1e6;
                if ($elapsedMs >= self::BUDGET_MS) {
                    $remaining = \count($locales) - $idx;
                    $notes[] = 'budget_ms=' . self::BUDGET_MS;
                    $notes[] = 'remaining=' . $remaining;
                    $notes[] = 'warm_partial=1';
                    break;
                }

                try {
                    // Peer warmers (product) call resetWelineVars(); re-pin every
                    // locale so Clear chain matches standalone CLI Host identity.
                    $this->installAcceptancePublicHost();
                    RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
                    RequestContext::setWelineUserLang($locale);
                    RequestContext::setWelineUserCurrency('USD');
                    $this->installHomeRequestUri($locale);
                    RequestContext::remove('framework.cache.storefront_key_context.v2');
                    Partials::clearOutputCache();
                    $this->ensureFrontendThemeAssignedToTemplate();
                    // Match storefront Index: chrome keys use snapshot.fingerprint.
                    $snapNote = $this->installHomepageLayoutSourceSnapshot();
                    if ($idx === 0) {
                        $notes[] = $snapNote;
                    }

                    if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                        $ctx = $resolver->freezeCurrent();
                        if (!$ctx->cacheable) {
                            ++$skipped;
                            $notes[] = 'freeze_fail:' . $locale . ':' . ($ctx->failureCode ?: 'unknown');
                            continue;
                        }
                    }

                    $localeRender = 0;
                    $headerL2 = false;
                    $footerL2 = false;
                    $headL2 = false;

                    $header = $partials->renderPartials('frontend', 'header', [], 'default');
                    $footer = $partials->renderPartials('frontend', 'footer', [], 'default');
                    if ((\is_string($header) && \trim($header) !== '')
                        || (\is_string($footer) && \trim($footer) !== '')
                    ) {
                        ++$localeRender;
                    } else {
                        ++$skipped;
                        $notes[] = 'empty_chrome:' . $locale;
                    }

                    if (\is_string($header) && \trim($header) !== '') {
                        $v = $partials->verifyStorefrontSharedDurableL2('frontend', 'header');
                        $headerL2 = !empty($v['hit']);
                    }
                    if (\is_string($footer) && \trim($footer) !== '') {
                        $v = $partials->verifyStorefrontSharedDurableL2('frontend', 'footer');
                        $footerL2 = !empty($v['hit']);
                    }

                    $head = $partials->renderPartials('frontend', 'head', [], 'default');
                    if (\is_string($head) && \trim($head) !== '') {
                        ++$localeRender;
                        $hv = $partials->verifyStorefrontSharedDurableL2('frontend', 'head');
                        $headL2 = !empty($hv['hit']);
                        if ($headL2) {
                            ++$headL2Hit;
                        }
                        $av = $partials->verifyStorefrontHeadAssetsDurableL2();
                        if (!empty($av['hit'])) {
                            ++$headAssetsL2Hit;
                        }
                    } else {
                        $notes[] = 'empty_head:' . $locale;
                    }

                    // chrome_n=2 on dig = header+footer bags. gate_ok only when BOTH L2 HIT
                    // under request-shaped identity (no trailing slash website_url).
                    $chromeL2 = $headerL2 && $footerL2;
                    $warmedRender += $localeRender;
                    if ($chromeL2) {
                        ++$warmedL2Hit;
                        if (\in_array($locale, self::GATE_LOCALES, true)) {
                            $notes[] = 'gate_ok:' . $locale;
                        }
                    } elseif ($localeRender > 0) {
                        $notes[] = 'l2_miss:' . $locale
                            . ':header=' . ($headerL2 ? '1' : '0')
                            . ':footer=' . ($footerL2 ? '1' : '0')
                            . ':head=' . ($headL2 ? '1' : '0');
                        if (\in_array($locale, self::GATE_LOCALES, true)) {
                            $notes[] = 'gate_miss:' . $locale;
                        }
                    }

                    // Header commerce bags used while building chrome.
                    try {
                        HeaderCommerceData::resolveCategoryNavItems();
                        HeaderCommerceData::resolveSearchTypes();
                        HeaderCommerceData::resolveHotWords();
                    } catch (\Throwable) {
                        // Fail-open: chrome HTML bag is the tip tax.
                    }
                } catch (\Throwable $e) {
                    ++$skipped;
                    $notes[] = 'error:' . $locale . ':' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                Partials::clearOutputCache();
            } catch (\Throwable) {
            }
            try {
                Parser::clearUsedWords();
            } catch (\Throwable) {
            }
            try {
                RequestContext::resetWelineVars();
            } catch (\Throwable) {
            }
            $this->restoreMemoryLimit($prevMem);
        }

        $notes[] = 'warmed_render=' . $warmedRender;
        $notes[] = 'warmed_l2_hit=' . $warmedL2Hit;
        $notes[] = 'head_l2_hit=' . $headL2Hit;
        $notes[] = 'head_assets_l2_hit=' . $headAssetsL2Hit;
        if ($remaining > 0) {
            ++$skipped;
            if (!\in_array('warm_partial=1', $notes, true)) {
                $notes[] = 'warm_partial=1';
            }
        }

        return [
            // Honest durable count for Clear registry; render-only stays in message.
            'warmed' => \max(0, $warmedL2Hit),
            'skipped' => $skipped,
            'message' => \implode(';', $notes) ?: 'ok',
        ];
    }

    /**
     * Pin CLI/Clear identity to the storefront public acceptance Host so
     * KeyBuilder environmentHash (host/website_url/base_url) matches HTTP.
     * Reads WLS_PUBLIC_ORIGIN / instance public_origin / public_host — never a
     * sibling-site slug whitelist.
     */
    private function installAcceptancePublicHost(): string
    {
        $resolved = $this->resolveAcceptancePublicOrigin();
        if ($resolved === null) {
            return 'host=unresolved';
        }

        $scheme = $resolved['scheme'];
        $host = $resolved['host'];
        // Match storefront request / Cookie WELINE_WEBSITE_URL shape: NO trailing slash.
        // Trailing "/" forks KeyBuilder environmentHash website_url → logical key miss
        // (warmer L2 HIT under …com/ while quiet cold reads …com).
        $websiteUrl = $scheme . '://' . $host;

        RequestContext::setWelineWebsiteUrl($websiteUrl);
        WelineEnv::setServer('HTTP_HOST', $host, 'StorefrontChromeLocaleCacheWarmer');
        WelineEnv::setServer('SERVER_NAME', \explode(':', $host, 2)[0], 'StorefrontChromeLocaleCacheWarmer');
        WelineEnv::setServer('REQUEST_SCHEME', $scheme, 'StorefrontChromeLocaleCacheWarmer');
        WelineEnv::setServer('HTTPS', $scheme === 'https' ? 'on' : 'off', 'StorefrontChromeLocaleCacheWarmer');
        WelineEnv::setServer('WELINE_WEBSITE_URL', $websiteUrl, 'StorefrontChromeLocaleCacheWarmer');
        if (RequestContext::isInitialized()) {
            try {
                $ctx = Context::getCurrent();
                if ($ctx !== null) {
                    $ctx->set('input.host', $host);
                    $ctx->set('input.scheme', $scheme);
                    $ctx->set('route.website_url', $websiteUrl);
                }
            } catch (\Throwable) {
            }
        }
        if (!Runtime::isPersistent()) {
            $_SERVER['HTTP_HOST'] = $host;
            $_SERVER['SERVER_NAME'] = \explode(':', $host, 2)[0];
            $_SERVER['REQUEST_SCHEME'] = $scheme;
            $_SERVER['HTTPS'] = $scheme === 'https' ? 'on' : 'off';
            $_SERVER['WELINE_WEBSITE_URL'] = $websiteUrl;
        }

        return 'host=' . $host . ';website_url=' . $websiteUrl;
    }

    /**
     * @return array{scheme:string,host:string}|null
     */
    private function resolveAcceptancePublicOrigin(): ?array
    {
        $candidates = [];

        foreach ([
            (string)(\getenv('WLS_PUBLIC_ORIGIN') ?: ''),
            (string)($_SERVER['WLS_PUBLIC_ORIGIN'] ?? ''),
            (string)($_ENV['WLS_PUBLIC_ORIGIN'] ?? ''),
        ] as $raw) {
            $raw = \trim($raw);
            if ($raw !== '') {
                $candidates[] = $raw;
            }
        }

        $instance = $this->readCurrentInstanceMetadata();
        $listenPort = (int)($instance['main_port'] ?? $instance['port'] ?? 0);
        $publicOrigin = \trim((string)($instance['public_origin'] ?? ''));
        $publicHost = \trim((string)($instance['public_host'] ?? ''));
        // Prefer bare public_host BEFORE public_origin: pure-WLS / --edge=wls may
        // rewrite public_origin to host:9555 while quiet-cold nginx dig uses bare
        // HTTP_HOST → environmentHash fork + gate_ok under the wrong key.
        if ($publicHost !== '' && !$this->isLoopbackHost($publicHost)) {
            $candidates[] = 'https://' . \explode(':', $publicHost, 2)[0];
        }
        if ($publicOrigin !== '') {
            $candidates[] = $publicOrigin;
        }

        foreach ($candidates as $candidate) {
            $parsed = $this->parsePublicOriginCandidate($candidate, $listenPort);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @return array{scheme:string,host:string}|null
     */
    private function parsePublicOriginCandidate(string $raw, int $listenPort = 0): ?array
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return null;
        }
        if (!\str_contains($raw, '://')) {
            $raw = 'https://' . $raw;
        }
        $parts = \parse_url($raw);
        if (!\is_array($parts)) {
            return null;
        }
        $host = \trim((string)($parts['host'] ?? ''));
        if ($host === '' || $this->isLoopbackHost($host)) {
            return null;
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        $port = (int)($parts['port'] ?? 0);
        // Acceptance quiet dig Host is the TLS edge bare name (Cookie
        // WELINE_WEBSITE_URL / nginx). Strip WLS listen / private 9xxx ports so
        // warmer L2 keys match request Partials remember (not host:9555).
        if ($port > 0 && $this->isPrivateListenPort($port, $listenPort)) {
            $port = 0;
        }
        $authority = $host;
        if ($port > 0 && !(
            ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80)
        )) {
            $authority .= ':' . $port;
        }

        return ['scheme' => $scheme, 'host' => $authority];
    }

    /**
     * WLS worker / pure-edge listen ports must not enter chrome environmentHash
     * when the acceptance surface is managed-nginx bare Host.
     */
    private function isPrivateListenPort(int $port, int $listenPort = 0): bool
    {
        if ($port <= 0) {
            return false;
        }
        if ($listenPort > 0 && $port === $listenPort) {
            return true;
        }
        // Framework URL helpers treat 9000–9999 as private WLS listen range.
        return $port >= 9000 && $port <= 9999;
    }

    private function isLoopbackHost(string $host): bool
    {
        $h = \strtolower(\trim(\explode(':', $host, 2)[0]));

        return $h === ''
            || $h === '0.0.0.0'
            || $h === '::'
            || $h === '127.0.0.1'
            || $h === 'localhost';
    }

    /**
     * @return array<string, mixed>
     */
    private function readCurrentInstanceMetadata(): array
    {
        if (!\defined('BP')) {
            return [];
        }
        $instance = (string)(
            $_SERVER['WLS_INSTANCE_NAME']
            ?? $_SERVER['WLS_INSTANCE']
            ?? $_ENV['WLS_INSTANCE_NAME']
            ?? $_ENV['WLS_INSTANCE']
            ?? \getenv('WLS_INSTANCE')
            ?: 'default'
        );
        $instance = \trim($instance) !== '' ? \trim($instance) : 'default';
        $path = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
            . 'instances' . DIRECTORY_SEPARATOR . $instance . '.json';
        if (!\is_file($path)) {
            $path = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
                . 'instances' . DIRECTORY_SEPARATOR . 'default.json';
        }
        if (!\is_file($path)) {
            return [];
        }
        try {
            $decoded = \json_decode((string)\file_get_contents($path), true);

            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function installHomeRequestUri(string $locale): void
    {
        $locale = \trim($locale);
        $path = $locale !== '' ? '/' . $locale . '/' : '/';
        $uri = $path . '?currency=USD';
        WelineEnv::setServer('REQUEST_URI', $uri, 'StorefrontChromeLocaleCacheWarmer');
        WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI', $uri, 'StorefrontChromeLocaleCacheWarmer');
        try {
            $host = (string)WelineEnv::server('HTTP_HOST', '');
            $scheme = (string)WelineEnv::server('REQUEST_SCHEME', 'https');
            if ($host !== '') {
                WelineEnv::setServer(
                    'WELINE_FULL_REQUEST_URI',
                    $scheme . '://' . $host . $uri,
                    'StorefrontChromeLocaleCacheWarmer',
                );
            }
        } catch (\Throwable) {
        }
        if (!Runtime::isPersistent()) {
            $_SERVER['REQUEST_URI'] = $uri;
            $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $uri;
        }
        try {
            $ctx = Context::getCurrent();
            if ($ctx !== null) {
                $ctx->set('input.uri', $path);
                $ctx->set('input.origin_request_uri', $uri);
            }
        } catch (\Throwable) {
        }
    }

    private function ensureMemoryFloor(): void
    {
        $current = \trim((string)\ini_get('memory_limit'));
        if ($current === '' || $current === '-1') {
            return;
        }
        if (!\preg_match('/^(\d+)([KMG]?)$/i', $current, $m)) {
            @\ini_set('memory_limit', self::MEMORY_FLOOR);

            return;
        }
        $number = (float)$m[1];
        $unit = \strtoupper($m[2] !== '' ? $m[2] : 'B');
        $bytes = match ($unit) {
            'G' => (int)($number * 1024 * 1024 * 1024),
            'M' => (int)($number * 1024 * 1024),
            'K' => (int)($number * 1024),
            default => (int)$number,
        };
        if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
            @\ini_set('memory_limit', self::MEMORY_FLOOR);
        }
    }

    private function restoreMemoryLimit(string $prevMem): void
    {
        if ($prevMem !== '') {
            @\ini_set('memory_limit', $prevMem);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveEnabledLocales(): array
    {
        $codes = [];
        try {
            /** @var WebsiteLanguage $wl */
            $wl = ObjectManager::getInstance(WebsiteLanguage::class);
            $raw = $wl->getWebsiteLanguageCodes(Website::ID_DEFAULT);
            if (\is_array($raw)) {
                foreach ($raw as $code) {
                    $code = \trim((string)$code);
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            }
        } catch (\Throwable) {
            $codes = [];
        }

        $codes = \array_values(\array_unique($codes));
        if ($codes === []) {
            $codes = ['zh_Hans_CN', 'en_US'];
        }

        // Baseline → gate locales (quiet-cold G-*) → alphabetical remainder.
        // Clear budget cutovers must still cover fr_FR/it_IT/es_ES.
        $priority = \array_merge(['zh_Hans_CN', 'en_US'], self::GATE_LOCALES);
        $head = [];
        foreach ($priority as $p) {
            if (\in_array($p, $codes, true) && !\in_array($p, $head, true)) {
                $head[] = $p;
            }
        }
        $tail = \array_values(\array_filter(
            $codes,
            static fn(string $c): bool => !\in_array($c, $head, true),
        ));
        \sort($tail, \SORT_STRING);

        return \array_merge($head, $tail);
    }

    private function installDefaultChannelIdentity(): void
    {
        $storeMode = Store::MODE_NORMAL;
        $identity = ScopeIdentity::channel(
            Website::ID_DEFAULT,
            Website::CODE_DEFAULT,
            Store::CODE_DEFAULT,
            SalesChannel::CODE_DEFAULT,
            $storeMode,
        );
        RequestContext::setWelineWebsiteId(Website::ID_DEFAULT);
        RequestContext::setWelineWebsiteCode(Website::CODE_DEFAULT);
        RequestContext::setWelineStoreId(0);
        RequestContext::setWelineStoreCode(Store::CODE_DEFAULT);
        RequestContext::setWelineStoreMode($storeMode);
        RequestContext::setWelineChannelId(0);
        RequestContext::setWelineChannelCode(SalesChannel::CODE_DEFAULT);
        RequestContext::installScopeIdentity($identity);
    }

    private function ensureFrontendThemeAssignedToTemplate(): string
    {
        try {
            $this->installDefaultWebsiteThemeApplication();
            $template = \Weline\Framework\View\Template::getInstance();
            $existing = $template->getData('theme');
            /** @var ThemeContextService $context */
            $context = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $context->resolveTheme('frontend', null, false);
            if (!$theme instanceof WelineTheme) {
                $theme = $context->resolveRegisteredDefaultTheme('frontend');
            }
            if (!$theme instanceof WelineTheme || (int)$theme->getId() <= 0) {
                return 'theme=none';
            }
            $base = \is_array($existing) ? $existing : [];
            $base['theme'] = $theme;
            $base['area'] = 'frontend';
            // Match Index homepage chrome key dims (HTTP dump: layout+colorMode).
            $base['layoutType'] = 'homepage';
            $base['layoutOption'] = 'default';
            if (!isset($base['colorMode']) || (string)$base['colorMode'] === '') {
                $base['colorMode'] = 'default';
            }
            $template->assign('theme', $base);
            $app = ThemeApplicationContext::current('frontend', 'runtime');

            return 'theme_id=' . (int)$theme->getId()
                . ';layout=homepage;color=' . (string)$base['colorMode']
                . ';app_theme=' . ($app instanceof ThemeApplicationContext ? (string)$app->themeId : '0');
        } catch (\Throwable $e) {
            return 'theme=error:' . $e->getMessage();
        }
    }

    /**
     * Storefront resolveTheme needs ThemeApplicationContext (published site theme).
     * Without it CLI falls back to module default theme_id=1 and chrome L2 forks.
     */
    private function installDefaultWebsiteThemeApplication(): void
    {
        try {
            $identity = RequestContext::scopeIdentity();
            if (!$identity instanceof ScopeIdentity) {
                $identity = ScopeIdentity::channel(
                    Website::ID_DEFAULT,
                    Website::CODE_DEFAULT,
                    Store::CODE_DEFAULT,
                    SalesChannel::CODE_DEFAULT,
                    Store::MODE_NORMAL,
                );
            }
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website = (clone $website)->load(Website::ID_DEFAULT, null, true);
            $locale = \trim((string)$website->getDefaultLanguage());
            if ($locale === '') {
                $locale = 'zh_Hans_CN';
            }
            /** @var ThemeApplicationContextProducer $producer */
            $producer = ObjectManager::getInstance(ThemeApplicationContextProducer::class);
            $application = $producer->build(
                identity: $identity,
                storeMode: (string)($identity->storeMode ?? Store::MODE_NORMAL),
                defaultLocale: $locale,
                displayName: (string)$website->getName(),
                purpose: 'runtime',
            );
            // Peer warmers / prior locales may have fixed a different application.
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.runtime');
            $application->install();
        } catch (\Throwable) {
        }
    }

    /**
     * Install the published homepage source snapshot so chrome HotCache keys use
     * the same snapshot.fingerprint as storefront Index (ControllerFetchFileBefore
     * → SolidifiedControllerTemplateResolver). Clearing the snapshot made warmer
     * seed mtime|size keys that quiet-cold HTTP never reads.
     */
    private function installHomepageLayoutSourceSnapshot(): string
    {
        try {
            $themeId = 0;
            $template = Template::getInstance();
            $themeBag = $template->getData('theme');
            if (\is_array($themeBag)) {
                $theme = $themeBag['theme'] ?? null;
                if ($theme instanceof WelineTheme) {
                    $themeId = (int)$theme->getId();
                }
            }
            if ($themeId <= 0) {
                $app = ThemeApplicationContext::current('frontend', 'runtime');
                if ($app instanceof ThemeApplicationContext) {
                    $themeId = (int)$app->themeId;
                }
            }
            if ($themeId <= 0) {
                return 'snapshot=no_theme';
            }

            // Drop any peer-warmer / prior-locale snapshot before capture.
            RequestContext::remove(ThemeLayoutSourceSnapshot::REQUEST_KEY);

            /** @var SolidifiedControllerTemplateResolver $resolver */
            $resolver = ObjectManager::getInstance(SolidifiedControllerTemplateResolver::class);
            $resolver->resolveExecutableLayoutPath(
                $themeId,
                'homepage',
                'default',
                'frontend',
                'global',
                null,
            );
            $snapshot = ThemeLayoutSourceSnapshot::current();
            if (!$snapshot instanceof ThemeLayoutSourceSnapshot) {
                return 'snapshot=missing:theme_id=' . $themeId;
            }

            return 'snapshot_fp=' . \substr($snapshot->fingerprint(), 0, 16)
                . ';theme_id=' . $themeId;
        } catch (\Throwable $e) {
            return 'snapshot=error:' . $e->getMessage();
        }
    }

}
