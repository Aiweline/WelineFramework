<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/**
 * Storefront compare semantics for an EAV attribute definition.
 */
final class CompareMode
{
    public const NONE = 'none';
    public const DIFF = 'diff';
    public const HIGHER_BETTER = 'higher_better';
    public const LOWER_BETTER = 'lower_better';

    /** @var list<string> */
    public const ALL = [
        self::NONE,
        self::DIFF,
        self::HIGHER_BETTER,
        self::LOWER_BETTER,
    ];

    public static function normalize(mixed $value): string
    {
        $mode = strtolower(trim((string)$value));
        if (!in_array($mode, self::ALL, true)) {
            return self::NONE;
        }

        return $mode;
    }

    public static function isValid(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), self::ALL, true);
    }
}
