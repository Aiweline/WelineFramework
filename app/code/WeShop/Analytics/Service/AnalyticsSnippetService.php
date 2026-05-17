<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Provider\BingAds;
use WeShop\Analytics\Provider\FacebookPixel;
use WeShop\Analytics\Provider\GoogleAnalytics;
use WeShop\Analytics\Provider\TikTokPixel;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class AnalyticsSnippetService
{
    public const SLOT_HEAD = 'head';
    public const SLOT_BODY = 'body';
    public const SLOT_FOOTER = 'footer';
    private const FRONTEND_SNIPPET_CACHE_TTL = 60;

    protected ?PixelProviderInterface $googleAnalytics = null;
    protected ?PixelProviderInterface $facebookPixel = null;
    protected ?PixelProviderInterface $tiktokPixel = null;
    protected ?PixelProviderInterface $bingAds = null;
    /** @var array<string, array{expires_at: float, data: array<int, array{provider:string,snippet:string}>}> */
    private static array $frontendSlotSnippetCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    public function __construct(
        ?GoogleAnalytics $googleAnalytics = null,
        ?FacebookPixel $facebookPixel = null,
        ?TikTokPixel $tiktokPixel = null,
        ?BingAds $bingAds = null
    ) {
        $this->googleAnalytics = $googleAnalytics;
        $this->facebookPixel = $facebookPixel;
        $this->tiktokPixel = $tiktokPixel;
        $this->bingAds = $bingAds;
    }

    /**
     * @return array<int, array{provider:string,snippet:string}>
     */
    public function getFrontendPixelSnippets(): array
    {
        $snippets = [];

        foreach ($this->getProviders() as $providerCode => $provider) {
            if (!$provider instanceof PixelProviderInterface || !$provider->isEnabled()) {
                continue;
            }

            $snippet = trim($provider->getPixelCode());
            if ($snippet === '') {
                continue;
            }

            $snippets[] = [
                'provider' => $providerCode,
                'snippet' => $snippet,
            ];
        }

        return $snippets;
    }

    /**
     * @return array<int, array{provider:string,snippet:string}>
     */
    public function getFrontendPixelSnippetsBySlot(string $slot): array
    {
        $slot = $this->normalizeSlot($slot);
        if ($slot === null) {
            return [];
        }

        $cacheKey = $this->buildFrontendSlotSnippetCacheKey($slot);
        if ($cacheKey !== null) {
            $cached = self::$frontendSlotSnippetCache[$cacheKey] ?? null;
            if (\is_array($cached)
                && isset($cached['expires_at'], $cached['data'])
                && (float)$cached['expires_at'] >= \microtime(true)
                && \is_array($cached['data'])) {
                return $cached['data'];
            }

            $runtimeCached = $this->runtimeCacheGet($cacheKey);
            if (\is_array($runtimeCached)) {
                $this->rememberFrontendSlotSnippetCache($cacheKey, $runtimeCached);
                return $runtimeCached;
            }
        }

        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $traceName = 'analytics::AnalyticsSnippetService::getFrontendPixelSnippetsBySlot::' . $slot;
        $traceStart = $this->tracePush($traceEnabled, $traceName);
        $snippets = [];
        try {
            foreach ($this->getProviders() as $providerCode => $provider) {
                $providerTraceName = 'analytics::AnalyticsSnippetService::provider::' . $providerCode;
                $providerTraceStart = $this->tracePush($traceEnabled, $providerTraceName);
                try {
                    if (!$provider instanceof PixelProviderInterface) {
                        continue;
                    }

                    $enabledTraceName = $providerTraceName . '::isEnabled';
                    $enabledTraceStart = $this->tracePush($traceEnabled, $enabledTraceName);
                    try {
                        $isEnabled = $provider->isEnabled();
                    } finally {
                        $this->tracePop($traceEnabled, $enabledTraceName, $enabledTraceStart);
                    }

                    if (!$isEnabled) {
                        continue;
                    }

                    $resolveTraceName = $providerTraceName . '::resolveSlotSnippets';
                    $resolveTraceStart = $this->tracePush($traceEnabled, $resolveTraceName);
                    try {
                        $providerSnippets = $this->resolveProviderSlotSnippets($provider);
                    } finally {
                        $this->tracePop($traceEnabled, $resolveTraceName, $resolveTraceStart);
                    }

                    $snippet = trim((string) ($providerSnippets[$slot] ?? ''));
                    if ($snippet === '') {
                        continue;
                    }

                    $snippets[] = [
                        'provider' => $providerCode,
                        'snippet' => $snippet,
                    ];
                } finally {
                    $this->tracePop($traceEnabled, $providerTraceName, $providerTraceStart);
                }
            }

        } finally {
            $this->tracePop($traceEnabled, $traceName, $traceStart);
        }

        if ($cacheKey !== null) {
            $this->rememberFrontendSlotSnippetCache($cacheKey, $snippets);
            $this->runtimeCacheSet($cacheKey, $snippets, $this->frontendSnippetCacheTtl());
        }

        return $snippets;
    }

    /**
     * @return array<string, PixelProviderInterface|null>
     */
    protected function getProviders(): array
    {
        return [
            'google' => $this->googleAnalytics,
            'facebook' => $this->facebookPixel,
            'tiktok' => $this->tiktokPixel,
            'bing' => $this->bingAds,
        ];
    }

    /**
     * @return array{head:string,body:string,footer:string}
     */
    private function resolveProviderSlotSnippets(PixelProviderInterface $provider): array
    {
        if (method_exists($provider, 'getPixelHookSnippets')) {
            $snippets = $provider->getPixelHookSnippets();
            if (is_array($snippets)) {
                return [
                    self::SLOT_HEAD => trim((string) ($snippets[self::SLOT_HEAD] ?? '')),
                    self::SLOT_BODY => trim((string) ($snippets[self::SLOT_BODY] ?? '')),
                    self::SLOT_FOOTER => trim((string) ($snippets[self::SLOT_FOOTER] ?? '')),
                ];
            }
        }

        return $this->splitLegacyPixelSnippet($provider->getPixelCode());
    }

    /**
     * @return array{head:string,body:string,footer:string}
     */
    private function splitLegacyPixelSnippet(string $snippet): array
    {
        $snippet = trim($snippet);
        if ($snippet === '') {
            return [
                self::SLOT_HEAD => '',
                self::SLOT_BODY => '',
                self::SLOT_FOOTER => '',
            ];
        }

        $bodySnippets = [];
        if (preg_match_all('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', $snippet, $matches)) {
            $bodySnippets = $matches[0] ?? [];
        }

        $body = trim(implode("\n", array_map('trim', array_filter($bodySnippets, 'is_string'))));
        $head = preg_replace('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', '', $snippet);

        return [
            self::SLOT_HEAD => trim((string) $head),
            self::SLOT_BODY => $body,
            self::SLOT_FOOTER => '',
        ];
    }

    private function normalizeSlot(string $slot): ?string
    {
        $slot = strtolower(trim($slot));

        return match ($slot) {
            self::SLOT_HEAD,
            self::SLOT_BODY,
            self::SLOT_FOOTER => $slot,
            default => null,
        };
    }

    private function buildFrontendSlotSnippetCacheKey(string $slot): ?string
    {
        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        $envFile = BP . 'app' . DS . 'etc' . DS . 'env.php';
        $envMtime = (int)@filemtime($envFile);
        $envSize = (int)@filesize($envFile);

        return 'analytics.frontend_slot_snippets.' . \sha1(\implode('|', [
            $slot,
            (string)$envMtime,
            (string)$envSize,
            (string)Env::get('theme.id', ''),
            (string)Env::get('server.host', ''),
        ]));
    }

    /**
     * @param array<int, array{provider:string,snippet:string}> $snippets
     */
    private function rememberFrontendSlotSnippetCache(string $cacheKey, array $snippets): void
    {
        if (\count(self::$frontendSlotSnippetCache) > 16) {
            self::$frontendSlotSnippetCache = [];
        }

        self::$frontendSlotSnippetCache[$cacheKey] = [
            'expires_at' => \microtime(true) + $this->frontendSnippetCacheTtl(),
            'data' => $snippets,
        ];
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('theme_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set('theme_runtime', $key, $value, \max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent() || !\class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            self::$runtimeCache = new MemoryStateFacade(ObjectManager::getInstance(RuntimeCachePolicy::class)->memoryOptions([
                'consumer_code' => 'analytics_frontend_snippets',
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function frontendSnippetCacheTtl(): int
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class)->ttl(
            'theme.hook_output_ttl',
            self::FRONTEND_SNIPPET_CACHE_TTL
        );
    }

    private function tracePush(bool $traceEnabled, string $name): float
    {
        if (!$traceEnabled) {
            return 0.0;
        }

        RequestLifecycleTrace::pushCurrentParent($name);

        return microtime(true);
    }

    private function tracePop(bool $traceEnabled, string $name, float $start, string $category = 'analytics'): void
    {
        if (!$traceEnabled) {
            return;
        }

        RequestLifecycleTrace::popCurrentParent();
        RequestLifecycleTrace::recordSpan(
            $name,
            (microtime(true) - $start) * 1000,
            $category
        );
    }
}
