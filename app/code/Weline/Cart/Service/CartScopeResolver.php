<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Converts Cart API parameters into the immutable three-segment Scope identity.
 */
final class CartScopeResolver
{
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
            $currentScope = RequestContext::scopeIdentity();
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
        $trusted = RequestContext::scopeIdentity();
        if ($trusted === null || $trusted->isGlobal() || $trusted->equals($candidate)) {
            return $candidate;
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
}
