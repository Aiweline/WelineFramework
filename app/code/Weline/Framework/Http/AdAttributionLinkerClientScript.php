<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Inject the ad-attribution link linker (always on, cache-safe, no secret).
 *
 * Storefront links rendered server-side (categories, shops, compare, home)
 * carry no query, so a paid landing used to lose utm / gclid params on the
 * first internal click. The client script only reads this page's own
 * location.search and stamps the same-origin links; it stores nothing and
 * sends nothing, so the tag itself is identical for every visitor and safe
 * inside FPC / CDN cached HTML.
 */
final class AdAttributionLinkerClientScript
{
    public const MARKER_ATTR = 'data-weline-ad-attribution-linker';

    /**
     * Inject loader once before &lt;/body&gt; (idempotent; replaces stale tag).
     */
    public static function injectIntoHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        // Binary / compressed FPC bodies must not be scanned as HTML.
        if (\strncmp($html, 'WQB1', 4) === 0) {
            return $html;
        }

        $tag = self::renderScriptTag();
        if ($tag === '') {
            return $html;
        }

        if (\str_contains($html, self::MARKER_ATTR)) {
            $replaced = \preg_replace(
                '#<script\b[^>]*' . \preg_quote(self::MARKER_ATTR, '#') . '[^>]*>\s*</script>#i',
                $tag,
                $html,
                1
            );

            return \is_string($replaced) && $replaced !== '' ? $replaced : $html;
        }

        if (\stripos($html, '</body>') !== false) {
            return (string)\preg_replace('/<\/body>/i', $tag . '</body>', $html, 1);
        }

        return $html . $tag;
    }

    public static function renderScriptTag(): string
    {
        $src = self::publicScriptUrl();
        if ($src === '') {
            return '';
        }

        $esc = static fn (string $value): string => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<script src="' . $esc($src) . '" '
            . self::MARKER_ATTR . '="1" '
            . 'data-no-extract="true"></script>';
    }

    private static function publicScriptUrl(): string
    {
        $url = '';
        try {
            if (\class_exists(\Weline\Framework\View\PublicStaticUrl::class)) {
                $url = (string)\Weline\Framework\View\PublicStaticUrl::fromModuleSource(
                    'Weline_Framework::js/ad-attribution-linker.js'
                );
            }
        } catch (\Throwable) {
            $url = '';
        }
        if ($url === '') {
            $url = '/static/Weline/Framework/js/ad-attribution-linker.js';
        }

        $version = self::scriptFileVersion();
        if ($version === '') {
            return $url;
        }

        return \str_contains($url, '?') ? ($url . '&v=' . $version) : ($url . '?v=' . $version);
    }

    private static function scriptFileVersion(): string
    {
        $path = \dirname(__DIR__) . '/View/statics/js/ad-attribution-linker.js';
        if (!\is_file($path)) {
            return '';
        }
        $mtime = @\filemtime($path);

        return $mtime ? (string)$mtime : '';
    }
}
