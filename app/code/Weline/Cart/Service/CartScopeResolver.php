<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
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
                return $currentScope;
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
            return $candidate;
        }
        if ($this->canRefineWebsiteScope($candidate, $trusted)) {
            return $trusted;
        }

        throw new CartV2ConflictException(
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
                return $bound;
            }

            if ($this->canRefineWebsiteScope($current, $bound)) {
                return $bound;
            }
        }

        if ($current instanceof ScopeIdentity
            && !$current->isGlobal()
            && $current->scopeKind !== ScopeIdentity::KIND_WEBSITE) {
            return $current;
        }

        $default = $this->serverResolvedDefaultScope();
        if ($default instanceof ScopeIdentity
            && (!$current instanceof ScopeIdentity
                || $current->isGlobal()
                || $current->equals($default)
                || $this->canRefineWebsiteScope($current, $default))) {
            return $default;
        }

        return $current;
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

            return $installer->installNavigationScope($scheme . '://' . $authority . '/')->scope;
        } catch (\Throwable) {
            return null;
        }
    }
}
