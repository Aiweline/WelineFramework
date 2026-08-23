<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\ThemeData;

final class ThemeDataProjectedTranslationValueTest extends TestCase
{
    public function testStructuredTranslationRoundTripsWithoutLosingType(): void
    {
        $value = [
            'version' => 1,
            'asset_id' => 'asset-id',
            'alt' => '页面语境图片',
            'decorative' => false,
            'widths' => [480, 768, 1280],
        ];

        $encoded = ThemeData::encodeProjectedTranslationValue($value);

        self::assertStringStartsWith(ThemeData::PROJECTED_TRANSLATION_JSON_PREFIX, $encoded);
        self::assertSame($value, ThemeData::decodeProjectedTranslationValue($encoded));
    }

    public function testPlainTranslationRemainsPlainText(): void
    {
        self::assertSame('标题', ThemeData::encodeProjectedTranslationValue('标题'));
        self::assertSame('标题', ThemeData::decodeProjectedTranslationValue('标题'));
    }

    public function testCompatibilityMergeIncludesTypedNonTextParams(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/ThemeData.php');

        self::assertStringContainsString('mergeProjectedStructuredParams', $source);
        self::assertMatchesRegularExpression(
            '/mergeProjectedStructuredParams\([^;]+;\s*if \(\$effectiveLocale ===/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/function mergeTranslatedPaths\(.+?resolveRequestedScopeForArea/s',
            $source,
        );
    }
}
