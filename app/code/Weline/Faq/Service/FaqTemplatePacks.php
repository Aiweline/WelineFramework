<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

/**
 * Single source for PDP FAQ template pack codes (seeds + SystemConfig options).
 */
final class FaqTemplatePacks
{
    public const RETAIL = 'retail';
    public const CROSS_BORDER = 'cross_border';
    public const VIRTUAL = 'virtual';
    public const B2B = 'b2b';

    public const DEFAULT = self::RETAIL;

    /** @return list<string> */
    public static function codes(): array
    {
        return [
            self::RETAIL,
            self::CROSS_BORDER,
            self::VIRTUAL,
            self::B2B,
        ];
    }

    public static function isValid(string $code): bool
    {
        return in_array(strtolower(trim($code)), self::codes(), true);
    }

    public static function normalize(string $code): string
    {
        $code = strtolower(trim($code));

        return self::isValid($code) ? $code : self::DEFAULT;
    }

    /** @return array<string,string> code => label */
    public static function labels(): array
    {
        return [
            self::RETAIL => '标准零售',
            self::CROSS_BORDER => '跨境',
            self::VIRTUAL => '虚拟商品',
            self::B2B => 'B2B',
        ];
    }

    /** SystemConfig select options string: code:Label,... */
    public static function configOptions(): string
    {
        $parts = [];
        foreach (self::labels() as $code => $label) {
            $parts[] = $code . ':' . $label;
        }

        return implode(',', $parts);
    }
}
