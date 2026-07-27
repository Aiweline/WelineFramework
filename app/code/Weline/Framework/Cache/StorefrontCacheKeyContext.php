<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;

/** Immutable storefront cache identity installed in RequestContext. */
final readonly class StorefrontCacheKeyContext
{
    public const SCHEMA_VERSION = 'storefront-cache-v2';
    private const STORAGE_KEY = 'framework.cache.storefront_key_context.v2';

    public function __construct(
        public ?ScopeIdentity $scopeIdentity,
        public string $lang,
        public string $currency,
        public ?string $namespaceFingerprint,
        public string $cacheKeyFingerprint,
        public bool $cacheable,
        public string $failureCode = '',
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $cacheKeyFingerprint) !== 1) {
            throw new \InvalidArgumentException(__('Storefront 缓存键指纹必须是小写 SHA-256'));
        }
        if ($namespaceFingerprint !== null
            && preg_match('/^[a-f0-9]{64}$/D', $namespaceFingerprint) !== 1
        ) {
            throw new \InvalidArgumentException(__('Storefront 命名空间指纹必须是小写 SHA-256'));
        }
        if ($cacheable && $namespaceFingerprint === null) {
            throw new \InvalidArgumentException(__('可缓存 Storefront 上下文必须携带命名空间指纹'));
        }
    }

    public static function current(): ?self
    {
        $context = RequestContext::get(self::STORAGE_KEY);
        return $context instanceof self ? $context : null;
    }

    public static function install(self $context): void
    {
        if (!Context::hasCurrent()) {
            return;
        }
        RequestContext::set(self::STORAGE_KEY, $context);
    }

    /**
     * Static cache callers use a request-only fence until the DB-backed
     * generation vector is resolved. This path never touches ObjectManager or
     * storage, so namespace ORM reads cannot recurse through KeyBuilder.
     */
    public static function currentOrRequestFence(string $failureCode = 'storefront_cache_context_unresolved'): self
    {
        $current = self::current();
        if ($current instanceof self) {
            return $current;
        }

        if (!Context::hasCurrent()) {
            if (Runtime::isPersistent()) {
                try {
                    $seed = bin2hex(random_bytes(16));
                } catch (\Throwable) {
                    $seed = str_replace('.', '', uniqid('', true));
                }
            } else {
                // CLI owns one bootstrap scope per process; FPM exposes a
                // request-local start time. This keeps set/get stable inside
                // that scope without introducing static process state. WLS is
                // deliberately excluded because its process serves many tasks.
                $seed = implode('|', [
                    Runtime::getMode(),
                    (string)getmypid(),
                    (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? $_SERVER['REQUEST_TIME'] ?? ''),
                    (string)($_SERVER['SCRIPT_FILENAME'] ?? ''),
                ]);
            }
            return new self(
                null,
                trim(WelineEnv::getLang()) ?: 'zh_Hans_CN',
                trim(WelineEnv::getCurrency()) ?: 'CNY',
                null,
                hash('sha256', 'no-context-fence-v2|' . $seed),
                false,
                $failureCode,
            );
        }

        $identity = RequestContext::scopeIdentity();
        $lang = trim(RequestContext::getWelineUserLang());
        $currency = trim(RequestContext::getWelineUserCurrency());
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $nonce = str_replace('.', '', uniqid('', true));
        }
        $requestIdentity = RequestContext::getId();
        if ($requestIdentity === null) {
            $requestIdentity = 'context-' . spl_object_id(Context::current());
        }
        $fingerprint = hash('sha256', implode('|', [
            'request-fence-v1',
            $requestIdentity,
            $identity?->canonicalKey() ?? 'unresolved',
            $lang,
            $currency,
            $nonce,
        ]));
        $context = new self(
            $identity,
            $lang !== '' ? $lang : 'zh_Hans_CN',
            $currency !== '' ? $currency : 'CNY',
            null,
            $fingerprint,
            false,
            $failureCode,
        );
        self::install($context);
        return $context;
    }

    /**
     * @return array{
     *   schema:string,scope_state:string,scope_kind:string,website:string,store:string,
     *   channel:string,store_mode:string,context_version:string,lang:string,currency:string,
     *   namespace_fingerprint:string,cache_key_fingerprint:string
     * }
     */
    public function keyDimensions(): array
    {
        $identity = $this->scopeIdentity;
        return [
            'schema' => self::SCHEMA_VERSION,
            'scope_state' => $this->cacheable ? 'frozen' : 'request-fence',
            'scope_kind' => $identity?->scopeKind ?? 'unresolved',
            'website' => $identity?->websiteCode ?? 'default',
            'store' => $identity?->storeCode ?? 'default',
            'channel' => $identity?->channelCode ?? 'default',
            'store_mode' => $identity?->storeMode ?? ScopeIdentity::MODE_NORMAL,
            'context_version' => $identity?->contextVersion ?? ScopeIdentity::CONTEXT_VERSION,
            'lang' => $this->lang,
            'currency' => $this->currency,
            'namespace_fingerprint' => $this->namespaceFingerprint ?? '',
            'cache_key_fingerprint' => $this->cacheKeyFingerprint,
        ];
    }

    public function hasCompleteFrozenScope(): bool
    {
        $identity = $this->scopeIdentity;
        return $this->cacheable
            && $identity instanceof ScopeIdentity
            && $identity->scopeKind === ScopeIdentity::KIND_CHANNEL
            && $identity->websiteId !== null
            && $identity->websiteCode !== null
            && $identity->storeCode !== null
            && $identity->channelCode !== null
            && $identity->storeMode !== null;
    }
}
