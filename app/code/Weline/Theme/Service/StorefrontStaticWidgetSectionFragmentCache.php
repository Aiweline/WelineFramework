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
use Weline\Theme\Service\ThemeContextService;

/**
 * Scope-hot HTML for guest-safe homepage content widgets.
 *
 * Whitelist: design-theme content widgets, video-carousel, image-gallery.
 * Profiles:
 * - {@see PROFILE_STATIC}: long-lived config HTML (textile / video / gallery)
 * - {@see PROFILE_LOOKS}: buyer-looks walls; TTL mirrors Review buyer_looks; fingerprint must include assembled items
 * Preview mode bypasses the bag.
 */
final class StorefrontStaticWidgetSectionFragmentCache implements ProcessSharedInterface
{
    public const ALLOWED_CODES = [
        'textile-heritage' => true,
        'video-carousel' => true,
        'image-gallery' => true,
    ];

    public const PROFILE_STATIC = 'static';
    public const PROFILE_LOOKS = 'looks';

    private const REQUEST_MEMO_PREFIX = 'theme.static_widget_section.req.v1.';
    private const REQUEST_ORIGIN_MEMO_KEY = 'theme.static_widget_section.origin_segment.v1';
    private const REQUEST_LOCALE_MEMO_KEY = 'theme.static_widget_section.locale_segment.v1';
    private const REQUEST_THEME_MEMO_KEY = 'theme.static_widget_section.theme_segment.v1';

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return self::cachePolicyFor(self::PROFILE_STATIC);
    }

    public static function cachePolicyFor(string $profile): CachePolicy
    {
        $profile = \strtolower(\trim($profile));
        if ($profile === self::PROFILE_LOOKS) {
            return StorefrontThemeCacheCoordinator::looksWidgetSectionHtmlPolicy();
        }

        return StorefrontThemeCacheCoordinator::staticWidgetSectionHtmlPolicy();
    }

    public static function isAllowed(string $widgetCode): bool
    {
        return isset(self::ALLOWED_CODES[\trim($widgetCode)]);
    }

    /**
     * @param array<string, mixed> $configParts Values that change rendered HTML.
     */
    public function configFingerprint(array $configParts): string
    {
        return \substr(\sha1((string)\json_encode($configParts, JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * @param callable():string $builder
     */
    public function rememberSectionHtml(
        string $widgetCode,
        string $configHash,
        callable $builder,
        string $profile = self::PROFILE_STATIC,
    ): string {
        $widgetCode = \trim($widgetCode);
        if (!self::isAllowed($widgetCode)) {
            $rendered = $builder();

            return \is_string($rendered) ? $rendered : '';
        }

        $profile = \strtolower(\trim($profile));
        if ($profile !== self::PROFILE_LOOKS) {
            $profile = self::PROFILE_STATIC;
        }

        $configHash = \trim($configHash);
        if ($configHash === '') {
            $configHash = 'empty';
        }

        $logicalKey = $this->logicalKey($widgetCode, $configHash, $profile);
        $memoKey = self::REQUEST_MEMO_PREFIX . $logicalKey;
        try {
            $memo = RequestContext::get($memoKey);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable) {
        }

        $html = $this->hotCache->rememberPolicy(
            self::cachePolicyFor($profile),
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

    public function logicalKey(
        string $widgetCode,
        string $configHash,
        string $profile = self::PROFILE_STATIC,
    ): string {
        $codeSlug = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower(\trim($widgetCode))) ?: 'widget';
        $profile = \strtolower(\trim($profile)) === self::PROFILE_LOOKS
            ? self::PROFILE_LOOKS
            : self::PROFILE_STATIC;

        return \sprintf(
            'theme.section.%s.%s.v1.%s.%s.%s.%s',
            $codeSlug,
            $profile,
            $this->themeSegment(),
            $this->storefrontLocaleSegment(),
            $this->requestOriginSegment(),
            \trim($configHash) !== '' ? \trim($configHash) : 'empty',
        );
    }

    private function themeSegment(): string
    {
        try {
            $memo = RequestContext::get(self::REQUEST_THEME_MEMO_KEY);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable) {
        }

        $theme = 'default';
        try {
            /** @var ThemeContextService $ctx */
            $ctx = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeContextService::class);
            $active = $ctx->getDirectActiveTheme('frontend');
            if ($active !== null) {
                $name = \trim((string)$active->getPath());
                if ($name === '') {
                    $name = \trim((string)$active->getName());
                }
                if ($name !== '') {
                    $theme = \preg_replace('/[^a-zA-Z0-9_\/-]+/', '-', $name) ?: 'default';
                    $theme = \str_replace('/', '-', $theme);
                }
            }
        } catch (\Throwable) {
            $theme = 'default';
        }
        try {
            RequestContext::set(self::REQUEST_THEME_MEMO_KEY, $theme);
        } catch (\Throwable) {
        }

        return $theme;
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
