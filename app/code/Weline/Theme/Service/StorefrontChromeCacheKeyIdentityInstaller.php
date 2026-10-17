<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Framework\View\Template;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\SolidifiedControllerTemplateResolver;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ThemeApplicationContextProducer;

/**
 * Layer A/B chrome HotCache key identity — shared by locale warmer (seed) and
 * Process L1 hydrate (Shared→Process peek).
 *
 * Without this stack, hydrate peeks mtime/theme_id=1/host forks while warmer
 * wrote under acceptance public Host + ThemeApplication + homepage snapshot.
 */
final class StorefrontChromeCacheKeyIdentityInstaller
{
    /**
     * Pin RequestContext so theme.chrome.* / theme.head.* policy keys match
     * StorefrontChromeLocaleCacheWarmer Shared inventory.
     *
     * @return list<string> diagnostic notes (host / theme / snapshot / cacheable)
     */
    public function installForSharedChromePeek(?string $locale = null): array
    {
        $notes = [];
        if (!RequestContext::isInitialized()) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                return ['request_init_fail:' . $e->getMessage()];
            }
        }

        $this->installDefaultChannelIdentity();
        $notes[] = $this->installAcceptancePublicHost();

        $locale = \trim((string)($locale ?? ''));
        if ($locale === '') {
            $locale = $this->resolveDefaultLocale();
        }
        RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
        RequestContext::setWelineUserLang($locale);
        // Warmer seeds with USD + ?currency=USD — keep hydrate on the same dim.
        RequestContext::setWelineUserCurrency('USD');
        $this->installHomeRequestUri($locale);

        $notes[] = $this->ensureFrontendThemeAssignedToTemplate();
        $notes[] = $this->installHomepageLayoutSourceSnapshot();

        RequestContext::remove('framework.cache.storefront_key_context.v2');
        try {
            $ctx = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class)
                ->freezeCurrent();
            $notes[] = 'cacheable=' . (int)$ctx->cacheable
                . ($ctx->failureCode !== '' ? ';fail=' . $ctx->failureCode : '');
        } catch (\Throwable $e) {
            $notes[] = 'freeze_fail:' . $e->getMessage();
        }

        return $notes;
    }

    private function resolveDefaultLocale(): string
    {
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website = (clone $website)->load(Website::ID_DEFAULT, null, true);
            $locale = \trim((string)$website->getDefaultLanguage());
            if ($locale !== '') {
                return $locale;
            }
        } catch (\Throwable) {
        }

        return 'zh_Hans_CN';
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

    private function installAcceptancePublicHost(): string
    {
        $resolved = $this->resolveAcceptancePublicOrigin();
        if ($resolved === null) {
            return 'host=unresolved';
        }

        $scheme = $resolved['scheme'];
        $host = $resolved['host'];
        // NO trailing slash — trailing "/" forks KeyBuilder environmentHash.
        $websiteUrl = $scheme . '://' . $host;

        RequestContext::setWelineWebsiteUrl($websiteUrl);
        WelineEnv::setServer('HTTP_HOST', $host, 'StorefrontChromeCacheKeyIdentityInstaller');
        WelineEnv::setServer('SERVER_NAME', \explode(':', $host, 2)[0], 'StorefrontChromeCacheKeyIdentityInstaller');
        WelineEnv::setServer('REQUEST_SCHEME', $scheme, 'StorefrontChromeCacheKeyIdentityInstaller');
        WelineEnv::setServer('HTTPS', $scheme === 'https' ? 'on' : 'off', 'StorefrontChromeCacheKeyIdentityInstaller');
        WelineEnv::setServer('WELINE_WEBSITE_URL', $websiteUrl, 'StorefrontChromeCacheKeyIdentityInstaller');
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

    private function isPrivateListenPort(int $port, int $listenPort = 0): bool
    {
        if ($port <= 0) {
            return false;
        }
        if ($listenPort > 0 && $port === $listenPort) {
            return true;
        }

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
        WelineEnv::setServer('REQUEST_URI', $uri, 'StorefrontChromeCacheKeyIdentityInstaller');
        WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI', $uri, 'StorefrontChromeCacheKeyIdentityInstaller');
        try {
            $host = (string)WelineEnv::server('HTTP_HOST', '');
            $scheme = (string)WelineEnv::server('REQUEST_SCHEME', 'https');
            if ($host !== '') {
                WelineEnv::setServer(
                    'WELINE_FULL_REQUEST_URI',
                    $scheme . '://' . $host . $uri,
                    'StorefrontChromeCacheKeyIdentityInstaller',
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

    private function ensureFrontendThemeAssignedToTemplate(): string
    {
        try {
            $this->installDefaultWebsiteThemeApplication();
            $template = Template::getInstance();
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
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.runtime');
            $application->install();
        } catch (\Throwable) {
        }
    }

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
            $snapshot->install($template);

            return 'snapshot_fp=' . \substr($snapshot->fingerprint(), 0, 16)
                . ';theme_id=' . $themeId;
        } catch (\Throwable $e) {
            return 'snapshot=error:' . $e->getMessage();
        }
    }
}
