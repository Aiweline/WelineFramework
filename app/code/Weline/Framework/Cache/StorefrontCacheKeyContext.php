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

    public string $defaultLocale;
    /** @var list<string> Ordered lookup chain; namespace paths may sort separately. */
    public array $translationLocales;

    public function __construct(
        public ?ScopeIdentity $scopeIdentity,
        public string $lang,
        public string $currency,
        public ?string $namespaceFingerprint,
        public string $cacheKeyFingerprint,
        public bool $cacheable,
        public string $failureCode = '',
        string $defaultLocale = '',
        ?array $translationLocales = null,
    ) {
        $this->defaultLocale = \Weline\Framework\Phrase\LocaleFallbackChain::normalize(
            $defaultLocale !== '' ? $defaultLocale : \Weline\Framework\Phrase\LocaleFallbackChain::websiteDefaultLocale(),
        );
        $this->translationLocales = $translationLocales
            ?? \Weline\Framework\Phrase\LocaleFallbackChain::candidates($lang, $this->defaultLocale);
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
            self::reportFence($failureCode, null, $seed !== '');

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
        // Website detection runs before Store/Channel resolution. Preserve that
        // authoritative website boundary so structural website resources (for
        // example the Store catalog) can be shared across workers while the
        // remaining navigation scope is still being resolved. Only accept the
        // default website with id 0; a custom website must have a positive id.
        if (!$identity instanceof ScopeIdentity) {
            $websiteId = RequestContext::getWelineWebsiteId();
            $websiteCode = trim(RequestContext::getWelineWebsiteCode());
            if ($websiteCode !== ''
                && ($websiteId > 0 || $websiteCode === 'default')
            ) {
                try {
                    $identity = ScopeIdentity::website($websiteId, $websiteCode);
                } catch (\Throwable) {
                    $identity = null;
                }
            }
        }
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
        self::reportFence($failureCode, $identity, true);
        self::install($context);
        return $context;
    }

    /**
     * 记录一次「请求栅栏」降级。
     *
     * 这是「FPC payload 持续增长但同一 URL 永远 MISS」的成因：栅栏指纹
     * 掺入的是稳定输入，但每个请求都会重新装配一次，且 cacheable=false，
     * 于是键逐请求变化、写入无法被后续请求读回。该降级不产生任何错误日志，
     * 因此必须显式埋点（`__fpcdiag=1` 或 `var/fpc-diag.on` 启用，默认零开销）。
     */
    private static function reportFence(string $failureCode, ?ScopeIdentity $identity, bool $freshFingerprint): void
    {
        \Weline\Framework\Router\FpcDiag::event('storefront_context_fence', [
            'failure_code' => $failureCode,
            'cacheable' => false,
            'fresh_fingerprint' => $freshFingerprint,
            'context_scope_identity' => $identity instanceof ScopeIdentity,
            'scope_kind' => $identity?->scopeKind,
            'website_code' => $identity?->websiteCode,
            'store_code' => $identity?->storeCode,
            'channel_code' => $identity?->channelCode,
            'request_scope_identity' => RequestContext::scopeIdentity() instanceof ScopeIdentity,
            'lang' => trim(WelineEnv::getLang()),
            'currency' => trim(WelineEnv::getCurrency()),
            // 谁先触发了栅栏决定了整个请求的缓存键，必须能定位到调用点。
            'caller_chain' => self::callerChain(),
        ]);
    }

    /**
     * 精简调用链（只保留 类::方法 / 函数，不含参数），用于定位是谁在作用域就绪前
     * 提前触发了栅栏并锁死本请求的 FPC 缓存键。
     *
     * @return list<string>
     */
    private static function callerChain(): array
    {
        $chain = [];
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $fn = (string)($frame['function'] ?? '');
            if ($fn === '' || $fn === 'reportFence' || $fn === 'currentOrRequestFence') {
                continue;
            }
            $class = (string)($frame['class'] ?? '');
            $type = (string)($frame['type'] ?? '');
            $chain[] = $class !== '' ? $class . $type . $fn : $fn;
            if (\count($chain) >= 8) {
                break;
            }
        }

        return $chain;
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
            'default_locale' => $this->defaultLocale,
            'translation_locales' => implode(',', $this->translationLocales),
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

    /**
     * Website resolution is authoritative before Store/Channel resolution.
     * This is deliberately weaker than a frozen storefront scope and is only
     * suitable for policies whose declared scope is exactly Website.
     */
    public function hasWebsiteScope(): bool
    {
        $identity = $this->scopeIdentity;

        return $identity instanceof ScopeIdentity
            && $identity->scopeKind !== ScopeIdentity::KIND_GLOBAL
            && $identity->websiteId !== null
            && $identity->websiteCode !== null;
    }
}
