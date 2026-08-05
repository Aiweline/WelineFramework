<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontNavigationScope;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\Exception\ScopeResolutionException;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

/**
 * 请求 Scope 解析器（P1a）。
 *
 * 在 Website 命中之后一次性解析 Store 与 SalesChannel 三段，
 * 并把解析结果冻结进 RequestContext / ScopeContext：
 *
 * 1. Store 独立入口 URL 按规范 Origin 与最长路径段边界匹配；
 * 2. 站点 default Store 及其 default Channel；
 * 3. __store / __channel 仅作为一致性断言，不能改变可信解析结果。
 *
 * 未知、停用、跨站或歧义断言必须 fail-closed；可信 URL 无店铺匹配时才使用默认。
 */
class ScopeResolver
{
    public const PARAM_STORE = '__store';
    public const PARAM_CHANNEL = '__channel';

    public function __construct(
        private readonly StoreCatalogInterface $storeCatalog,
        private readonly SalesChannelCatalogInterface $channelCatalog,
    ) {
    }

    /**
     * 解析并冻结当前请求的完整 Scope。
     *
     * @param int    $websiteId   已命中的站点ID（0 为系统默认站，合法）
     * @param string $websiteCode 已命中的站点 code
     * @param string $trustedRequestUrl 由服务端组装的可信完整 http/https 请求 URL
     * @param array<string, mixed> $params 请求参数（用于显式 __store/__channel）
     */
    public function resolve(
        int $websiteId,
        string $websiteCode,
        string $trustedRequestUrl,
        array $params = [],
        ?string $defaultRoutePath = null,
    ): StorefrontNavigationScope
    {
        if ($websiteId < 0
            || trim($websiteCode) === ''
            || ($websiteId === Website::ID_DEFAULT && !hash_equals(Website::CODE_DEFAULT, $websiteCode))) {
            throw new ScopeResolutionException(
                'website_context_invalid',
                (string)__('站点范围上下文无效'),
                500,
            );
        }

        [$store, $routePath] = $this->resolveStore($websiteId, $trustedRequestUrl, $defaultRoutePath);
        $channel = $this->resolveChannel($websiteId, $store);
        $this->assertExplicitScope($store, $channel, $params);

        $storeCode = $store->code;
        $storeMode = $store->storeMode;
        $channelCode = $channel->code;
        $identity = ScopeIdentity::channel($websiteId, $websiteCode, $storeCode, $channelCode, $storeMode);

        // 冻结到 RequestContext（数值 ID + code + mode）
        RequestContext::setWelineStoreId($store->id);
        RequestContext::setWelineStoreCode($storeCode);
        RequestContext::setWelineStoreMode($storeMode);
        RequestContext::setWelineChannelId($channel->id);
        RequestContext::setWelineChannelCode($channelCode);
        RequestContext::installScopeIdentity($identity);

        // 冻结到 ScopeContext（三段字符串，兼容既有 scope 读取方）
        ScopeContext::setStoreCode($storeCode);
        ScopeContext::setChannelCode($channelCode);
        RequestContext::setStorefrontRoutePath($routePath);

        return new StorefrontNavigationScope($identity, $routePath);
    }

