<?php

declare(strict_types=1);

namespace Weline\I18n\Helper;

/**
 * Make inline SVG id/href unique per insert so the same flag can appear twice
 * (language switcher trigger + menu row) without duplicate-id health warnings.
 */
final class InlineSvgIdUniquifier
{
    private static int $sequence = 0;

    /**
     * @internal tests may reset between cases
     */
    public static function resetSequence(): void
    {
        self::$sequence = 0;
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

        $suffix = '-u' . (++self::$sequence);
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
