<?php

declare(strict_types=1);

namespace Weline\Framework\View\Helper;

/**
 * Public copy slots vs internal module identifiers.
 *
 * Invariant: title / meta.title / meta_title / controller_title must never hold
 * Vendor_Module codes. Layouts may keep using $title after Template sanitizes.
 */
final class EmbeddedPageTitle
{
    private const PUBLIC_SLOT_KEYS = ['title', 'meta_title', 'controller_title'];

    public static function resolve(mixed $title, string $fallbackSourcePhrase): string
    {
        $title = trim((string) $title);
        if ($title === '' || self::isModulePlaceholder($title)) {
            return (string) __($fallbackSourcePhrase);
        }

        return $title;
    }

    /**
     * True for Vendor_Module style identifiers only (empty is not an identifier).
     */
    public static function isInternalIdentifier(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*$/', $value);
    }

    /**
     * Empty or internal module identifier — not safe as public copy.
     */
    public static function isModulePlaceholder(string $title): bool
    {
        $title = trim($title);

        return $title === '' || self::isInternalIdentifier($title);
    }

    /**
     * Clear public title slots that hold module identifiers (set to '').
     * Nested meta arrays are sanitized the same way.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function sanitizePublicSlots(array $data): array
    {
        foreach (self::PUBLIC_SLOT_KEYS as $key) {
            if (!\array_key_exists($key, $data)) {
                continue;
            }
            $value = \trim((string)$data[$key]);
            if ($value === '' || self::isInternalIdentifier($value)) {
                $data[$key] = '';
            }
        }

        if (isset($data['meta']) && \is_array($data['meta'])) {
            $data['meta'] = self::sanitizePublicSlots($data['meta']);
        }

        return $data;
    }

    /**
     * HTML is admissible for process caches when no document title / H1 is a
     * bare module code.
     */
    public static function htmlAdmissible(string $html): bool
    {
        return !self::htmlContainsModulePlaceholderTitle($html);
    }

    /**
     * @deprecated Prefer htmlAdmissible(); kept for call-site BC.
     */
    public static function htmlContainsModulePlaceholderTitle(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (\preg_match('/<title[^>]*>\s*[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*\s*<\/title>/i', $html) === 1) {
            return true;
        }

        return \preg_match(
            '/<h1\b[^>]*>\s*[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*\s*<\/h1>/i',
            $html
        ) === 1;
    }
}
