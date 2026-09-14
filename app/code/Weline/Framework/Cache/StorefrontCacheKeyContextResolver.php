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
        $defaultLocale = \Weline\Framework\Phrase\LocaleFallbackChain::websiteDefaultLocale();
        $translationLocales = \Weline\Framework\Phrase\LocaleFallbackChain::candidates($lang, $defaultLocale);
        $existing = StorefrontCacheKeyContext::current();
        if ($existing instanceof StorefrontCacheKeyContext
            && $existing->cacheable
            && $existing->scopeIdentity?->equals($identity)
            && $existing->lang === ($lang !== '' ? $lang : 'zh_Hans_CN')
            && $existing->currency === ($currency !== '' ? $currency : 'CNY')
            && $existing->defaultLocale === $defaultLocale
            && $existing->translationLocales === $translationLocales
        ) {
            return $existing;
        }
        if ($existing instanceof StorefrontCacheKeyContext
            && !$existing->cacheable
            && $existing->failureCode === 'storefront_namespace_unavailable'
            && $existing->scopeIdentity?->equals($identity)
            && $existing->lang === ($lang !== '' ? $lang : 'zh_Hans_CN')
            && $existing->currency === ($currency !== '' ? $currency : 'CNY')
            && $existing->defaultLocale === $defaultLocale
            && $existing->translationLocales === $translationLocales
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
            $fingerprint = $this->fingerprintForIdentity($identity, $provisional->translationLocales);
            $resolved = new StorefrontCacheKeyContext(
                $identity,
                $provisional->lang,
                $provisional->currency,
                $fingerprint,
                $this->translationKeyFingerprint($fingerprint, $provisional),
                true,
                '',
                $provisional->defaultLocale,
                $provisional->translationLocales,
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
                $provisional->defaultLocale,
                $provisional->translationLocales,
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
            $fingerprint = $this->fingerprintForIdentity($identity, $provisional->translationLocales);
            $resolved = new StorefrontCacheKeyContext(
                $identity,
                $provisional->lang,
                $provisional->currency,
                $fingerprint,
                $this->translationKeyFingerprint($fingerprint, $provisional),
                true,
                '',
                $provisional->defaultLocale,
                $provisional->translationLocales,
            );
            StorefrontCacheKeyContext::install($resolved);
            return $resolved;
        } catch (\Throwable) {
            return $provisional;
        }
    }

    /** 复用权威请求快照核对已冻结回执；不信任广播更新的进程向量。 */
    public function fingerprintForIdentity(ScopeIdentity $identity, array $translationLocales = []): string
    {
        return $this->generations->fingerprint($this->namespacePathsForIdentity($identity, $translationLocales));
    }

    private function translationKeyFingerprint(string $fingerprint, StorefrontCacheKeyContext $context): string
    {
        return hash('sha256', json_encode([
            'namespace' => $fingerprint,
            'lang' => $context->lang,
            'default_locale' => $context->defaultLocale,
            'translation_locales' => $context->translationLocales,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @return list<string> */
    public function namespacePaths(string $websiteCode, array $translationLocales = []): array
    {
        $websiteCode = trim($websiteCode);
        if ($websiteCode === '') {
            throw new \InvalidArgumentException(__('Storefront 缓存版本缺少 website_code'));
        }

        return [
            $this->namespacePath->global('i18n'),
            ...\Weline\Framework\Phrase\DictionaryCacheNamespace::namespacePaths($translationLocales),
            $this->namespacePath->global('storefront', ['config']),
            $this->namespacePath->global('storefront', ['price']),
            $this->namespacePath->global('storefront', ['theme']),
            $this->namespacePath->global('storefront', ['auth']),
            $this->namespacePath->website($websiteCode),
            $this->namespacePath->website($websiteCode, ['config']),
            $this->namespacePath->website($websiteCode, ['catalog']),
            $this->namespacePath->website($websiteCode, ['price']),
            $this->namespacePath->website($websiteCode, ['theme']),
            $this->namespacePath->website($websiteCode, ['auth']),
        ];
    }

    /**
     * Scope-aware storefront vector. Parent paths remain in the vector, so a
     * Website bump invalidates its Stores and Channels without enumerating them.
     *
     * @return list<string>
     */
    public function namespacePathsForIdentity(ScopeIdentity $identity, array $translationLocales = []): array
    {
        if (!$this->isCompleteChannelIdentity($identity)) {
            throw new \InvalidArgumentException(__('Storefront 缓存版本缺少完整 Channel Scope'));
        }

        $websiteCode = (string)$identity->websiteCode;
        $storeCode = (string)$identity->storeCode;
        $channelCode = (string)$identity->channelCode;
        $storeMode = (string)$identity->storeMode;

        return [
            ...$this->namespacePaths($websiteCode, $translationLocales),
            $this->namespacePath->website($websiteCode, ['theme', 'store', $storeCode, $storeMode]),
            $this->namespacePath->website(
                $websiteCode,
                ['theme', 'store', $storeCode, $storeMode, 'channel', $channelCode],
            ),
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
