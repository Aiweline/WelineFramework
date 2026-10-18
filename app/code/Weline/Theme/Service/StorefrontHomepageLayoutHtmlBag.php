<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\State;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Websites\Model\Website;

/**
 * HotCache bag for formal storefront homepage layout HTML (post-wrap).
 *
 * ControllerFetchFileAfter peeks before renderLayoutChain; MISS remembers the
 * rendered layout. Payloads are gzip-packed so Process L1 (256KiB entry cap)
 * and FileAdapter post-SSR memory headroom can accept ~0.7MB HTML.
 * Shared write is also deferred via PostResponse Scope (SWR family) when the
 * in-request write is refused under memory pressure.
 * Layer B hydrate peeks Shared → Process only (no SSR/seed).
 * Preview / editor bypass the bag.
 */
final class StorefrontHomepageLayoutHtmlBag implements ProcessSharedInterface
{
    public const RESOURCE = 'theme.storefront_homepage_layout_html';

    private const REQUEST_MEMO_PREFIX = 'theme.homepage_layout_html.req.v1.';
    /** v2: payload is post hard-cut finalize (safety_net complete). */
    private const LOGICAL_KEY_PREFIX = 'theme.homepage_layout.html.v2.';
    /** Gzip envelope marker (ASCII — avoids null-byte FileAdapter edge cases). */
    private const PACK_PREFIX = 'HPLH1:';

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return StorefrontThemeCacheCoordinator::storefrontHomepageLayoutHtmlPolicy();
    }

    /**
     * Formal layouts/homepage wrap only; controller content template is irrelevant.
     * Never preview/editor.
     * Path-locale storefront prefixes (e.g. /bn_BD) bypass the bag: HotCache vary is
     * lang+currency only, so a request whose State lang has not yet matched the URI
     * prefix would otherwise HIT the default-lang homepage HTML and skip layout
     * pathLocale SERP packs (GSC indexes those prefixes).
     */
    public function isEligible(string $contentTemplate, string $layoutTemplate): bool
    {
        unset($contentTemplate);
        $layout = \str_replace('\\', '/', $layoutTemplate);
        if (!\str_contains($layout, '/layouts/homepage/')
            && !\str_ends_with($layout, '/layouts/homepage.phtml')
            && !\str_contains($layout, '::theme/frontend/layouts/homepage')
        ) {
            return false;
        }
        if ($this->isPreviewOrEditorRequest()) {
            return false;
        }
        if ($this->requestHasStorefrontPathLocalePrefix()) {
            return false;
        }

        return true;
    }

    public function peekHtml(string $contentTemplate, string $layoutTemplate): ?string
    {
        if (!$this->isEligible($contentTemplate, $layoutTemplate)) {
            return null;
        }
        $logicalKey = $this->logicalKey($layoutTemplate);
        $memoKey = self::REQUEST_MEMO_PREFIX . $logicalKey;
        try {
            if (RequestContext::isInitialized() && RequestContext::has($memoKey)) {
                $memo = RequestContext::get($memoKey);
                if (\is_string($memo) && $memo !== '') {
                    return $memo;
                }
            }
        } catch (\Throwable) {
        }

        $peeked = $this->hotCache->peekPolicy(self::cachePolicy(), $logicalKey);
        $html = $this->unpackPayload($peeked);
        if ($html === null || $html === '') {
            return null;
        }
        try {
            if (RequestContext::isInitialized()) {
                RequestContext::set($memoKey, $html);
            }
        } catch (\Throwable) {
        }

        return $html;
    }

    /**
     * @param callable():string $builder
     */
    public function rememberHtml(string $contentTemplate, string $layoutTemplate, callable $builder): string
    {
        if (!$this->isEligible($contentTemplate, $layoutTemplate)) {
            $rendered = $builder();

            return \is_string($rendered) ? $rendered : '';
        }

        $logicalKey = $this->logicalKey($layoutTemplate);
        $memoKey = self::REQUEST_MEMO_PREFIX . $logicalKey;
        $policy = self::cachePolicy();

        $existing = $this->unpackPayload($this->hotCache->peekPolicy($policy, $logicalKey));
        if ($existing !== null && $existing !== '') {
            try {
                if (RequestContext::isInitialized()) {
                    RequestContext::set($memoKey, $existing);
                }
            } catch (\Throwable) {
            }

            return $existing;
        }

        $rendered = $builder();
        $html = \is_string($rendered) ? $rendered : '';
        if ($html === '') {
            return '';
        }

        $packed = $this->packPayload($html);
        // In-request remember (Process L1 when packed ≤256KiB; Shared may refuse under
        // FileAdapter headroom). Builder returns packed bytes already rendered.
        try {
            $this->hotCache->rememberPolicy(
                $policy,
                $logicalKey,
                static function () use ($packed): string {
                    return $packed;
                },
            );
        } catch (\Throwable) {
        }

        // Post-response Scope seed: durable Shared after SSR memory pressure clears.
        try {
            // Immediate post-response Scope seed (workers have ≥1GiB limit; gzip
            // packed payload ~80–150KiB). Also keep in-request remember for Process L1.
            PostResponseTaskQueue::enqueue(
                'theme.homepage_layout_html:' . \sha1($logicalKey),
                static function () use ($policy, $logicalKey, $packed): void {
                    try {
                        if (\function_exists('gc_collect_cycles')) {
                            \gc_collect_cycles();
                        }
                        /** @var StorefrontScopeHotCache $hot */
                        $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
                        $hot->rememberPolicy(
                            $policy,
                            $logicalKey,
                            static function () use ($packed): string {
                                return $packed;
                            },
                        );
                    } catch (\Throwable $e) {
                        \error_log('[HomepageLayoutHtmlBag] post_response_seed_fail:' . $e->getMessage());
                    }
                },
                0.0,
                PostResponseCaptureMode::Scope,
            );
        } catch (\Throwable) {
        }

        try {
            if (RequestContext::isInitialized()) {
                RequestContext::set($memoKey, $html);
            }
        } catch (\Throwable) {
        }

        return $html;
    }

    /**
     * Layer B: Shared → Process peek only (no builder / no seed).
     *
     * @return array{hit:bool,peeked:int,bags:list<string>,errors:list<string>}
     */
    public function hydrateProcessFromShared(string $layoutOption = 'default'): array
    {
        $out = ['hit' => false, 'peeked' => 0, 'bags' => [], 'errors' => []];
        try {
            if ($this->isPreviewOrEditorRequest()) {
                $out['errors'][] = self::RESOURCE . ':preview_bypass';

                return $out;
            }
            $layoutTemplate = 'Weline_Theme::theme/frontend/layouts/homepage/'
                . ($layoutOption !== '' ? $layoutOption : 'default')
                . '.phtml';
            $logicalKey = $this->logicalKey($layoutTemplate);
            $policy = self::cachePolicy();
            $peeked = $this->hotCache->peekPolicy($policy, $logicalKey);
            if ($this->unpackPayload($peeked) === null) {
                foreach ($this->hydrateCurrencyCandidates() as $currency) {
                    try {
                        RequestContext::setWelineUserCurrency($currency);
                        RequestContext::remove('framework.cache.storefront_key_context.v2');
                        ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class)
                            ->freezeCurrent();
                    } catch (\Throwable) {
                        continue;
                    }
                    $peeked = $this->hotCache->peekPolicy($policy, $logicalKey);
                    if ($this->unpackPayload($peeked) !== null) {
                        break;
                    }
                }
            }
            if ($this->unpackPayload($peeked) === null) {
                $out['errors'][] = self::RESOURCE . ':shared_miss';

                return $out;
            }
            $out['hit'] = true;
            $out['peeked'] = 1;
            $out['bags'][] = self::RESOURCE;

            return $out;
        } catch (\Throwable $e) {
            $out['errors'][] = self::RESOURCE . ':' . $e->getMessage();

            return $out;
        }
    }

    public function logicalKey(string $layoutTemplate): string
    {
        $layout = \str_replace('\\', '/', $layoutTemplate);
        $option = 'default';
        if (\preg_match('#/layouts/homepage/([^/]+)\.phtml$#', $layout, $m) === 1) {
            $option = \strtolower(\trim((string)$m[1]));
        } elseif (\str_ends_with($layout, '/layouts/homepage.phtml')) {
            $option = 'default';
        }
        if ($option === '') {
            $option = 'default';
        }

        return self::LOGICAL_KEY_PREFIX
            . $this->themeIdentitySegment()
            . '.homepage.'
            . $option;
    }

    private function packPayload(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (\str_starts_with($html, self::PACK_PREFIX)) {
            return $html;
        }
        if (!\function_exists('gzcompress')) {
            return $html;
        }
        $gz = \gzcompress($html, 6);
        if (!\is_string($gz) || $gz === '') {
            return $html;
        }

        return self::PACK_PREFIX . $gz;
    }

    private function unpackPayload(mixed $payload): ?string
    {
        if (!\is_string($payload) || $payload === '') {
            return null;
        }
        if (\str_starts_with($payload, self::PACK_PREFIX)) {
            if (!\function_exists('gzuncompress')) {
                return null;
            }
            $raw = \substr($payload, \strlen(self::PACK_PREFIX));
            $html = \gzuncompress($raw);

            return \is_string($html) && $html !== '' ? $html : null;
        }

        // Legacy uncompressed HTML (pre-pack) still accepted.
        return \trim($payload) !== '' ? $payload : null;
    }

    private function themeIdentitySegment(): string
    {
        // Logical key stays on theme id only. Publish/version bumps ride the
        // CachePolicy `theme` dependency fingerprint + projection-pool purge —
        // embedding V/R here forked hydrate vs live keys (t3 vs t3.vN.formal).
        try {
            $snapshot = ThemeLayoutSourceSnapshot::current();
            if ($snapshot !== null && $snapshot->identity !== null) {
                $id = (int)$snapshot->identity->themeId;
                if ($id > 0) {
                    return 't' . $id;
                }
            }
        } catch (\Throwable) {
        }

        try {
            /** @var ThemeContextService $ctx */
            $ctx = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $ctx->resolveTheme('frontend', null, false);
            if (\is_object($theme) && \method_exists($theme, 'getId')) {
                $id = (int)$theme->getId();
                if ($id > 0) {
                    return 't' . $id;
                }
            }
        } catch (\Throwable) {
        }

        return 't0';
    }

    /**
     * @return list<string>
     */
    private function hydrateCurrencyCandidates(): array
    {
        $candidates = ['USD'];
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website = (clone $website)->load(Website::ID_DEFAULT, null, true);
            $default = \strtoupper(\trim((string)($website->getDefaultCurrency() ?? '')));
            if ($default !== '') {
                $candidates[] = $default;
            }
        } catch (\Throwable) {
        }
        try {
            $current = \strtoupper(\trim((string)RequestContext::getWelineUserCurrency()));
            if ($current !== '') {
                $candidates[] = $current;
            }
        } catch (\Throwable) {
        }

        return \array_values(\array_unique($candidates));
    }

    /**
     * True when the visitor URI starts with a storefront locale segment (bn_BD, ar_SA, …).
     * Prefer WELINE_ORIGIN_REQUEST_URI / WELINE_FULL_REQUEST_URI — Framework may rewrite
     * REQUEST_URI to the canonical (locale-stripped) path before layout bag peek.
     */
    private function requestHasStorefrontPathLocalePrefix(): bool
    {
        $candidates = [];
        try {
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_VISITOR_REQUEST_URI', '');
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '');
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
            $candidates[] = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        } catch (\Throwable) {
        }
        $candidates[] = (string)($_SERVER['WELINE_VISITOR_REQUEST_URI'] ?? '');
        $candidates[] = (string)($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? '');
        $candidates[] = (string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
        $candidates[] = (string)($_SERVER['REQUEST_URI'] ?? '');

        foreach ($candidates as $uri) {
            $uri = \trim($uri);
            if ($uri === '') {
                continue;
            }
            $path = \parse_url($uri, \PHP_URL_PATH);
            if (!\is_string($path) || $path === '') {
                $path = $uri;
            }
            $q = \strpos($path, '?');
            if ($q !== false) {
                $path = \substr($path, 0, $q);
            }
            if (\preg_match('#^/([a-z]{2}(?:_[A-Za-z]{2,4}){1,2})(?:/|$)#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isPreviewOrEditorRequest(): bool
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $editorMode = \trim((string)$request->getParam('editor_mode', ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return true;
            }
            $preview = \trim((string)$request->getParam('preview', ''));
            if ($preview === '1' || \strtolower($preview) === 'true') {
                return true;
            }
            $status = \strtolower(\trim((string)$request->getParam('status', '')));
            if ($status === 'draft' || $status === 'preview') {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if (RequestContext::isInitialized()) {
                $mode = \strtolower(\trim((string)RequestContext::get('theme.store_mode', '')));
                if ($mode === 'preview' || $mode === 'draft') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (State::isBackend()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
