<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/** Resolves and freezes the authoritative storefront generation vector. */
final class StorefrontCacheKeyContextResolver
{
    public function __construct(
        private readonly NamespaceGenerationInterface $generations,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    public function freezeCurrent(): StorefrontCacheKeyContext
    {
        $identity = RequestContext::scopeIdentity();
        if (!$this->isCompleteChannelIdentity($identity)) {
            return StorefrontCacheKeyContext::currentOrRequestFence('storefront_scope_incomplete');
        }

        $lang = trim(RequestContext::getWelineUserLang());
        $currency = trim(RequestContext::getWelineUserCurrency());
        $existing = StorefrontCacheKeyContext::current();
        if ($existing instanceof StorefrontCacheKeyContext
            && $existing->cacheable
            && $existing->scopeIdentity?->equals($identity)
            && $existing->lang === ($lang !== '' ? $lang : 'zh_Hans_CN')
            && $existing->currency === ($currency !== '' ? $currency : 'CNY')
        ) {
            return $existing;
        }
        if ($existing instanceof StorefrontCacheKeyContext
            && !$existing->cacheable
            && $existing->failureCode === 'storefront_namespace_unavailable'
            && $existing->scopeIdentity?->equals($identity)
            && $existing->lang === ($lang !== '' ? $lang : 'zh_Hans_CN')
            && $existing->currency === ($currency !== '' ? $currency : 'CNY')
        ) {
            return $existing;
        }

        $provisional = $this->requestFence(
            $identity,
            $lang,
            $currency,
            'storefront_namespace_pending',
        );
        StorefrontCacheKeyContext::install($provisional);

        try {
            $fingerprint = $this->generations->fingerprint(
                $this->namespacePaths((string)$identity->websiteCode),
            );
            $resolved = new StorefrontCacheKeyContext(
                $identity,
                $provisional->lang,
                $provisional->currency,
                $fingerprint,
                $fingerprint,
                true,
            );
            StorefrontCacheKeyContext::install($resolved);
            return $resolved;
        } catch (\Throwable) {
            $failed = new StorefrontCacheKeyContext(
                $identity,
                $provisional->lang,
                $provisional->currency,
                null,
                $provisional->cacheKeyFingerprint,
                false,
                'storefront_namespace_unavailable',
            );
            StorefrontCacheKeyContext::install($failed);
            return $failed;
        }
    }

    /** Framework compatibility when no storefront installer is configured. */
    public function freezeLegacyDefault(): StorefrontCacheKeyContext
    {
        $identity = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
        $lang = Context::hasCurrent() ? RequestContext::getWelineUserLang() : 'zh_Hans_CN';
        $currency = Context::hasCurrent() ? RequestContext::getWelineUserCurrency() : 'CNY';
        $provisional = $this->requestFence($identity, $lang, $currency, 'legacy_namespace_pending');
        StorefrontCacheKeyContext::install($provisional);
        try {
            $fingerprint = $this->generations->fingerprint($this->namespacePaths('default'));
            $resolved = new StorefrontCacheKeyContext(
                $identity,
                $provisional->lang,
                $provisional->currency,
                $fingerprint,
                $fingerprint,
                true,
            );
            StorefrontCacheKeyContext::install($resolved);
            return $resolved;
        } catch (\Throwable) {
            return $provisional;
        }
    }

    /** @return list<string> */
    public function namespacePaths(string $websiteCode): array
    {
        $websiteCode = trim($websiteCode);
        if ($websiteCode === '') {
            throw new \InvalidArgumentException(__('Storefront 缓存版本缺少 website_code'));
        }

        return [
            $this->namespacePath->global('storefront', ['config']),
            $this->namespacePath->global('storefront', ['price']),
            $this->namespacePath->global('storefront', ['theme']),
            $this->namespacePath->website($websiteCode),
            $this->namespacePath->website($websiteCode, ['config']),
            $this->namespacePath->website($websiteCode, ['catalog']),
            $this->namespacePath->website($websiteCode, ['price']),
            $this->namespacePath->website($websiteCode, ['theme']),
        ];
    }

    private function requestFence(
        ScopeIdentity $identity,
        string $lang,
        string $currency,
        string $failureCode,
    ): StorefrontCacheKeyContext {
        $lang = trim($lang) !== '' ? trim($lang) : 'zh_Hans_CN';
        $currency = trim($currency) !== '' ? trim($currency) : 'CNY';
        $requestId = RequestContext::getId()
            ?? (Context::hasCurrent() ? 'context-' . spl_object_id(Context::current()) : 'process-' . getmypid());
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $nonce = str_replace('.', '', uniqid('', true));
        }

        return new StorefrontCacheKeyContext(
            $identity,
            $lang,
            $currency,
            null,
            hash('sha256', implode('|', [
                'request-fence-v1',
                $requestId,
                $identity->canonicalKey(),
                $lang,
                $currency,
                $nonce,
            ])),
            false,
            $failureCode,
        );
    }

    private function isCompleteChannelIdentity(?ScopeIdentity $identity): bool
    {
        return $identity instanceof ScopeIdentity
            && $identity->scopeKind === ScopeIdentity::KIND_CHANNEL
            && $identity->websiteId !== null
            && $identity->websiteCode !== null
            && $identity->storeCode !== null
            && $identity->channelCode !== null
            && $identity->storeMode !== null;
    }
}
