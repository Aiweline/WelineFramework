<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/** Normalizes Widget editor values without introducing site-specific demo content. */
final class SiteBlockConfig
{
    public static function text(mixed $value, string $fallback = ''): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? trim((string)$value) : $fallback;
    }

    /** @return list<array> */
    public static function items(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    public static function boolean(mixed $value, bool $fallback = false): bool
    {
        return $value === null ? $fallback : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback);
    }

    public static function choice(mixed $value, array $choices, string $fallback): string
    {
        $value = self::text($value);
        return in_array($value, $choices, true) ? $value : $fallback;
    }

    public static function length(mixed $value, string $fallback): string
    {
        $value = self::text($value);
        return preg_match('/^(?:0|[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vh|vw|svh|lvh|dvh)|var\(--[a-zA-Z0-9_-]+\))$/D', $value) === 1 ? $value : $fallback;
    }

    public static function link(mixed $value): string
    {
        $value = self::text($value);
        if (str_starts_with($value, 'mailto:')) {
            $email = substr($value, 7);
            return filter_var($email, FILTER_VALIDATE_EMAIL) && !str_contains($email, '%') ? 'mailto:' . $email : '';
        }
        if (str_starts_with($value, 'tel:')) {
            return preg_match('/^tel:\+?[0-9 ()-]+$/D', $value) === 1 ? $value : '';
        }
        return LegacyMediaUrl::sanitize($value);
    }

    /** Override per-use alt text on trusted, hydrated FileAsset HTML; keep its sources and attributes. */
    public static function mediaHtml(mixed $html, mixed $alt = null): string
    {
        $html = self::text($html);
        $alt = self::text($alt);
        if ($html === '' || $alt === '') {
            return $html;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            // PHP 8.4 is the framework baseline. HTML5 parsing preserves picture/source void semantics.
            $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>', 0, 'UTF-8');
            foreach ($document->getElementsByTagName('img') as $image) {
                $image->setAttribute('alt', $alt);
            }
            $body = $document->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return $html;
            }
            $result = '';
            foreach ($body->childNodes as $node) {
                $result .= $document->saveHtml($node);
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars(self::text($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
