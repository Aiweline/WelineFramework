<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * Canonical storefront product-image placeholder.
 *
 * Hard rule: never emit `data:image/svg+xml;charset=…` (or any data URI) as media
 * `src`. Always use one shared static SVG file under view/statics so the browser
 * loads a single cacheable asset instead of inlining the same bytes hundreds of times.
 *
 * Pair with `data-storefront-img` + `data-fallback` and Theme `storefront-image-fallback.js`.
 */
final class StorefrontImagePlaceholder
{
    public const STATIC_DIR = 'images/storefront-placeholder';

    /** Single shared placeholder file (seed is API-compat only). */
    public const FILE = 'default.svg';

    /** @deprecated Use FILE; kept so callers that loop palette count do not break. */
    public const PALETTE_COUNT = 1;

    /** @var list<string> */
    private const UNUSABLE_REMOTE_FRAGMENTS = [
        'photo-1524484485831-a92aec687147',
        'photo-1625723044790-576b099b2b83',
        'photo-1605000796989-c3e7684c9a65',
        'photo-1558317374-873fb1538da2',
        'photo-1583947215250-4f8f9136f916',
        'photo-1598327105666-5b89351aff23',
        'photo-1565849906261-5a4c496227a9',
        'photo-1584622781865-329d5d683190',
        'photo-1615667243544-447c04d8f585',
        'photo-1592899677977-9b10ca588fab',
        'photo-1600166896080-3945215e8f8a',
    ];

    /**
     * Module static source key for templates (`@static` / getStaticUrl).
     *
     * @param int $seed Ignored; retained for call-site compatibility.
     */
    public static function assetSource(int $seed = 0): string
    {
        unset($seed);

        return 'Weline_Theme::' . self::STATIC_DIR . '/' . self::FILE;
    }

    /**
     * Root-relative URL to the one shared static SVG (browser-cacheable src).
     *
     * @param int $seed Ignored; retained for call-site compatibility.
     */
    public static function url(int $seed = 0): string
    {
        unset($seed);
        $relative = 'Weline/Theme/view/statics/' . self::STATIC_DIR . '/' . self::FILE;
        if (\defined('PROD') && PROD) {
            $theme = 'default';
            try {
                $cfg = \Weline\Framework\App\Env::getInstance()->getConfig('theme');
                if (\is_array($cfg)) {
                    $candidate = trim((string)($cfg['frontend'] ?? $cfg['frontend_theme'] ?? ''));
                    if ($candidate !== '') {
                        $theme = $candidate;
                    }
                }
            } catch (\Throwable) {
            }

            return '/static/' . $theme . '/' . $relative;
        }

        return '/' . $relative;
    }

    public static function isUsable(?string $image): bool
    {
        $image = trim((string)$image);
        if ($image === '') {
            return false;
        }
        // Forbidden for media: never accept inline data URIs as a usable image src.
        if (str_starts_with($image, 'data:image/')) {
            return false;
        }
        foreach (self::UNUSABLE_REMOTE_FRAGMENTS as $fragment) {
            if (str_contains($image, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{src:string,fallback:string}
     */
    public static function resolve(?string $image, int $seed = 0): array
    {
        $shared = self::url($seed);
        $image = trim((string)$image);
        if (!self::isUsable($image)) {
            return ['src' => $shared, 'fallback' => $shared];
        }

        return ['src' => $image, 'fallback' => $shared];
    }
}
