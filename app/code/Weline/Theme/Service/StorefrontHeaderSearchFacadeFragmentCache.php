<?php

declare(strict_types=1);

namespace Weline\Theme\Service;


use Weline\Framework\Env\WelineEnv;
use Weline\Framework\App\State;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\HeaderNavFragment;

/**
 * Scope-hot HTML for the header search facade (header-bar partial).
 *
 * Shared bag is selection-free (empty q, type=all, category=0). Request-local
 * q / type / category are painted after HIT so chrome SharedBag hydrate does
 * not re-SSR the full bar on every request.
 */
final class StorefrontHeaderSearchFacadeFragmentCache implements ProcessSharedInterface
{
    private const REQUEST_MEMO_PREFIX = 'theme.header.search_facade.req.v1.';
    private const REQUEST_ORIGIN_MEMO_KEY = 'theme.header.search_facade.origin_segment.v1';
    private const REQUEST_LOCALE_MEMO_KEY = 'theme.header.search_facade.locale_segment.v1';

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return StorefrontThemeCacheCoordinator::headerSearchFacadePolicy();
    }

    public static function cachePool(): string
    {
        return StorefrontThemeCacheCoordinator::STOREFRONT_CHROME_POOL;
    }

    /**
     * @param callable():string $builder Must render stable_fragment header-bar HTML.
     */
    public function rememberFacadeHtml(
        string $panelId,
        string $placeholderSource,
        bool $showHotWords,
        callable $builder,
    ): string {
        $logicalKey = $this->logicalKey($panelId, $placeholderSource, $showHotWords);
        $memoKey = self::REQUEST_MEMO_PREFIX . $logicalKey;
        try {
            $memo = RequestContext::get($memoKey);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable) {
        }

        $html = $this->hotCache->rememberPolicy(
            self::cachePolicy(),
            $logicalKey,
            static function () use ($builder): string {
                $rendered = $builder();

                return \is_string($rendered) ? $rendered : '';
            },
        );
        $html = \is_string($html) ? $html : '';
        if ($html !== '') {
            try {
                RequestContext::set($memoKey, $html);
            } catch (\Throwable) {
            }
        }

        return $html;
    }

    /**
     * Paint request-local query + type/category onto a stable facade snapshot.
     */
    public function applyRequestLocalFacade(
        string $html,
        string $query,
        string $selectedType,
        int $selectedCategoryId,
    ): string {
        if ($html === '') {
            return $html;
        }

        $query = \trim($query);
        $escQuery = \htmlspecialchars($query, \ENT_QUOTES, 'UTF-8');
        $replaced = \preg_replace(
            '/(<input\b[^>]*\bname="q"[^>]*\bvalue=")[^"]*(")/i',
            '${1}' . $escQuery . '${2}',
            $html,
            1,
        );
        if (\is_string($replaced)) {
            $html = $replaced;
        }

        return HeaderNavFragment::applySearchTypeDropdownSelection(
            $html,
            $selectedType,
            $selectedCategoryId,
        );
    }

    public function logicalKey(string $panelId, string $placeholderSource, bool $showHotWords): string
    {
        $panelId = \trim($panelId);
        if ($panelId === '') {
            $panelId = 'header-search-panel';
        }
        $panelSlug = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower($panelId)) ?: 'panel';
        $placeholderFp = \substr(\sha1(\trim($placeholderSource)), 0, 12);

        return \sprintf(
            'theme.header.search_facade.v1.%s.%s.%s.%s.%s',
            $this->storefrontLocaleSegment(),
            $this->requestOriginSegment(),
            $panelSlug,
            $showHotWords ? 'hot1' : 'hot0',
            $placeholderFp,
        );
    }

    private function storefrontLocaleSegment(): string
    {
        try {
            $memo = RequestContext::get(self::REQUEST_LOCALE_MEMO_KEY);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable) {
        }

        $locale = 'default';
        try {
            $locale = \trim((string)WelineEnv::getLangLocal());
            if ($locale === '') {
                $locale = 'default';
            }
        } catch (\Throwable) {
            $locale = 'default';
        }
        $segment = \preg_replace('/[^a-zA-Z0-9_-]+/', '-', $locale) ?: 'default';
        try {
            RequestContext::set(self::REQUEST_LOCALE_MEMO_KEY, $segment);
        } catch (\Throwable) {
        }

        return $segment;
    }

    private function requestOriginSegment(): string
    {
        try {
            $memo = RequestContext::get(self::REQUEST_ORIGIN_MEMO_KEY);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable) {
        }

        $origin = '';
        try {
            if (\class_exists(Url::class) && \method_exists(Url::class, 'getBaseUrl')) {
                $origin = \trim((string)Url::getBaseUrl());
            }
        } catch (\Throwable) {
            $origin = '';
        }
        if ($origin === '') {
            $origin = 'origin';
        }
        $segment = \substr(\sha1($origin), 0, 12);
        try {
            RequestContext::set(self::REQUEST_ORIGIN_MEMO_KEY, $segment);
        } catch (\Throwable) {
        }

        return $segment;
    }
}
