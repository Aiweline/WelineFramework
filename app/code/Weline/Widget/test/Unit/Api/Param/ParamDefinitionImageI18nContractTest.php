<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Api\Param;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Api\Param\ParamDefinition;

final class ParamDefinitionImageI18nContractTest extends TestCase
{
    public function testMediaImageUiTypeIsTranslatableByDefault(): void
    {
        self::assertTrue(ParamDefinition::isTranslatable([
            'type' => 'string',
            'ui_type' => 'media_image',
        ]));
        self::assertTrue(ParamDefinition::isTranslatable([
            'type' => 'media_image',
        ]));
        self::assertTrue(ParamDefinition::isTranslatable([
            'type' => 'image',
        ]));
        self::assertTrue(ParamDefinition::isImageUiType([
            'ui_type' => 'media_image',
        ]));
    }

    public function testExplicitI18nFalseOptsOutImageFields(): void
    {
        self::assertFalse(ParamDefinition::isTranslatable([
            'type' => 'media_image',
            'i18n' => false,
        ]));
        self::assertFalse(ParamDefinition::isTranslatable([
            'type' => 'string',
            'ui_type' => 'media_image',
            'i18n' => false,
        ]));
    }

    public function testNonImageNonTextTypesStayLocaleNeutral(): void
    {
        self::assertFalse(ParamDefinition::isTranslatable([
            'type' => 'bool',
        ]));
        self::assertFalse(ParamDefinition::isTranslatable([
            'type' => 'color',
        ]));
        self::assertFalse(ParamDefinition::isTranslatable([
            'type' => 'select',
        ]));
    }
}
