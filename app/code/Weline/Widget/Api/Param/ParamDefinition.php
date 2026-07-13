<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Param;

/** Pure parameter-definition rules shared with consumers without exposing Widget UI classes. */
final class ParamDefinition
{
    private const TRANSLATABLE_TYPES = ['string', 'textarea', 'html', 'text'];

    /** @param array<string, mixed> $definition */
    public static function isTranslatable(array $definition): bool
    {
        if (array_key_exists('i18n', $definition)) {
            return (bool)$definition['i18n'];
        }

        return in_array((string)($definition['type'] ?? 'string'), self::TRANSLATABLE_TYPES, true);
    }
}