    /**
     * @return array{0: StoreSummary, 1: string}
     */
    private function resolveStore(
        int $websiteId,
        string $trustedRequestUrl,
        ?string $defaultRoutePath,
    ): array
    {
        try {
            $requestUrl = CanonicalStorefrontUrl::fromRequestUrl($trustedRequestUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'trusted_request_url_invalid',
                (string)__('可信请求 URL 无效，已拒绝解析店铺范围'),
                400,
                $exception,
            );
        }

        $bestMatch = null;
        $bestMatchUrl = null;
        $bestSpecificity = -1;
        $ambiguous = false;
        foreach ($this->storeCatalog->byWebsite($websiteId) as $candidate) {
            if ($candidate->url === null) {
                continue;
            }
            try {
                $candidateUrl = CanonicalStorefrontUrl::fromStoreUrl($candidate->url);
            } catch (\InvalidArgumentException $exception) {
                throw new ScopeResolutionException(
                    'store_url_invalid',
                    (string)__('店铺入口 URL 配置无效，已拒绝解析店铺范围'),
                    503,
                    $exception,
                );
            }
            if (!$candidateUrl->sameOrigin($requestUrl) || !$candidateUrl->matchesRequestPath($requestUrl)) {
                continue;
            }

            $specificity = $candidateUrl->pathSpecificity();
            if ($specificity > $bestSpecificity) {
                $bestMatch = $candidate;
                $bestMatchUrl = $candidateUrl;
                $bestSpecificity = $specificity;
                $ambiguous = false;
            } elseif ($specificity === $bestSpecificity) {
                $ambiguous = true;
            }
        }
        if ($ambiguous) {
            throw new ScopeResolutionException(
                'store_url_ambiguous',
                (string)__('当前 Origin/URI 匹配到多个同优先级店铺，已拒绝请求'),
                409,
            );
        }
        if ($bestMatch !== null && $bestMatchUrl instanceof CanonicalStorefrontUrl) {
            if ($bestMatch->websiteId !== $websiteId) {
                throw new ScopeResolutionException(
                    'store_website_conflict',
                    (string)__('当前请求命中的店铺与已冻结 Website 归属不一致'),
                    409,
                );
            }
            if ($bestMatch->lifecycleStatus === Store::LIFECYCLE_TOMBSTONE) {
                throw new ScopeResolutionException(
                    'store_tombstoned',
                    (string)__('当前请求命中的店铺已进入墓碑生命周期'),
                    410,
                );
            }
            if ($bestMatch->lifecycleStatus !== Store::LIFECYCLE_ACTIVE
                || $bestMatch->tombstonedAt !== null) {
                throw new ScopeResolutionException(
                    'store_lifecycle_invalid',
                    (string)__('当前请求命中的店铺生命周期无效'),
                    503,
                );
            }
            if (!$bestMatch->enabled) {
                throw new ScopeResolutionException(
                    'store_disabled',
                    (string)__('当前请求命中的店铺已停用'),
                    503,
                );
            }
            $routePath = (string)\substr($requestUrl->path, \strlen($bestMatchUrl->path));
            if ($bestMatchUrl->path === '/') {
                $routePath = $requestUrl->path;
            }
            $routePath = CanonicalStorefrontUrl::canonicalPath($routePath === '' ? '/' : $routePath);
            return [$bestMatch, $routePath];
        }

        $store = $this->storeCatalog->defaultStore($websiteId);
        if ($store === null || !$store->enabled
            || $store->lifecycleStatus !== Store::LIFECYCLE_ACTIVE
            || $store->tombstonedAt !== null
            || $store->websiteId !== $websiteId
            || !$store->isDefault
            || $store->code !== Store::CODE_DEFAULT
            || $store->storeMode !== Store::MODE_NORMAL) {
            throw new ScopeResolutionException(
                'default_store_unavailable',
                (string)__('当前站点缺少可用的默认店铺'),
                503,
            );
        }
        try {
            $routePath = CanonicalStorefrontUrl::canonicalPath($defaultRoutePath ?? $requestUrl->path);
        } catch (\InvalidArgumentException $exception) {
            throw new ScopeResolutionException(
                'storefront_route_path_invalid',
                (string)__('站点路由剩余路径无效'),
                500,
                $exception,
            );
        }
        return [$store, $routePath];
    }

    /**
     */
    private function resolveChannel(int $websiteId, StoreSummary $store): SalesChannelSummary
    {
        $channel = $this->channelCatalog->defaultChannel($store->id);
        if ($channel === null || !$channel->enabled || !$channel->effectiveEnabled
            || $channel->parentStoreLifecycleStatus !== Store::LIFECYCLE_ACTIVE
            || $channel->websiteId !== $websiteId
            || $channel->storeId !== $store->id
            || !$channel->isDefault
            || $channel->code !== SalesChannel::CODE_DEFAULT) {
            throw new ScopeResolutionException(
                'default_channel_unavailable',
                (string)__('当前店铺缺少可用的默认销售渠道'),
                503,
            );
        }
        return $channel;
    }

    /** @param array<string, mixed> $params */
    private function assertExplicitScope(
        StoreSummary $store,
        SalesChannelSummary $channel,
        array $params,
    ): void {
        $this->assertExplicitCode($params, self::PARAM_STORE, $store->code, 'store_assertion_conflict');
        $this->assertExplicitCode($params, self::PARAM_CHANNEL, $channel->code, 'channel_assertion_conflict');
    }

    /** @param array<string, mixed> $params */
    private function assertExplicitCode(array $params, string $parameter, string $trustedCode, string $reason): void
    {
        if (!array_key_exists($parameter, $params)) {
            return;
        }
        if (!is_scalar($params[$parameter])) {
            throw new ScopeResolutionException($reason, (string)__('请求的范围断言格式无效'));
        }
        $raw = trim((string)$params[$parameter]);
        $normalized = Store::normalizeCode($raw);
        if ($raw === '' || $normalized !== $raw || !hash_equals($trustedCode, $normalized)) {
            throw new ScopeResolutionException($reason, (string)__('请求的范围与可信 Host/URI 不一致'), 409);
        }
    }
}
