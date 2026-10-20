<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\State;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;

/**
 * HotCache bag for formal storefront blog post detail layout HTML (post hard-cut).
 *
 * ControllerFetchFileAfter peeks before renderLayoutChain; MISS remembers the
 * finalized layout. Logical key MUST include post slug (never layout-only).
 * Payloads reuse homepage gzip pack (HPLH1:) for Process L1 / FileAdapter headroom.
 * Request-path peek/remember only — no warm hydrate-all-posts.
 * Preview / editor bypass the bag.
 * Invalidation: CachePolicy depends on global/storefront/blog (BlogContentMutation bump).
 */
final class StorefrontBlogLayoutHtmlBag implements ProcessSharedInterface
{
    public const RESOURCE = 'theme.storefront_blog_layout_html';

    private const REQUEST_MEMO_PREFIX = 'theme.blog_layout_html.req.v1.';
    /** v6: dialog opens through the dialog component, including currency-only. */
    private const LOGICAL_KEY_PREFIX = 'theme.blog_layout.html.v7.';
    /** Same gzip envelope as homepage bag. */
    private const PACK_PREFIX = 'HPLH1:';

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return StorefrontThemeCacheCoordinator::storefrontBlogLayoutHtmlPolicy();
    }

    /**
     * Formal layouts/blog detail wrap only; requires resolvable post slug.
     * Never blog_category, never preview/editor.
     */
    public function isEligible(string $contentTemplate, string $layoutTemplate): bool
    {
        unset($contentTemplate);
        if (!$this->isBlogDetailLayout($layoutTemplate)) {
            return false;
        }
        if ($this->isPreviewOrEditorRequest()) {
            return false;
        }
        if ($this->resolvePostSlug() === null) {
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

        try {
            PostResponseTaskQueue::enqueue(
                'theme.blog_layout_html:' . \sha1($logicalKey),
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
                        \error_log('[BlogLayoutHtmlBag] post_response_seed_fail:' . $e->getMessage());
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

    public function logicalKey(string $layoutTemplate): string
    {
        $layout = \str_replace('\\', '/', $layoutTemplate);
        $option = 'default';
        if (\preg_match('#/layouts/blog/([^/]+)\.phtml$#', $layout, $m) === 1) {
            $option = \strtolower(\trim((string)$m[1]));
        } elseif (\str_ends_with($layout, '/layouts/blog.phtml')) {
            $option = 'default';
        }
        if ($option === '') {
            $option = 'default';
        }

        $slug = $this->resolvePostSlug() ?? '';
        $slugHash = $slug !== '' ? \substr(\sha1($slug), 0, 16) : 'missing';
        $rejected = \strtolower(\trim((string)\Weline\Framework\Runtime\RequestContext::get('route.rejected_path_language', '')));
        $rejectedSeg = $rejected !== '' ? \substr(\sha1($rejected), 0, 8) : 'langok';
        $rejectedCurrency = \strtolower(\trim((string)\Weline\Framework\Runtime\RequestContext::get('route.rejected_path_currency', '')));
        $rejectedCurrencySeg = $rejectedCurrency !== '' ? \substr(\sha1($rejectedCurrency), 0, 8) : 'curok';
        $localeSeg = $this->activeLocaleSegment();

        return self::LOGICAL_KEY_PREFIX
            . $this->themeIdentitySegment()
            . '.blog.'
            . $option
            . '.'
            . $slugHash
            . '.'
            . $localeSeg
            . '.'
            . $rejectedSeg
            . '.'
            . $rejectedCurrencySeg;
    }

    private function isBlogDetailLayout(string $layoutTemplate): bool
    {
        $layout = \str_replace('\\', '/', $layoutTemplate);
        // Exclude blog_category (path contains /layouts/blog_category/).
        if (\str_contains($layout, '/layouts/blog_category/')
            || \str_contains($layout, '::theme/frontend/layouts/blog_category')
        ) {
            return false;
        }

        return \str_contains($layout, '/layouts/blog/')
            || \str_ends_with($layout, '/layouts/blog.phtml')
            || \str_contains($layout, '::theme/frontend/layouts/blog/');
    }

    /**
     * Post slug identity for the bag key (Blog owns slug; Theme only reads it).
     */
    public function resolvePostSlug(): ?string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $slug = \strtolower(\trim((string)$request->getParam('slug', '')));
            if ($this->isUsablePostSlug($slug)) {
                return $slug;
            }
            $uri = (string)($request->getServer('REQUEST_URI') ?? $request->getUri() ?? '');
            $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: '');
            $path = \strtolower(\rawurldecode($path));
            // /blog/{slug} optionally prefixed by currency/locale segments.
            if (\preg_match('#(?:^|/)blog/([a-z0-9][a-z0-9\-]*)/?$#', $path, $m) === 1) {
                $fromPath = \strtolower(\trim((string)$m[1]));
                if ($this->isUsablePostSlug($fromPath)) {
                    return $fromPath;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (RequestContext::isInitialized()) {
                $ctx = \strtolower(\trim((string)RequestContext::get('blog.post.slug.v1', '')));
                if ($this->isUsablePostSlug($ctx)) {
                    return $ctx;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function isUsablePostSlug(string $slug): bool
    {
        if ($slug === '') {
            return false;
        }
        // Reserved public blog path segments (keep Theme free of Blog module coupling).
        if (\in_array($slug, ['category', 'rss', 'rss.xml', 'index', 'feed'], true)) {
            return false;
        }

        return (bool)\preg_match('#^[a-z0-9][a-z0-9\-]*$#', $slug);
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

        return \trim($payload) !== '' ? $payload : null;
    }

    private function themeIdentitySegment(): string
    {
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
     * Dialog copy is already translated into the cached HTML.
     * Rejected-path flags alone let two enabled languages share one bag.
     */
    private function activeLocaleSegment(): string
    {
        $lang = \strtolower(\str_replace('-', '_', \trim(
            \Weline\Framework\Runtime\RequestContext::getWelineUserLang()
        )));
        $lang = \preg_replace('/[^a-z0-9_]/', '', $lang) ?? '';

        return $lang !== '' ? $lang : 'nolang';
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
