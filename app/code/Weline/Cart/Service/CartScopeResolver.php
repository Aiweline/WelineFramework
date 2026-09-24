<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
use Weline\Framework\Runtime\StorefrontWebsiteContext;
use Weline\Framework\Runtime\StorefrontWebsiteContextResolverInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

/**
 * Converts Cart API parameters into the immutable three-segment Scope identity.
 */
final class CartScopeResolver implements CartScopeResolverInterface
{
    /** @var (\Closure(): (?ScopeIdentity))|null */
    private readonly ?\Closure $serverScopeResolver;

    /** @param (callable(): (?ScopeIdentity))|null $serverScopeResolver */
    public function __construct(?callable $serverScopeResolver = null)
    {
        $this->serverScopeResolver = $serverScopeResolver === null
            ? null
            : \Closure::fromCallable($serverScopeResolver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fromParams(array $params): ScopeIdentity
    {
        if (isset($params['scope']) && is_array($params['scope'])) {
            return $this->assertTrustedRequestScope(ScopeIdentity::fromArray($params['scope']));
        }

        $explicitScopeKeys = [
            'website_id',
            'website_code',
            'store_code',
            'channel_code',
            'store_mode',
        ];
        if (array_intersect_key($params, array_flip($explicitScopeKeys)) === []) {
            $currentScope = $this->trustedRequestScope();
            if ($currentScope instanceof ScopeIdentity && !$currentScope->isGlobal()) {
                return $this->normalizeCartScope($currentScope);
            }
        }

        $websiteId = (int)($params['website_id'] ?? 0);
        $websiteCode = trim((string)($params['website_code'] ?? 'default')) ?: 'default';
        $storeCode = trim((string)($params['store_code'] ?? ''));
        $channelCode = trim((string)($params['channel_code'] ?? ''));
        $storeMode = trim((string)($params['store_mode'] ?? ScopeIdentity::MODE_NORMAL))
            ?: ScopeIdentity::MODE_NORMAL;

        if ($channelCode !== '' && $storeCode === '') {
            throw new \InvalidArgumentException((string)__('channel_code 必须与 store_code 同时提供'));
        }
        if ($channelCode !== '') {
            return $this->assertTrustedRequestScope(ScopeIdentity::channel(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $storeMode,
            ));
        }
        if ($storeCode !== '') {
            return $this->assertTrustedRequestScope(
                ScopeIdentity::store($websiteId, $websiteCode, $storeCode, $storeMode),
            );
        }

        return $this->assertTrustedRequestScope(ScopeIdentity::website($websiteId, $websiteCode));
    }

    private function assertTrustedRequestScope(ScopeIdentity $candidate): ScopeIdentity
    {
        $trusted = $this->trustedRequestScope();
        if ($trusted === null || $trusted->isGlobal() || $trusted->equals($candidate)) {
            return $this->normalizeCartScope($candidate);
        }
        if ($this->canRefineWebsiteScope($candidate, $trusted)) {
            return $this->normalizeCartScope($trusted);
        }

        throw new CartConflictException(
            'cart_scope_request_conflict',
            (string)__('购物车 Scope 与当前可信 Website/Store/Channel 请求不一致'),
            [
                'trusted_scope_key' => $trusted->canonicalKey(),
                'requested_scope_key' => $candidate->canonicalKey(),
            ],
        );
    }

    /**
     * Resolve the server-owned storefront Scope for both HTML and QueryBin.
     *
     * QueryBin can run while the global rollout still keeps RequestContext at
     * Website/global scope. Its execution context nevertheless carries the
     * already signed and gateway-revalidated Channel binding; Cart must use
     * that binding instead of silently creating a different Website cart.
     *
     * When Scope-kernel rollout is off (no worker binding) and RequestContext
     * stays at Website projection, Cart still must not key rows under
     * `website|…` — storefront carts are persisted at Channel scope.
     */
    private function trustedRequestScope(): ?ScopeIdentity
    {
        $current = RequestContext::scopeIdentity();
        $execution = RequestContext::get(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        $binding = $execution instanceof FrontendWorkerExecutionContext
            && $execution->area === FrontendWorkerExecutionContext::AREA_FRONTEND
            ? $execution->scopeBinding
            : null;

        if ($binding instanceof FrontendWorkerScopeBinding
            && $binding->tokenExpiresAt > \time()) {
            $bound = $binding->scope;
            if (!$current instanceof ScopeIdentity || $current->isGlobal() || $current->equals($bound)) {
                return $this->normalizeCartScope($bound);
            }

            if ($this->canRefineWebsiteScope($current, $bound)) {
                return $this->normalizeCartScope($bound);
            }
        }

        if ($current instanceof ScopeIdentity
            && !$current->isGlobal()
            && $current->scopeKind !== ScopeIdentity::KIND_WEBSITE) {
            return $this->normalizeCartScope($current);
        }

        $default = $this->serverResolvedDefaultScope();
        if ($default instanceof ScopeIdentity
            && (!$current instanceof ScopeIdentity
                || $current->isGlobal()
                || $current->equals($default)
                || $this->canRefineWebsiteScope($current, $default))) {
            return $this->normalizeCartScope($default);
        }

        return $current instanceof ScopeIdentity ? $this->normalizeCartScope($current) : null;
    }

    /**
     * Commerce cart rows are Channel-keyed. A Website RequestContext projection
     * (common on QueryBin while Scope-kernel rollout is off / installer miss)
     * must refine to the website's default Channel instead of opening an empty
     * parallel `website|…` cart namespace.
     */
    private function normalizeCartScope(ScopeIdentity $scope): ScopeIdentity
    {
        if ($scope->isGlobal() || $scope->scopeKind !== ScopeIdentity::KIND_WEBSITE) {
            return $scope;
        }

        $default = $this->serverResolvedDefaultScope();
        if ($default instanceof ScopeIdentity
            && !$default->isGlobal()
            && $this->canRefineWebsiteScope($scope, $default)) {
            return $default;
        }

        $websiteCode = \trim((string)($scope->websiteCode ?? '')) ?: 'default';

        return ScopeIdentity::channel(
            (int)$scope->websiteId,
            $websiteCode,
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
    }

    private function canRefineWebsiteScope(ScopeIdentity $current, ScopeIdentity $candidate): bool
    {
        return $current->scopeKind === ScopeIdentity::KIND_WEBSITE
            && $current->websiteId === $candidate->websiteId
            && \hash_equals((string)$current->websiteCode, (string)$candidate->websiteCode);
    }

    private function serverResolvedDefaultScope(): ?ScopeIdentity
    {
        if ($this->serverScopeResolver !== null) {
            $resolved = ($this->serverScopeResolver)();
            return $resolved instanceof ScopeIdentity && !$resolved->isGlobal() ? $resolved : null;
        }

        $authority = RequestAuthority::current();
        if ($authority === '') {
            return null;
        }

        try {
            $installer = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(StorefrontScopeInstallerInterface::class);
            if (!$installer instanceof StorefrontScopeInstallerInterface) {
                return null;
            }
            $scheme = \strtolower(\trim(WelineEnv::getRequestScheme()));
            if (!\in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            // QueryBin defers storefront Scope install and its URI is always the
            // fixed /api/framework/query-bin path. With Scope-kernel OFF there is
            // no Worker binding, so Host-root (`/`) would resolve the default
            // website on a shared Host and miss path-mounted catalogs (e.g.
            // /daocharms → website 158). Prefer a same-origin document Referer
            // that actually matches a Website mount; fall back to Host root.
            $navigationUri = $this->navigationUriForInstaller($scheme, $authority);

            return $installer->installNavigationScope($navigationUri)->identity;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pick the navigation URL used to install Channel Scope when QueryBin has
     * no Worker Scope binding.
     */
    private function navigationUriForInstaller(string $scheme, string $authority): string
    {
        $fallback = $scheme . '://' . $authority . '/';

        // Prefer Worker-signed document pathname. Dedicated Worker fetch Referer
        // is the worker script URL (not the PDP), so HTTP_REFERER alone cannot
        // recover path mounts like /daocharms.
        $fromWorker = $this->workerStorefrontNavigationUri($scheme, $authority);
        if ($fromWorker !== null && $this->websiteContextMatches($fromWorker)) {
            return $fromWorker;
        }

        $referer = $this->sameSiteStorefrontRefererUri($scheme, $authority);
        if ($referer !== null && $this->websiteContextMatches($referer)) {
            return $referer;
        }

        return $fallback;
    }

    private function workerStorefrontNavigationUri(string $scheme, string $authority): ?string
    {
        $pathname = RequestContext::get(
            FrontendWorkerExecutionContext::STOREFRONT_PATHNAME_CONTEXT_KEY,
        );
        if (!\is_string($pathname)) {
            return null;
        }
        $pathname = \trim($pathname);
        if ($pathname === '' || !\str_starts_with($pathname, '/') || \str_contains($pathname, '://')) {
            return null;
        }

        return $scheme . '://' . $authority . $pathname;
    }

    private function websiteContextMatches(string $navigationUri): bool
    {
        try {
            $websiteResolver = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(StorefrontWebsiteContextResolverInterface::class);
            if (!$websiteResolver instanceof StorefrontWebsiteContextResolverInterface) {
                return false;
            }
            return $websiteResolver->resolveWebsiteContext($navigationUri) instanceof StorefrontWebsiteContext;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Same-origin document Referer suitable for Website path-mount detection.
     * Cross-origin and QueryBin self-referers are rejected.
     */
    private function sameSiteStorefrontRefererUri(string $scheme, string $authority): ?string
    {
        $context = Context::getCurrent();
        if (!$context instanceof Context) {
            return null;
        }
        $raw = \trim((string)$context->server('HTTP_REFERER', ''));
        if ($raw === '' || \strlen($raw) > 2048) {
            return null;
        }

        try {
            $parts = \parse_url($raw);
        } catch (\ValueError) {
            return null;
        }
        if (!\is_array($parts)) {
            return null;
        }

        $refScheme = \strtolower(\trim((string)($parts['scheme'] ?? '')));
        if ($refScheme !== $scheme) {
            return null;
        }

        $host = \trim((string)($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $refAuthority = RequestAuthority::canonicalize($host . $port);
        if ($refAuthority === '' || !\hash_equals($authority, $refAuthority)) {
            return null;
        }

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $normalizedPath = \strtolower('/' . \ltrim($path, '/'));
        if ($normalizedPath === '/api/framework/query-bin'
            || $normalizedPath === '/framework/query-bin'
            || \str_contains($normalizedPath, '/query-bin')) {
            return null;
        }

        $uri = $scheme . '://' . $authority . $path;
        $query = (string)($parts['query'] ?? '');
        if ($query !== '') {
            $uri .= '?' . $query;
        }

        return $uri;
    }
}
