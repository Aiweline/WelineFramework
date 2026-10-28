<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * Inject a storefront script that stamps same-origin navigational URLs
 * (a[href]/form[action]/data-href) so widgets that skip Url::getUrl
 * (e.g. localizeHref('/category/...')) still pierce FPC.
 *
 * Two modes:
 * - self-detect (always): the loader carries no secret and the client reads the
 *   stamp from its own location.search, so the tag is cache-safe and a pierced
 *   visit keeps propagating even when the HTML came from an FPC/CDN cache.
 * - admin「链接自带签章」: the loader also carries data-pierce-secret/suffix so
 *   freshly rendered pages are stamped before the first navigation.
 */
final class FpcDevAutoPierceClientScript
{
    public const MARKER_ATTR = 'data-weline-fpc-dev-auto-pierce';

    public static function isActive(): bool
    {
        try {
            return FpcMode::isDevAutoPierceEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Inject loader + config once before &lt;/body&gt; (idempotent).
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

        // Replace stale loader (e.g. Bag/FPC HTML with older script URL) instead of no-op.
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

        $attrs = 'src="' . $esc($src) . '" '
            . self::MARKER_ATTR . '="1" '
            . 'data-no-extract="true" '
            . 'data-pierce-query-name="' . $esc(FpcPierceStamp::QUERY_NAME) . '"';

        // Secret only travels in admin「链接自带签章」mode; that mode bypasses FPC.
        // Self-detect mode must stay secret-free so the tag is safe in cached HTML.
        if (self::isActive()) {
            try {
                $secret = FpcPierceStamp::ensureSecret();
                $suffix = FpcPierceStamp::buildQuerySuffix($secret);
            } catch (\Throwable) {
                $secret = '';
                $suffix = '';
            }
            if ($secret !== '' && $suffix !== '') {
                $attrs .= ' data-pierce-secret="' . $esc($secret) . '"'
                    . ' data-pierce-suffix="' . $esc($suffix) . '"';
            }
        }

        // No defer: end-of-body placement already has full DOM; defer made
        // document.currentScript null and raced Bag/widget hydrate.
        return '<script ' . $attrs . '></script>';
    }

    private static function publicScriptUrl(): string
    {
        $url = '';
        try {
            if (\class_exists(\Weline\Framework\View\PublicStaticUrl::class)) {
                $url = (string)\Weline\Framework\View\PublicStaticUrl::fromModuleSource(
                    'Weline_Framework::js/fpc-dev-auto-pierce.js'
                );
            }
        } catch (\Throwable) {
            $url = '';
        }
        if ($url === '') {
            $url = '/static/Weline/Framework/js/fpc-dev-auto-pierce.js';
        }

        $version = self::scriptFileVersion();
        if ($version === '') {
            return $url;
        }

        return \str_contains($url, '?') ? ($url . '&v=' . $version) : ($url . '?v=' . $version);
    }

    private static function scriptFileVersion(): string
    {
        $path = \dirname(__DIR__, 2) . '/View/statics/js/fpc-dev-auto-pierce.js';
        if (!\is_file($path)) {
            return '';
        }
        $mtime = @\filemtime($path);

        return $mtime ? (string)$mtime : '';
    }
}
