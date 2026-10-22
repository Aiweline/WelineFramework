<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * A stored category value is ignored when it is empty, a placeholder, or the store category's own name.
 */
final class FeedCategoryValue
{
    public static function real(string $vocabulary, string $value, string $storeName = ''): string
    {
        $value = trim($value);
        $storeName = trim($storeName);
        if ($value === '' || $value === '杂项' || strcasecmp($value, 'other') === 0 || $value === '0') {
            return '';
        }
        if ($storeName !== '' && $value === $storeName) {
            return '';
        }
        if ($vocabulary === FeedCategoryVocabulary::GOOGLE && !preg_match('/^[1-9][0-9]*$/', $value)) {
            return '';
        }

        return $value;
    }
}
