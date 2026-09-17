<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\ThemeData;
use Weline\Widget\Api\Param\ParamDefinition;

final class ThemeDataImageTranslationMergeContractTest extends TestCase
{
    public function testBlankTranslationDoesNotOverwriteBaseFileImage(): void
    {
        $baseImage = [
            'type' => 'file-image',
            'usage' => [
                'version' => 1,
                'asset_id' => 'base-asset',
                'locale_code' => 'zh_Hans_CN',
            ],
        ];
        $baseConfig = ['image' => $baseImage];
        $paramDefs = [
            'image' => ['type' => 'media_image', 'label' => '图片'],
        ];

        self::assertTrue(ParamDefinition::isTranslatable($paramDefs['image']));
        self::assertTrue(ThemeData::isBlankTranslationValue(''));
        self::assertTrue(ThemeData::isBlankTranslationValue([
            'type' => 'file-image',
            'usage' => ['version' => 1, 'asset_id' => '', 'locale_code' => 'en_US'],
        ]));

        $encoded = ThemeData::encodeTranslationForStorage($baseImage);
        self::assertNotNull($encoded);
        self::assertStringStartsWith(ThemeData::PROJECTED_TRANSLATION_JSON_PREFIX, $encoded);
        self::assertSame($baseImage, ThemeData::decodeProjectedTranslationValue($encoded));

        // Source contract: merge only applies non-blank overlays.
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/ThemeData.php');
        self::assertStringContainsString('isBlankTranslationValue', $source);
        self::assertStringContainsString('encodeTranslationForStorage', $source);
        self::assertSame($baseImage, $baseConfig['image']);
    }

    public function testGetTranslatablePathsIncludesDefaultMediaImage(): void
    {
        $paths = ThemeData::getTranslatablePaths([
            'image' => ['type' => 'media_image'],
            'logo_image' => ['type' => 'image', 'i18n' => false],
            'title' => ['type' => 'string'],
        ]);
        self::assertContains('image', $paths['top']);
        self::assertContains('title', $paths['top']);
        self::assertNotContains('logo_image', $paths['top']);
    }

    public function testEncodeTranslationForStorageSkipsBlank(): void
    {
        self::assertNull(ThemeData::encodeTranslationForStorage(''));
        self::assertNull(ThemeData::encodeTranslationForStorage([]));
        self::assertNull(ThemeData::encodeTranslationForStorage([
            'type' => 'file-image',
            'usage' => ['version' => 1, 'asset_id' => '', 'locale_code' => 'en_US'],
        ]));
    }
}
