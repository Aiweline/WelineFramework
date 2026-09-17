<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Param;

/** Pure parameter-definition rules shared with consumers without exposing Widget UI classes. */
final class ParamDefinition
{
    /** Text-like UI types that are translatable by default. */
    private const TRANSLATABLE_TEXT_TYPES = ['string', 'textarea', 'html', 'text'];

    /**
     * Image UI types that are translatable by default (per-locale asset overlay).
     * Keep in sync with Theme editor isFieldTranslatable / Meta ParamDefinitionNormalizer.
     */
    private const TRANSLATABLE_IMAGE_TYPES = ['media_image', 'image', 'image_picker', 'file_image'];

    /** @param array<string, mixed> $definition */
    public static function isTranslatable(array $definition): bool
    {
        if (array_key_exists('i18n', $definition)) {
            return (bool)$definition['i18n'];
        }
        if (array_key_exists('translate', $definition)) {
            return (bool)$definition['translate'];
        }
        if (array_key_exists('translatable', $definition)) {
            return (bool)$definition['translatable'];
        }

        return self::isDefaultTranslatableUiType(self::resolveUiType($definition));
    }

    /** @param array<string, mixed> $definition */
    public static function isImageUiType(array $definition): bool
    {
        return in_array(self::resolveUiType($definition), self::TRANSLATABLE_IMAGE_TYPES, true);
    }

    /** @param array<string, mixed> $definition */
    public static function resolveUiType(array $definition): string
    {
        return (string)($definition['ui_type'] ?? $definition['input'] ?? $definition['type'] ?? 'string');
    }

    public static function isDefaultTranslatableUiType(string $uiType): bool
    {
        return in_array($uiType, self::TRANSLATABLE_TEXT_TYPES, true)
            || in_array($uiType, self::TRANSLATABLE_IMAGE_TYPES, true);
    }
}
