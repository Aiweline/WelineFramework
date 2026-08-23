<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * Read-only compatibility sanitizer for media URLs stored before FileAsset.
 *
 * New Theme/CMS data must use a typed file-image value. This helper exists
 * only so legacy rows can be rendered without turning an HTML-escaped value
 * into executable URL/CSS syntax.
 */
final class LegacyMediaUrl
{
    private const MAX_BYTES = 8192;

    public static function sanitize(mixed $value, bool $forCss = false): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $url = trim(html_entity_decode(
            (string)$value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
        if ($url === ''
            || strlen($url) > self::MAX_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
            || preg_match('/\\\\/', $url) === 1
            || ($forCss && preg_match('/["\']/', $url) === 1)
            || str_starts_with($url, '//')
        ) {
            return '';
        }

        if (str_starts_with($url, '#')) {
            return $forCss ? '' : $url;
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            if (!in_array($scheme, ['http', 'https'], true)
                || trim((string)($parts['host'] ?? '')) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return '';
            }
            return $url;
        }

        // A host without an explicit scheme is a scheme-relative URL in a
        // different spelling and is deliberately not accepted here.
        if (isset($parts['host'])) {
            return '';
        }

        return $url;
    }
}
