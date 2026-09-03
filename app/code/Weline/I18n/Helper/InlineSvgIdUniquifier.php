<?php

declare(strict_types=1);

namespace Weline\I18n\Helper;

/**
 * Make inline SVG id/href unique per insert so the same flag can appear in
 * independently rendered Hook/FPC fragments without duplicate DOM ids.
 */
final class InlineSvgIdUniquifier
{
    private const NONCE_BYTES = 12;

    /**
     * @internal Backward-compatible lifecycle hook; uniqueness is stateless.
     */
    public static function resetSequence(): void
    {
    }

    public static function uniquify(string $markup): string
    {
        $markup = trim($markup);
        if ($markup === '' || !str_contains($markup, 'id=')) {
            return $markup;
        }

        if (preg_match_all('/\bid=(["\'])([^"\']+)\1/i', $markup, $matches) < 1) {
            return $markup;
        }

        $ids = array_values(array_unique($matches[2]));
        if ($ids === []) {
            return $markup;
        }

        // A process-local sequence restarts independently in each Worker and
        // collides when fragments from different renderers share one response.
        // A 96-bit namespace remains unique across those cache/process bounds.
        $suffix = '-u' . bin2hex(random_bytes(self::NONCE_BYTES));
        foreach ($ids as $id) {
            $quoted = preg_quote($id, '/');
            $newId = $id . $suffix;
            $markup = (string)preg_replace(
                '/\bid=(["\'])' . $quoted . '\1/i',
                'id=$1' . $newId . '$1',
                $markup
            );
            $markup = (string)preg_replace(
                '/\b((?:xlink:)?href)=(["\'])#' . $quoted . '\2/i',
                '$1=$2#' . $newId . '$2',
                $markup
            );
            $markup = str_replace('url(#' . $id . ')', 'url(#' . $newId . ')', $markup);
        }

        return $markup;
    }
}
