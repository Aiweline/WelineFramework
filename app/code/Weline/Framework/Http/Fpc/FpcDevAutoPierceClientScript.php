<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * When admin「链接自带签章」is on, inject a storefront script that stamps
 * same-origin navigational URLs (a[href]/form[action]/data-href) so widgets
 * that skip Url::getUrl (e.g. localizeHref('/category/...')) still pierce FPC.
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
        if ($html === '' || !self::isActive()) {
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
        if (!self::isActive()) {
            return '';
        }

        try {
            $secret = FpcPierceStamp::ensureSecret();
            $suffix = FpcPierceStamp::buildQuerySuffix($secret);
        } catch (\Throwable) {
            return '';
        }
        if ($secret === '' || $suffix === '') {
            return '';
        }

        $src = self::publicScriptUrl();
        if ($src === '') {
            return '';
        }

        $esc = static fn (string $value): string => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        // No defer: end-of-body placement already has full DOM; defer made
        // document.currentScript null and raced Bag/widget hydrate.
        return '<script src="' . $esc($src) . '" '
            . self::MARKER_ATTR . '="1" '
            . 'data-no-extract="true" '
            . 'data-pierce-query-name="' . $esc(FpcPierceStamp::QUERY_NAME) . '" '
            . 'data-pierce-secret="' . $esc($secret) . '" '
            . 'data-pierce-suffix="' . $esc($suffix) . '"'
            . '></script>';
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
