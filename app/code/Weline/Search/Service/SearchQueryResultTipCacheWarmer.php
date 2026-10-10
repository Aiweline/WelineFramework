<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * Seeds bounded tip search.query_result HotCache after cache:clear.
 *
 * Causal goal: shorten quiet cold hit1 exclusive for frozen tip URLs
 * (default q=hanfu × contracts locale/currency). Projection warmer peek is NOT
 * this bag — do not treat product.search_projection_scope HIT as query_result HIT.
 * Sticky hit2 alone is NOT an acceptance pass for this warmer.
 *
 * Fail-open; never warm the full query space.
 */
final class SearchQueryResultTipCacheWarmer implements CacheWarmerInterface
{
    private const TIP_Q = 'hanfu';

    private const PAGE = 1;

    private const PAGE_SIZE = 24;

    private const TYPE = 'all';

    private const AREA = 'frontend';

    /** Soft wall so Clear stays usable alongside product/chrome warmers. */
    private const BUDGET_MS = 45000;

    /**
     * Frozen tip matrix (contracts A-search-query-result). Bounded — not all q.
     *
     * @var list<array{lang:string,currency:string}>
     */
    private const TIP_SCOPES = [
        ['lang' => 'de_DE', 'currency' => 'USD'],
        ['lang' => 'ja_JP', 'currency' => 'USD'],
        ['lang' => 'fr_FR', 'currency' => 'EUR'],
    ];

    public function getName(): string
    {
        return 'search.query_result_tip';
    }

    public function getTargetPool(): string
    {
        return 'search';
    }

    public function getPriority(): int
    {
        // After Product search_projection_scope (220); before shelf (230).
        return 225;
    }

    public function canWarm(): bool
    {
        return \class_exists(SearchHubService::class)
            && \class_exists(StorefrontScopeHotCache::class);
    }

    public function warm(): array
    {
        $hadRequest = RequestContext::isInitialized();
        if (!$hadRequest) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'request_init_fail:' . $e->getMessage(),
                ];
            }
        }

        $warmedL2Hit = 0;
        $skipped = 0;
        $remaining = 0;
        $notes = [];
        $started = \hrtime(true);

        try {
            $this->installDefaultChannelIdentity();
            $notes[] = $this->installAcceptancePublicHost();
            $notes[] = 'tip_q=' . self::TIP_Q;
            $notes[] = 'tip_scopes=' . \count(self::TIP_SCOPES);

            /** @var SearchHubService $hub */
            $hub = ObjectManager::getInstance(SearchHubService::class);
            /** @var SearchEngineResolver $engines */
            $engines = ObjectManager::getInstance(SearchEngineResolver::class);
            $engineCode = $engines->configuredCode();
            if ($engineCode === '') {
                $engineCode = SearchEngineResolver::DEFAULT_ENGINE;
            }
            /** @var StorefrontCacheKeyContextResolver $resolver */
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);

            foreach (self::TIP_SCOPES as $idx => $scope) {
                $elapsedMs = (\hrtime(true) - $started) / 1e6;
                if ($elapsedMs >= self::BUDGET_MS) {
                    $remaining = \count(self::TIP_SCOPES) - $idx;
                    $notes[] = 'budget_ms=' . self::BUDGET_MS;
                    $notes[] = 'remaining=' . $remaining;
                    break;
                }

                $lang = $scope['lang'];
                $currency = $scope['currency'];
                try {
                    RequestContext::setWelineUserLang($lang);
                    RequestContext::setWelineUserCurrency($currency);
                    RequestContext::remove('framework.cache.storefront_key_context.v2');

                    if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                        $ctx = $resolver->freezeCurrent();
                        if (!$ctx->cacheable) {
                            ++$skipped;
                            $notes[] = 'freeze_fail:' . $lang . ':' . ($ctx->failureCode ?: 'unknown');
                            continue;
                        }
                    }

                    $logicalKey = SearchStorefrontCacheCoordinator::queryResultLogicalKey(
                        self::TIP_Q,
                        self::TYPE,
                        self::PAGE,
                        self::PAGE_SIZE,
                        $engineCode,
                        self::AREA,
                        false,
                    );

                    $result = $hub->search([
                        'q' => self::TIP_Q,
                        'type' => self::TYPE,
                        'page' => self::PAGE,
                        'page_size' => self::PAGE_SIZE,
                    ], false);

                    if (!$result->ok) {
                        ++$skipped;
                        $notes[] = 'search_fail:' . $lang . ':' . ($result->errorCode ?: 'unknown');
                        continue;
                    }

                    $hot->purgeProcessCacheForLogicalKey($logicalKey);
                    $peek = $hot->peekPolicy(
                        SearchStorefrontCacheCoordinator::queryResultPolicy(),
                        $logicalKey,
                    );
                    if (\is_array($peek) && $peek !== []) {
                        ++$warmedL2Hit;
                        $notes[] = 'l2_hit:' . $lang . ':' . $currency;
                    } else {
                        ++$skipped;
                        $notes[] = 'l2_miss:' . $lang . ':' . $currency;
                    }
                } catch (\Throwable $e) {
                    ++$skipped;
                    $notes[] = 'error:' . $lang . ':' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                RequestContext::resetWelineVars();
            } catch (\Throwable) {
            }
        }

        if ($remaining > 0) {
            ++$skipped;
            $notes[] = 'partial=1';
        }
        $notes[] = 'warmed_l2_hit=' . $warmedL2Hit;

        return [
            'warmed' => \max(0, $warmedL2Hit),
            'skipped' => $skipped,
            'message' => \implode(';', $notes) ?: 'ok',
        ];
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

    /**
     * Pin CLI/Clear identity to storefront public Host so HotCache keys match HTTP.
     */
    private function installAcceptancePublicHost(): string
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
        $candidates[] = 'https://p05113ef3.test.weline.com';

        $scheme = 'https';
        $host = '';
        foreach ($candidates as $candidate) {
            $parts = \parse_url($candidate);
            if (!\is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
            if ($scheme !== 'http' && $scheme !== 'https') {
                $scheme = 'https';
            }
            $host = \strtolower((string)$parts['host']);
            $port = isset($parts['port']) ? (int)$parts['port'] : 0;
            if ($port > 0 && !\in_array($port, [80, 443], true)) {
                $host .= ':' . $port;
            }
            break;
        }
        if ($host === '') {
            return 'host=unresolved';
        }

        $websiteUrl = $scheme . '://' . $host;
        RequestContext::setWelineWebsiteUrl($websiteUrl);
        WelineEnv::setServer('HTTP_HOST', $host, 'SearchQueryResultTipCacheWarmer');
        WelineEnv::setServer('SERVER_NAME', \explode(':', $host, 2)[0], 'SearchQueryResultTipCacheWarmer');
        WelineEnv::setServer('REQUEST_SCHEME', $scheme, 'SearchQueryResultTipCacheWarmer');
        WelineEnv::setServer('HTTPS', $scheme === 'https' ? 'on' : 'off', 'SearchQueryResultTipCacheWarmer');
        WelineEnv::setServer('WELINE_WEBSITE_URL', $websiteUrl, 'SearchQueryResultTipCacheWarmer');
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

        return 'host=' . $host;
    }
}
