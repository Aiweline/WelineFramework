<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Design-theme images stay on the area publish path — they are not dual-written
 * into `/static/{ns}/Weline/Theme/view/theme/...` the way CSS/JS are.
 *
 * CSS packs may still live under the Theme-module URL namespace; relative
 * `url(../images/...)` rewrite must therefore land on
 * `/static/{ns}/{area}/assets/...` (original design address), not the module mirror.
 *
 * Also remaps legacy absolute `/Weline/Theme/view/theme/{area}/...` image URLs
 * (and the same with a `/static/{ns}` prefix), including query/hash suffixes.
 */
final class ThemeDesignImageStaticUrl
{
    /** @var list<string> */
    public const IMAGE_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'svg',
    ];

    public static function isImageExtension(string $extension): bool
    {
        $extension = strtolower(ltrim(trim($extension), '.'));

        return $extension !== '' && \in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Extract `/static/{Vendor}/{theme}` from a CSS leaf or pack URL when present.
     */
    public static function staticNamespaceFromUrl(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#^(/static/[^/]+/[^/]+)(?:/|$)#i', $path, $matches) === 1) {
            return rtrim((string)$matches[1], '/');
        }

        return '';
    }

    /**
     * `/static/{ns}/Weline/Theme/view/theme/{area}/{rel}` → `/static/{ns}/{area}/{rel}` for images.
     * Bare `/Weline/Theme/view/theme/{area}/{rel}` → `{nsHint}/{area}/{rel}` when hint given.
     * Non-image paths and already-area paths are returned unchanged (query/hash preserved).
     */
    public static function remapModuleThemePathToDesignArea(
        string $path,
        string $staticNamespaceHint = '',
    ): string {
        $raw = str_replace('\\', '/', trim($path));
        if ($raw === '') {
            return $path;
        }
        $suffix = '';
        if (preg_match('/([?#].*)$/', $raw, $suffixMatch) === 1) {
            $suffix = (string)$suffixMatch[1];
            $raw = substr($raw, 0, -strlen($suffix));
        }
        $normalized = '/' . ltrim($raw, '/');
        if ($normalized === '/') {
            return $path;
        }

        $area = '';
        $relative = '';
        $staticPrefix = '';

        if (preg_match(
            '#^(/static/.+)/Weline/Theme/view/theme/(frontend|backend)/(.+)$#i',
            $normalized,
            $matches,
        ) === 1) {
            $staticPrefix = rtrim((string)$matches[1], '/');
            $area = strtolower((string)$matches[2]) === 'backend' ? 'backend' : 'frontend';
            $relative = ltrim((string)$matches[3], '/');
        } elseif (preg_match(
            '#^/Weline/Theme/view/theme/(frontend|backend)/(.+)$#i',
            $normalized,
            $matches,
        ) === 1) {
            $area = strtolower((string)$matches[1]) === 'backend' ? 'backend' : 'frontend';
            $relative = ltrim((string)$matches[2], '/');
            $hint = rtrim(str_replace('\\', '/', trim($staticNamespaceHint)), '/');
            if ($hint !== '' && preg_match('#^/static/[^/]+/[^/]+$#i', $hint) === 1) {
                $staticPrefix = $hint;
            } else {
                // No publish namespace available — cannot invent /static/{ns}; leave unchanged.
                return $path;
            }
        } else {
            return $path;
        }

        $extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
        if (!self::isImageExtension($extension)) {
            return $path;
        }

        return $staticPrefix . '/' . $area . '/' . $relative . $suffix;
    }
}
